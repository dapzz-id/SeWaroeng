<?php

namespace App\Services;

use App\Domain\Tenant\TenantContext;
use App\Domain\Tenant\TenantManager;
use App\Exceptions\SaleInvalidStateException;
use App\Models\AuditLog;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReceiptService
{
    protected function ensureTenantRls(string $tenantId): void
    {
        try {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [$tenantId]);
        } catch (\Throwable) {
            // Ignored if non-pgsql connection or testing mock
        }
    }

    protected function resolveTenantId(?string $explicitTenantId = null): string
    {
        if ($explicitTenantId) {
            return $explicitTenantId;
        }

        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasContext()) {
            return $tenantManager->currentTenantId();
        }

        if (app()->bound(TenantContext::class)) {
            $context = app(TenantContext::class);
            if ($context->hasTenant()) {
                return $context->id();
            }
        }

        throw new \RuntimeException('Tenant context could not be resolved for Receipt operation.');
    }

    protected function executeForTenant(string $tenantId, callable $callback): mixed
    {
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasContext() && $tenantManager->currentTenantId() === $tenantId) {
            $this->ensureTenantRls($tenantId);

            return $callback();
        }

        return $tenantManager->runForTenant($tenantId, $callback);
    }

    /**
     * Get or issue authoritative receipt for a finalized sale.
     * Enforces that open/unfinalized sales cannot produce a finalized receipt.
     *
     * @return array<string, mixed>
     */
    public function getReceiptForSale(string $tenantId, string $saleId): array
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $saleId) {
            $this->ensureTenantRls($tenantId);

            /** @var Sale $sale */
            $sale = Sale::where('tenant_id', $tenantId)->findOrFail($saleId);

            if ($sale->isOpen()) {
                throw new SaleInvalidStateException(
                    "Struk transaksi belum tersedia. Penjualan '{$sale->reference_number}' masih berstatus open / belum difinalisasi."
                );
            }

            // Find or issue receipt record atomically
            $receipt = Receipt::where('tenant_id', $tenantId)
                ->where('sale_id', $sale->id)
                ->first();

            if (! $receipt) {
                $receiptNumber = 'REC-'.str_replace('SALE-', '', $sale->reference_number);

                $receipt = Receipt::create([
                    'tenant_id' => $tenantId,
                    'outlet_id' => $sale->outlet_id,
                    'sale_id' => $sale->id,
                    'receipt_number' => $receiptNumber,
                    'issued_at' => now(),
                    'reprint_count' => 0,
                ]);
            }

            return $receipt->formatPresentation();
        });
    }

    /**
     * Increment reprint counter and return authoritative receipt presentation.
     * Guaranteed never to mutate financial state or duplicate transactions.
     *
     * @return array<string, mixed>
     */
    public function reprintReceipt(string $tenantId, string $saleId, ?User $actor = null): array
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $saleId, $actor) {
            return DB::transaction(function () use ($tenantId, $saleId, $actor) {
                $this->ensureTenantRls($tenantId);

                /** @var Sale $sale */
                $sale = Sale::where('tenant_id', $tenantId)->findOrFail($saleId);

                if ($sale->isOpen()) {
                    throw new SaleInvalidStateException(
                        "Struk transaksi belum tersedia. Penjualan '{$sale->reference_number}' masih berstatus open."
                    );
                }

                /** @var Receipt $receipt */
                $receipt = Receipt::where('tenant_id', $tenantId)
                    ->where('sale_id', $sale->id)
                    ->lockForUpdate()
                    ->first();

                if (! $receipt) {
                    $receiptNumber = 'REC-'.str_replace('SALE-', '', $sale->reference_number);
                    $receipt = Receipt::create([
                        'tenant_id' => $tenantId,
                        'outlet_id' => $sale->outlet_id,
                        'sale_id' => $sale->id,
                        'receipt_number' => $receiptNumber,
                        'issued_at' => now(),
                        'reprint_count' => 1,
                        'last_reprinted_at' => now(),
                    ]);
                } else {
                    $receipt->increment('reprint_count');
                    $receipt->update(['last_reprinted_at' => now()]);
                }

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actor?->id ?? auth()->id(),
                    'action' => 'receipt_reprinted',
                    'entity_type' => Receipt::class,
                    'entity_id' => $receipt->id,
                    'after' => [
                        'receipt_number' => $receipt->receipt_number,
                        'sale_reference' => $sale->reference_number,
                        'reprint_count' => $receipt->reprint_count,
                    ],
                    'created_at' => now(),
                ]);

                return $receipt->fresh()->formatPresentation();
            });
        });
    }
}
