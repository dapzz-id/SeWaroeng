<?php

namespace App\Services\Sync;

use App\Domain\Tenant\TenantContext;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PriceNotConfiguredException;
use App\Exceptions\SaleInvalidStateException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\IdempotencyRecord;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\Payment\PaymentService;
use App\Services\PosService;
use App\Services\ReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncService
{
    public function __construct(
        protected PosService $posService,
        protected PaymentService $paymentService,
        protected ReceiptService $receiptService,
    ) {}

    protected function ensureTenantRls(string $tenantId): void
    {
        try {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [$tenantId]);
        } catch (\Throwable) {
            // Ignored if non-pgsql connection or testing mock
        }
    }

    /**
     * Process a batch of sync operations coming from an offline client.
     *
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    public function processBatch(array $batch, string $tenantId, string $userId): array
    {
        $clientId = $batch['client_id'] ?? null;
        $deviceId = $batch['device_id'] ?? null;
        $operations = $batch['operations'] ?? [];
        $incomingCheckpoint = $batch['checkpoint'] ?? null;

        $results = [];

        foreach ($operations as $operation) {
            $results[] = $this->processOperation($operation, $tenantId, $userId, $deviceId);
        }

        $newCheckpoint = (string) Str::uuid7();

        return [
            'client_id' => $clientId,
            'device_id' => $deviceId,
            'checkpoint' => $newCheckpoint,
            'results' => $results,
        ];
    }

    /**
     * Process a single sync operation deterministically.
     *
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    public function processOperation(array $op, string $tenantId, string $userId, ?string $deviceId = null): array
    {
        $operationId = $op['id'] ?? (string) Str::uuid7();
        $commandType = $op['command_type'] ?? '';
        $aggregateType = $op['aggregate_type'] ?? '';
        $aggregateId = $op['aggregate_id'] ?? '';
        $idempotencyKey = $op['idempotency_key'] ?? $operationId;
        $causationId = $op['causation_id'] ?? null;
        $payload = $op['payload'] ?? [];

        // 1. Idempotency Check: Already processed?
        $existingRecord = IdempotencyRecord::where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingRecord && $existingRecord->isCompleted()) {
            $cached = is_string($existingRecord->response_body)
                ? json_decode($existingRecord->response_body, true)
                : $existingRecord->response_body;

            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'already_processed',
                'code' => 'ALREADY_SYNCED',
                'message' => 'Operation already processed.',
                'data' => $cached['data'] ?? null,
            ];
        }

        // Multi-day idempotency defense: Check if aggregate sale already exists and is finalized,
        // even if IdempotencyRecord has expired or been purged (> 24 hours).
        $targetSaleId = ! empty($aggregateId) ? $aggregateId : ($payload['sale_id'] ?? null);
        if (($aggregateType === 'sale' || $commandType === 'sales.finalize') && ! empty($targetSaleId)) {
            $existingSale = Sale::where('tenant_id', $tenantId)->find($targetSaleId);
            if ($existingSale && $existingSale->isFinalized()) {
                $receiptData = null;
                try {
                    $receiptData = $this->receiptService->getReceiptForSale($tenantId, $existingSale->id);
                } catch (\Throwable) {
                    // receipt might not exist
                }

                return [
                    'operation_id' => $operationId,
                    'aggregate_id' => $existingSale->id,
                    'status' => 'already_processed',
                    'code' => 'ALREADY_SYNCED',
                    'message' => 'Sale already finalized (aggregate recognized).',
                    'data' => [
                        'sale' => $existingSale->loadMissing(['items.modifiers', 'customer'])->toArray(),
                        'receipt' => $receiptData,
                    ],
                ];
            }
        }

        // 2. Causation Dependency Ordering Check
        if (! empty($causationId)) {
            $parentRecord = IdempotencyRecord::where('tenant_id', $tenantId)
                ->where('idempotency_key', $causationId)
                ->where('status', IdempotencyRecord::STATUS_COMPLETED)
                ->first();

            if (! $parentRecord) {
                return [
                    'operation_id' => $operationId,
                    'aggregate_id' => $aggregateId,
                    'status' => 'retryable',
                    'code' => 'DEPENDENCY_NOT_READY',
                    'message' => "Causation dependency [{$causationId}] is not yet completed.",
                ];
            }
        }

        // 3. Tenant Isolation Check
        if (! empty($payload['tenant_id']) && $payload['tenant_id'] !== $tenantId) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'rejected',
                'code' => 'TENANT_MISMATCH',
                'message' => 'Payload tenant does not match authenticated tenant.',
            ];
        }

        // 4. Dispatch based on command_type
        return match ($commandType) {
            'sales.finalize', 'sales.create_and_finalize' => $this->handleSaleFinalize(
                $op,
                $tenantId,
                $userId,
                $deviceId,
                $operationId,
                $aggregateId,
                $idempotencyKey,
                $payload
            ),
            'sales.create' => $this->handleSaleCreate(
                $tenantId,
                $userId,
                $operationId,
                $aggregateId,
                $idempotencyKey,
                $payload
            ),
            default => [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'rejected',
                'code' => 'INVALID_OPERATION',
                'message' => "Unsupported command type [{$commandType}].",
            ],
        };
    }

    /**
     * Handle atomic offline sale finalization.
     *
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function handleSaleFinalize(
        array $op,
        string $tenantId,
        string $userId,
        ?string $deviceId,
        string $operationId,
        string $aggregateId,
        string $idempotencyKey,
        array $payload
    ): array {
        $outletId = $payload['outlet_id'] ?? null;
        if (! $outletId) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'rejected',
                'code' => 'INVALID_OPERATION',
                'message' => 'outlet_id is required.',
            ];
        }

        // Verify Outlet Access for User
        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->hasOutletAccess($outletId)) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'blocked',
                'code' => 'OUTLET_ACCESS_DENIED',
                'message' => 'User is not authorized for this outlet.',
            ];
        }

        // Verify Outlet belongs to Tenant
        $outlet = Outlet::where('tenant_id', $tenantId)->find($outletId);
        if (! $outlet) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'rejected',
                'code' => 'ENTITY_NOT_FOUND',
                'message' => 'Outlet not found.',
            ];
        }

        // Shift Validation: Must be open
        $shiftId = $payload['shift_id'] ?? null;
        if ($shiftId) {
            $shift = Shift::where('tenant_id', $tenantId)
                ->where('outlet_id', $outletId)
                ->find($shiftId);

            if (! $shift || $shift->status !== Shift::STATUS_OPEN) {
                return [
                    'operation_id' => $operationId,
                    'aggregate_id' => $aggregateId,
                    'status' => 'blocked',
                    'code' => 'SHIFT_NOT_AVAILABLE',
                    'message' => 'Associated shift is not open or not available.',
                ];
            }
        } else {
            $shift = Shift::where('tenant_id', $tenantId)
                ->where('outlet_id', $outletId)
                ->where('status', Shift::STATUS_OPEN)
                ->first();

            if (! $shift) {
                return [
                    'operation_id' => $operationId,
                    'aggregate_id' => $aggregateId,
                    'status' => 'blocked',
                    'code' => 'SHIFT_NOT_AVAILABLE',
                    'message' => 'No active open shift found for outlet.',
                ];
            }
            $shiftId = $shift->id;
        }

        // Payment Method Offline Restriction
        $paymentData = $payload['payment'] ?? ['method' => 'cash'];
        $paymentMethod = strtolower($paymentData['method'] ?? 'cash');
        if (! in_array($paymentMethod, ['cash', 'manual'])) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'blocked',
                'code' => 'OFFLINE_PAYMENT_METHOD_DISALLOWED',
                'message' => 'Online payment gateways (e.g. Midtrans, Duitku, QRIS) cannot be settled offline.',
            ];
        }

        // Customer Validation
        $customerId = $payload['customer_id'] ?? null;
        if ($customerId) {
            $customer = Customer::where('tenant_id', $tenantId)->find($customerId);
            if (! $customer) {
                return [
                    'operation_id' => $operationId,
                    'aggregate_id' => $aggregateId,
                    'status' => 'rejected',
                    'code' => 'CUSTOMER_NOT_FOUND',
                    'message' => 'Customer not found.',
                ];
            }
        }

        // Offline Price & Variant Integrity Checks
        foreach ($payload['items'] ?? [] as $item) {
            $variantId = $item['product_variant_id'] ?? null;
            if (! $variantId) {
                continue;
            }
            $variant = ProductVariant::where('tenant_id', $tenantId)->find($variantId);
            if (! $variant || $variant->status !== 'active') {
                return [
                    'operation_id' => $operationId,
                    'aggregate_id' => $aggregateId,
                    'status' => 'rejected',
                    'code' => 'VARIANT_INACTIVE',
                    'message' => "Product variant [{$variantId}] is inactive or not found.",
                ];
            }

            $resolvedPrice = $variant->resolvePrice($outlet->id);
            if (! $resolvedPrice) {
                return [
                    'operation_id' => $operationId,
                    'aggregate_id' => $aggregateId,
                    'status' => 'blocked',
                    'code' => 'PRICE_REMOVED',
                    'message' => "No active selling price configured for variant [{$variant->name}].",
                ];
            }

        }

        $saleId = ! empty($aggregateId) ? $aggregateId : ($payload['sale_id'] ?? (string) Str::uuid7());

        try {
            return DB::transaction(function () use (
                $tenantId,
                $userId,
                $deviceId,
                $operationId,
                $idempotencyKey,
                $payload,
                $outlet,
                $shift,
                $customerId,
                $saleId,
                $paymentData,
                $paymentMethod,
                $op
            ) {
                $this->ensureTenantRls($tenantId);

                // 1. Find or create the Sale
                $sale = Sale::where('tenant_id', $tenantId)->find($saleId);
                if (! $sale) {
                    $sale = $this->posService->createSale(
                        tenantId: $tenantId,
                        outletId: $outlet->id,
                        cashierUserId: $userId,
                        items: $payload['items'] ?? [],
                        notes: $payload['notes'] ?? null,
                        customerId: $customerId,
                        id: $saleId,
                    );
                } elseif (! $sale->isOpen()) {
                    throw new SaleInvalidStateException("Sale [{$saleId}] is already in status [{$sale->status}].");
                }

                // 2. Finalize Sale atomically via authoritative PosService
                $finalizedSale = $this->posService->finalizeSale(
                    sale: $sale,
                    options: [
                        'discount_minor' => $payload['discount_minor'] ?? null,
                        'tax_minor' => $payload['tax_minor'] ?? null,
                        'location_id' => $payload['location_id'] ?? null,
                    ],
                    userId: $userId,
                    tenantId: $tenantId,
                );

                // 3. Record and process Payment
                // Server-authoritative rule: The payment amount MUST equal the server-calculated total_minor.
                // Client-submitted amount_minor or total_minor is never trusted over authoritative server calculation.
                $authoritativeAmountMinor = (int) $finalizedSale->total_minor;
                $cashReceivedMinor = (int) ($paymentData['cash_received_minor'] ?? $authoritativeAmountMinor);

                $payment = $this->paymentService->createPayment(
                    sale: $finalizedSale,
                    data: [
                        'amount_minor' => $authoritativeAmountMinor,
                        'method' => $paymentMethod,
                        'provider' => 'manual',
                        'metadata' => [
                            'offline_sync' => true,
                            'device_id' => $deviceId,
                            'operation_id' => $operationId,
                            'client_claimed_total_minor' => $payload['total_minor'] ?? null,
                            'client_claimed_amount_minor' => $paymentData['amount_minor'] ?? null,
                            'cash_received_minor' => $cashReceivedMinor,
                            'cash_change_minor' => max(0, $cashReceivedMinor - $authoritativeAmountMinor),
                        ],
                    ],
                );

                $this->paymentService->processPayment($payment, [
                    'shift_id' => $shift->id,
                    'cash_received_minor' => $cashReceivedMinor,
                ]);

                // 4. Generate authoritative Receipt
                $receiptData = $this->receiptService->getReceiptForSale($tenantId, $finalizedSale->id);

                $responseResult = [
                    'operation_id' => $operationId,
                    'aggregate_id' => $finalizedSale->id,
                    'status' => 'accepted',
                    'code' => 'OK',
                    'message' => 'Sale finalized and payment recorded successfully.',
                    'data' => [
                        'sale' => $finalizedSale->loadMissing(['items.modifiers', 'customer'])->toArray(),
                        'receipt' => $receiptData,
                    ],
                ];

                // 5. Store Idempotency Record
                IdempotencyRecord::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => hash('sha256', json_encode($op)),
                    'status' => IdempotencyRecord::STATUS_COMPLETED,
                    'response_status' => 200,
                    'response_body' => json_encode($responseResult),
                    'expires_at' => now()->addDays(7),
                ]);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'offline_sale_synced',
                    'entity_type' => Sale::class,
                    'entity_id' => $finalizedSale->id,
                    'after' => [
                        'operation_id' => $operationId,
                        'idempotency_key' => $idempotencyKey,
                        'total_minor' => $finalizedSale->total_minor,
                    ],
                    'created_at' => now(),
                ]);

                return $responseResult;
            });
        } catch (InsufficientStockException $e) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'blocked',
                'code' => 'INSUFFICIENT_STOCK',
                'message' => $e->getMessage(),
            ];
        } catch (PriceNotConfiguredException $e) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'blocked',
                'code' => 'PRICE_CHANGED',
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('Offline sync error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'blocked',
                'code' => 'FINALIZATION_FAILED',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle offline open sale creation.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function handleSaleCreate(
        string $tenantId,
        string $userId,
        string $operationId,
        string $aggregateId,
        string $idempotencyKey,
        array $payload
    ): array {
        $outletId = $payload['outlet_id'] ?? null;
        if (! $outletId) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'rejected',
                'code' => 'INVALID_OPERATION',
                'message' => 'outlet_id is required.',
            ];
        }

        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->hasOutletAccess($outletId)) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'blocked',
                'code' => 'OUTLET_ACCESS_DENIED',
                'message' => 'User is not authorized for this outlet.',
            ];
        }

        $saleId = ! empty($aggregateId) ? $aggregateId : ($payload['sale_id'] ?? (string) Str::uuid7());

        try {
            $sale = $this->posService->createSale(
                tenantId: $tenantId,
                outletId: $outletId,
                cashierUserId: $userId,
                items: $payload['items'] ?? [],
                notes: $payload['notes'] ?? null,
                customerId: $payload['customer_id'] ?? null,
                id: $saleId,
            );

            $result = [
                'operation_id' => $operationId,
                'aggregate_id' => $sale->id,
                'status' => 'accepted',
                'code' => 'OK',
                'message' => 'Sale created successfully.',
                'data' => [
                    'sale' => $sale->toArray(),
                ],
            ];

            IdempotencyRecord::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => hash('sha256', json_encode($payload)),
                'status' => IdempotencyRecord::STATUS_COMPLETED,
                'response_status' => 200,
                'response_body' => json_encode($result),
                'expires_at' => now()->addDays(7),
            ]);

            return $result;
        } catch (\Throwable $e) {
            return [
                'operation_id' => $operationId,
                'aggregate_id' => $aggregateId,
                'status' => 'blocked',
                'code' => 'CREATION_FAILED',
                'message' => $e->getMessage(),
            ];
        }
    }
}
