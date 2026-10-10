<?php

namespace App\Services\Loyalty;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\InsufficientLoyaltyPointsException;
use App\Exceptions\LoyaltyAccountInactiveException;
use App\Exceptions\SubscriptionFeatureNotIncludedException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\LoyaltyVoucher;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\Subscription\SubscriptionEntitlementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LoyaltyService
{
    public function __construct(
        protected TenantManager $tenantManager,
        protected SubscriptionEntitlementService $entitlementService,
    ) {}

    /**
     * Assert tenant subscription entitlement for loyalty.
     */
    public function assertLoyaltyEntitled(string|Tenant $tenant): void
    {
        $tenantModel = $tenant instanceof Tenant ? $tenant : Tenant::findOrFail($tenant);

        if (! $this->entitlementService->can($tenantModel, 'loyalty')) {
            throw new SubscriptionFeatureNotIncludedException('Fitur Loyalty Program tidak termasuk dalam paket langganan aktif.');
        }
    }

    /**
     * Get or create loyalty program for tenant.
     */
    public function getOrCreateProgram(string $tenantId, array $data = []): LoyaltyProgram
    {
        $this->assertLoyaltyEntitled($tenantId);

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $data) {
            $program = LoyaltyProgram::where('tenant_id', $tenantId)->first();

            if (! $program) {
                $program = LoyaltyProgram::create([
                    'tenant_id' => $tenantId,
                    'name' => $data['name'] ?? 'Program Loyalitas',
                    'status' => $data['status'] ?? LoyaltyProgram::STATUS_ACTIVE,
                    'points_per_currency' => $data['points_per_currency'] ?? 1,
                    'earn_unit_amount_minor' => $data['earn_unit_amount_minor'] ?? 100000, // Rp 1.000 = 1 pt
                    'minimum_earn_amount_minor' => $data['minimum_earn_amount_minor'] ?? 1000000, // Rp 10.000
                    'redemption_value_minor' => $data['redemption_value_minor'] ?? 10000, // Rp 100 per point
                    'rounding_policy' => $data['rounding_policy'] ?? LoyaltyProgram::ROUNDING_FLOOR,
                    'expiration_days' => $data['expiration_days'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'metadata' => $data['metadata'] ?? null,
                ]);

                // Create default Bronze tier if none exists
                LoyaltyTier::firstOrCreate([
                    'tenant_id' => $tenantId,
                    'loyalty_program_id' => $program->id,
                    'rank' => 1,
                ], [
                    'name' => 'Bronze',
                    'min_points_earned' => 0,
                    'multiplier_bps' => 10000, // 1.0x
                    'is_active' => true,
                ]);
            }

            return $program;
        });
    }

    /**
     * Update loyalty program configuration.
     */
    public function updateProgram(string $tenantId, array $data): LoyaltyProgram
    {
        $this->assertLoyaltyEntitled($tenantId);

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $data) {
            $program = $this->getOrCreateProgram($tenantId);
            $program->update(array_filter([
                'name' => $data['name'] ?? null,
                'status' => $data['status'] ?? null,
                'points_per_currency' => $data['points_per_currency'] ?? null,
                'earn_unit_amount_minor' => $data['earn_unit_amount_minor'] ?? null,
                'minimum_earn_amount_minor' => $data['minimum_earn_amount_minor'] ?? null,
                'redemption_value_minor' => $data['redemption_value_minor'] ?? null,
                'rounding_policy' => $data['rounding_policy'] ?? null,
                'expiration_days' => array_key_exists('expiration_days', $data) ? $data['expiration_days'] : null,
                'is_active' => array_key_exists('is_active', $data) ? $data['is_active'] : null,
                'metadata' => $data['metadata'] ?? null,
            ], fn ($val) => $val !== null));

            return $program->fresh();
        });
    }

    /**
     * List all tiers for the tenant's program.
     */
    public function listTiers(string $tenantId)
    {
        $this->assertLoyaltyEntitled($tenantId);

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId) {
            $program = $this->getOrCreateProgram($tenantId);

            return LoyaltyTier::where('tenant_id', $tenantId)
                ->where('loyalty_program_id', $program->id)
                ->orderBy('rank', 'asc')
                ->get();
        });
    }

    /**
     * Create a new loyalty tier.
     */
    public function createTier(string $tenantId, array $data): LoyaltyTier
    {
        $this->assertLoyaltyEntitled($tenantId);

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $data) {
            $program = $this->getOrCreateProgram($tenantId);

            return LoyaltyTier::create([
                'tenant_id' => $tenantId,
                'loyalty_program_id' => $program->id,
                'name' => $data['name'],
                'rank' => (int) $data['rank'],
                'min_points_earned' => (int) ($data['min_points_earned'] ?? 0),
                'multiplier_bps' => (int) ($data['multiplier_bps'] ?? 10000),
                'perks' => $data['perks'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * Update an existing tier.
     */
    public function updateTier(string $tenantId, string $tierId, array $data): LoyaltyTier
    {
        $this->assertLoyaltyEntitled($tenantId);

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $tierId, $data) {
            $tier = LoyaltyTier::where('tenant_id', $tenantId)->findOrFail($tierId);

            $tier->update(array_filter([
                'name' => $data['name'] ?? null,
                'rank' => isset($data['rank']) ? (int) $data['rank'] : null,
                'min_points_earned' => isset($data['min_points_earned']) ? (int) $data['min_points_earned'] : null,
                'multiplier_bps' => isset($data['multiplier_bps']) ? (int) $data['multiplier_bps'] : null,
                'perks' => $data['perks'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? $data['is_active'] : null,
            ], fn ($val) => $val !== null));

            return $tier->fresh();
        });
    }

    /**
     * Get or create customer loyalty account.
     */
    public function getOrCreateAccount(string $tenantId, string $customerId): CustomerLoyaltyAccount
    {
        $this->assertLoyaltyEntitled($tenantId);

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $customerId) {
            Customer::where('tenant_id', $tenantId)->findOrFail($customerId);
            $program = $this->getOrCreateProgram($tenantId);

            $account = CustomerLoyaltyAccount::where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->first();

            if (! $account) {
                // Find base tier (rank 1)
                $baseTier = LoyaltyTier::where('tenant_id', $tenantId)
                    ->where('loyalty_program_id', $program->id)
                    ->orderBy('rank', 'asc')
                    ->first();

                $account = CustomerLoyaltyAccount::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'loyalty_program_id' => $program->id,
                    'tier_id' => $baseTier?->id,
                    'current_balance' => 0,
                    'lifetime_earned' => 0,
                    'lifetime_redeemed' => 0,
                    'status' => CustomerLoyaltyAccount::STATUS_ACTIVE,
                    'joined_at' => now(),
                ]);
            }

            return $account->load(['tier', 'program']);
        });
    }

    /**
     * Evaluate and update tier progression for an account based on lifetime_earned.
     */
    public function evaluateTierTransition(CustomerLoyaltyAccount $account): ?LoyaltyTier
    {
        $eligibleTier = LoyaltyTier::where('tenant_id', $account->tenant_id)
            ->where('loyalty_program_id', $account->loyalty_program_id)
            ->where('is_active', true)
            ->where('min_points_earned', '<=', $account->lifetime_earned)
            ->orderBy('rank', 'desc')
            ->first();

        if ($eligibleTier && $eligibleTier->id !== $account->tier_id) {
            $previousTierId = $account->tier_id;
            $account->tier_id = $eligibleTier->id;
            $account->save();

            AuditLog::create([
                'tenant_id' => $account->tenant_id,
                'action' => 'loyalty_tier_changed',
                'entity_type' => CustomerLoyaltyAccount::class,
                'entity_id' => $account->id,
                'before' => ['tier_id' => $previousTierId],
                'after' => ['tier_id' => $eligibleTier->id, 'tier_name' => $eligibleTier->name],
                'created_at' => now(),
            ]);

            Log::info('Loyalty tier updated', [
                'tenant_id' => $account->tenant_id,
                'account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'new_tier' => $eligibleTier->name,
            ]);
        }

        return $eligibleTier;
    }

    /**
     * Authoritatively calculate and award loyalty points for a finalized and paid sale.
     * Idempotent by design via database unique constraints on reference.
     */
    public function awardPointsForSale(Sale $sale): ?LoyaltyPointLedger
    {
        $tenantId = $sale->tenant_id;

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $sale) {
            // Entitlement and customer requirement check
            $tenantModel = Tenant::find($tenantId);
            if (! $tenantModel || ! $this->entitlementService->can($tenantModel, 'loyalty')) {
                return null;
            }

            if (! $sale->customer_id || ! $sale->isFinalized() || $sale->getPaidAmountMinor() < $sale->total_minor || $sale->total_minor <= 0) {
                return null;
            }

            $program = LoyaltyProgram::where('tenant_id', $tenantId)->first();

            if (! $program) {
                $program = $this->getOrCreateProgram($tenantId);
            }

            if (! $program->is_active || $program->status !== LoyaltyProgram::STATUS_ACTIVE) {
                return null;
            }

            // Check if already awarded (idempotency check)
            $existingLedger = LoyaltyPointLedger::where('tenant_id', $tenantId)
                ->where('reference_type', Sale::class)
                ->where('reference_id', $sale->id)
                ->where('type', LoyaltyPointLedger::TYPE_PURCHASE)
                ->first();

            if ($existingLedger) {
                return $existingLedger;
            }

            $eligibleAmount = (int) $sale->total_minor;
            if ($eligibleAmount < $program->minimum_earn_amount_minor) {
                return null;
            }

            return DB::transaction(function () use ($tenantId, $sale, $program, $eligibleAmount) {
                // Double-check inside transaction with lock
                $existing = LoyaltyPointLedger::where('tenant_id', $tenantId)
                    ->where('reference_type', Sale::class)
                    ->where('reference_id', $sale->id)
                    ->where('type', LoyaltyPointLedger::TYPE_PURCHASE)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $account = CustomerLoyaltyAccount::where('tenant_id', $tenantId)
                    ->where('customer_id', $sale->customer_id)
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    $account = $this->getOrCreateAccount($tenantId, $sale->customer_id);
                    $account = CustomerLoyaltyAccount::where('id', $account->id)->lockForUpdate()->first();
                }

                if (! $account || ! $account->isActive()) {
                    return null;
                }

                // Calculate base points
                $earnUnits = intdiv($eligibleAmount, max(1, $program->earn_unit_amount_minor));
                $rawPoints = $earnUnits * $program->points_per_currency;

                // Apply tier multiplier if active tier has multiplier
                $multiplierBps = 10000;
                if ($account->tier_id) {
                    $tier = LoyaltyTier::find($account->tier_id);
                    if ($tier && $tier->is_active && $tier->multiplier_bps > 0) {
                        $multiplierBps = $tier->multiplier_bps;
                    }
                }

                $points = (int) floor(($rawPoints * $multiplierBps) / 10000);

                if ($points <= 0) {
                    return null;
                }

                // Update account balances
                $account->current_balance += $points;
                $account->lifetime_earned += $points;
                $account->save();

                try {
                    $ledger = LoyaltyPointLedger::create([
                        'tenant_id' => $tenantId,
                        'loyalty_program_id' => $program->id,
                        'loyalty_account_id' => $account->id,
                        'customer_id' => $sale->customer_id,
                        'type' => LoyaltyPointLedger::TYPE_PURCHASE,
                        'points' => $points,
                        'balance_after' => $account->current_balance,
                        'reference_type' => Sale::class,
                        'reference_id' => $sale->id,
                        'idempotency_key' => "sale:{$sale->id}:earn",
                        'notes' => "Poin transaksi #{$sale->reference_number}",
                        'metadata' => [
                            'sale_id' => $sale->id,
                            'sale_total_minor' => $sale->total_minor,
                            'multiplier_bps' => $multiplierBps,
                        ],
                    ]);
                } catch (QueryException $e) {
                    // Handled concurrent race duplicate gracefully
                    return LoyaltyPointLedger::where('tenant_id', $tenantId)
                        ->where('reference_type', Sale::class)
                        ->where('reference_id', $sale->id)
                        ->where('type', LoyaltyPointLedger::TYPE_PURCHASE)
                        ->first();
                }

                $this->evaluateTierTransition($account);

                Log::info('Loyalty points earned from sale', [
                    'tenant_id' => $tenantId,
                    'customer_id' => $sale->customer_id,
                    'sale_id' => $sale->id,
                    'points' => $points,
                    'balance_after' => $account->current_balance,
                ]);

                return $ledger;
            });
        });
    }

    /**
     * Atomically redeem reward for a customer.
     * Prevents race conditions with row locking.
     */
    public function redeemReward(
        string $tenantId,
        string $customerId,
        string $rewardId,
        ?string $idempotencyKey = null,
    ): LoyaltyVoucher {
        $this->assertLoyaltyEntitled($tenantId);

        return $this->tenantManager->runForTenant($tenantId, function () use (
            $tenantId,
            $customerId,
            $rewardId,
            $idempotencyKey
        ) {
            // Check idempotency if key provided
            if ($idempotencyKey) {
                $existingLedger = LoyaltyPointLedger::where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingLedger) {
                    $existingVoucher = LoyaltyVoucher::where('tenant_id', $tenantId)
                        ->where('reward_id', $rewardId)
                        ->where('customer_id', $customerId)
                        ->latest('created_at')
                        ->first();

                    if ($existingVoucher) {
                        return $existingVoucher;
                    }
                }
            }

            return DB::transaction(function () use ($tenantId, $customerId, $rewardId, $idempotencyKey) {
                $account = CustomerLoyaltyAccount::where('tenant_id', $tenantId)
                    ->where('customer_id', $customerId)
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    throw new InsufficientLoyaltyPointsException('Akun loyalitas pelanggan belum terdaftar.');
                }

                if (! $account->isActive()) {
                    throw new LoyaltyAccountInactiveException;
                }

                $reward = LoyaltyReward::where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($rewardId);

                if (! $reward->isAvailable()) {
                    throw new \InvalidArgumentException('Reward ini tidak sedang aktif atau stok sudah habis.');
                }

                if ($account->current_balance < $reward->points_cost) {
                    throw new InsufficientLoyaltyPointsException(
                        "Poin Anda ({$account->current_balance}) tidak mencukupi untuk reward ini (membutuhkan {$reward->points_cost} poin)."
                    );
                }

                // Deduct reward stock if limited
                if ($reward->stock !== null) {
                    $reward->stock = max(0, $reward->stock - 1);
                    $reward->save();
                }

                // Deduct points
                $account->current_balance -= $reward->points_cost;
                $account->lifetime_redeemed += $reward->points_cost;
                $account->save();

                // Create immutable ledger entry
                $ledger = LoyaltyPointLedger::create([
                    'tenant_id' => $tenantId,
                    'loyalty_program_id' => $reward->loyalty_program_id,
                    'loyalty_account_id' => $account->id,
                    'customer_id' => $customerId,
                    'type' => LoyaltyPointLedger::TYPE_REDEMPTION,
                    'points' => -((int) $reward->points_cost),
                    'balance_after' => $account->current_balance,
                    'reference_type' => LoyaltyReward::class,
                    'reference_id' => $reward->id,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => "Penukaran reward: {$reward->name}",
                    'metadata' => [
                        'reward_id' => $reward->id,
                        'reward_name' => $reward->name,
                        'points_cost' => $reward->points_cost,
                    ],
                ]);

                // Generate voucher
                $voucherCode = 'VCH-'.strtoupper(Str::random(8));
                $voucher = LoyaltyVoucher::create([
                    'tenant_id' => $tenantId,
                    'loyalty_program_id' => $reward->loyalty_program_id,
                    'customer_id' => $customerId,
                    'reward_id' => $reward->id,
                    'code' => $voucherCode,
                    'status' => LoyaltyVoucher::STATUS_ACTIVE,
                    'discount_amount_minor' => $reward->discount_amount_minor,
                    'discount_percent' => $reward->discount_percent,
                    'min_purchase_minor' => $reward->min_purchase_minor,
                    'issued_at' => now(),
                    'expires_at' => now()->addDays($reward->validity_days),
                ]);

                Log::info('Loyalty reward redeemed', [
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'reward_id' => $reward->id,
                    'voucher_code' => $voucher->code,
                    'points_deducted' => $reward->points_cost,
                    'balance_after' => $account->current_balance,
                ]);

                return $voucher;
            });
        });
    }

    /**
     * Server-authoritative voucher discount validation and calculation.
     */
    public function validateAndCalculateVoucherDiscount(
        string $tenantId,
        string $voucherCode,
        int $subtotalMinor,
        ?string $customerId = null,
        ?string $saleId = null
    ): array {
        $this->assertLoyaltyEntitled($tenantId);

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $voucherCode, $subtotalMinor, $customerId, $saleId) {
            $voucher = LoyaltyVoucher::where('tenant_id', $tenantId)
                ->where('code', $voucherCode)
                ->firstOrFail();

            $isAlreadyReservedForThisSale = $saleId && $voucher->status === LoyaltyVoucher::STATUS_RESERVED && $voucher->used_sale_id === $saleId;

            if (! $voucher->isValidForUse() && ! $isAlreadyReservedForThisSale) {
                throw new \InvalidArgumentException('Voucher tidak valid atau sudah kadaluarsa/digunakan.');
            }

            if ($customerId && $voucher->customer_id !== $customerId) {
                throw new \InvalidArgumentException('Voucher ini milik pelanggan lain.');
            }

            if ($subtotalMinor < $voucher->min_purchase_minor) {
                throw new \InvalidArgumentException('Total transaksi belum memenuhi syarat minimum voucher (minimal Rp '.number_format($voucher->min_purchase_minor / 100, 0, ',', '.').').');
            }

            $discountMinor = $voucher->calculateDiscount($subtotalMinor);

            return [
                'voucher' => $voucher,
                'discount_minor' => $discountMinor,
            ];
        });
    }

    /**
     * Consume reserved voucher upon confirmed payment settlement.
     * Idempotent: repeated calls result in exactly-once consumption.
     */
    public function consumeVoucherForSale(Sale $sale): ?LoyaltyVoucher
    {
        $tenantId = $sale->tenant_id;

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $sale) {
            return DB::transaction(function () use ($tenantId, $sale) {
                $voucher = LoyaltyVoucher::where('tenant_id', $tenantId)
                    ->where('used_sale_id', $sale->id)
                    ->lockForUpdate()
                    ->first();

                if (! $voucher) {
                    return null;
                }

                // If already marked used, idempotent return
                if ($voucher->status === LoyaltyVoucher::STATUS_USED) {
                    return $voucher;
                }

                // Transition from reserved (or active) to used
                $voucher->status = LoyaltyVoucher::STATUS_USED;
                $voucher->used_at = now();
                $voucher->save();

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => auth()->id() ?? '00000000-0000-0000-0000-000000000000',
                    'action' => 'loyalty_voucher_consumed',
                    'entity_type' => LoyaltyVoucher::class,
                    'entity_id' => $voucher->id,
                    'after' => [
                        'status' => LoyaltyVoucher::STATUS_USED,
                        'used_sale_id' => $sale->id,
                        'used_at' => $voucher->used_at->toISOString(),
                    ],
                    'created_at' => now(),
                ]);

                return $voucher;
            });
        });
    }

    /**
     * Release voucher reservation when a sale is cancelled or unapplied.
     */
    public function releaseVoucherReservation(Sale $sale): ?LoyaltyVoucher
    {
        $tenantId = $sale->tenant_id;

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $sale) {
            return DB::transaction(function () use ($tenantId, $sale) {
                $voucher = LoyaltyVoucher::where('tenant_id', $tenantId)
                    ->where('used_sale_id', $sale->id)
                    ->where('status', LoyaltyVoucher::STATUS_RESERVED)
                    ->lockForUpdate()
                    ->first();

                if (! $voucher) {
                    return null;
                }

                $voucher->status = LoyaltyVoucher::STATUS_ACTIVE;
                $voucher->used_sale_id = null;
                $voucher->used_at = null;
                $voucher->save();

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => auth()->id() ?? '00000000-0000-0000-0000-000000000000',
                    'action' => 'loyalty_voucher_reservation_released',
                    'entity_type' => LoyaltyVoucher::class,
                    'entity_id' => $voucher->id,
                    'after' => [
                        'status' => LoyaltyVoucher::STATUS_ACTIVE,
                        'released_from_sale_id' => $sale->id,
                    ],
                    'created_at' => now(),
                ]);

                return $voucher;
            });
        });
    }

    /**
     * Mark voucher as used on a finalized sale.
     * Deprecated: Prefer consumeVoucherForSale for lifecycle safety.
     */
    public function markVoucherUsed(string $tenantId, string $voucherId, string $saleId): LoyaltyVoucher
    {
        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $voucherId, $saleId) {
            $voucher = LoyaltyVoucher::where('tenant_id', $tenantId)->findOrFail($voucherId);
            $voucher->status = LoyaltyVoucher::STATUS_USED;
            $voucher->used_at = now();
            $voucher->used_sale_id = $saleId;
            $voucher->save();

            return $voucher;
        });
    }

    /**
     * Manual controlled administrative point adjustment.
     * Generates immutable compensating ledger entry. Never mutates balance directly.
     */
    public function adjustPoints(
        string $tenantId,
        string $customerId,
        int $points,
        string $reason,
        ?string $actorUserId = null,
        ?string $idempotencyKey = null
    ): LoyaltyPointLedger {
        $this->assertLoyaltyEntitled($tenantId);

        if ($points === 0) {
            throw new \InvalidArgumentException('Jumlah poin penyesuaian tidak boleh nol.');
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Alasan penyesuaian poin wajib diisi.');
        }

        return $this->tenantManager->runForTenant($tenantId, function () use (
            $tenantId,
            $customerId,
            $points,
            $reason,
            $actorUserId,
            $idempotencyKey
        ) {
            return DB::transaction(function () use (
                $tenantId,
                $customerId,
                $points,
                $reason,
                $actorUserId,
                $idempotencyKey
            ) {
                $account = CustomerLoyaltyAccount::where('tenant_id', $tenantId)
                    ->where('customer_id', $customerId)
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    $account = $this->getOrCreateAccount($tenantId, $customerId);
                    $account = CustomerLoyaltyAccount::where('id', $account->id)->lockForUpdate()->first();
                }

                if ($points < 0 && ($account->current_balance + $points < 0)) {
                    throw new \InvalidArgumentException("Penyesuaian tidak valid: saldo poin tidak boleh negatif (saldo saat ini: {$account->current_balance}).");
                }

                $account->current_balance += $points;
                if ($points > 0) {
                    $account->lifetime_earned += $points;
                } else {
                    $account->lifetime_redeemed += abs($points);
                }
                $account->save();

                $ledger = LoyaltyPointLedger::create([
                    'tenant_id' => $tenantId,
                    'loyalty_program_id' => $account->loyalty_program_id,
                    'loyalty_account_id' => $account->id,
                    'customer_id' => $customerId,
                    'type' => LoyaltyPointLedger::TYPE_ADJUSTMENT,
                    'points' => $points,
                    'balance_after' => $account->current_balance,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $reason,
                    'metadata' => [
                        'actor_user_id' => $actorUserId,
                        'reason' => $reason,
                    ],
                ]);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actorUserId,
                    'action' => 'loyalty_manual_adjustment',
                    'entity_type' => LoyaltyPointLedger::class,
                    'entity_id' => $ledger->id,
                    'after' => [
                        'points' => $points,
                        'balance_after' => $account->current_balance,
                        'reason' => $reason,
                    ],
                    'created_at' => now(),
                ]);

                $this->evaluateTierTransition($account);

                return $ledger;
            });
        });
    }

    /**
     * Audit and reconcile account cached current_balance against immutable ledger entries.
     * critical invariant: current_balance = SUM(points).
     */
    public function reconcileAccountBalance(string $tenantId, string $accountId): array
    {
        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $accountId) {
            $account = CustomerLoyaltyAccount::where('tenant_id', $tenantId)->findOrFail($accountId);

            $ledgerSum = (int) LoyaltyPointLedger::where('tenant_id', $tenantId)
                ->where('loyalty_account_id', $accountId)
                ->sum('points');

            $cachedBalance = (int) $account->current_balance;
            $isReconciled = ($cachedBalance === $ledgerSum);

            return [
                'account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'cached_balance' => $cachedBalance,
                'ledger_sum' => $ledgerSum,
                'difference' => $cachedBalance - $ledgerSum,
                'is_reconciled' => $isReconciled,
            ];
        });
    }
}
