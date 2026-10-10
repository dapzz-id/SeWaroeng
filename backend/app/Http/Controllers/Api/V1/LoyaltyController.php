<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\LoyaltyVoucher;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyController extends Controller
{
    public function __construct(
        protected LoyaltyService $loyaltyService,
    ) {}

    /**
     * Get or create active loyalty program for current tenant.
     */
    public function showProgram(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $program = $this->loyaltyService->getOrCreateProgram($tenantId);

        return ApiResponse::success([
            'id' => $program->id,
            'name' => $program->name,
            'status' => $program->status,
            'points_per_currency' => $program->points_per_currency,
            'earn_unit_amount_minor' => $program->earn_unit_amount_minor,
            'minimum_earn_amount_minor' => $program->minimum_earn_amount_minor,
            'redemption_value_minor' => $program->redemption_value_minor,
            'rounding_policy' => $program->rounding_policy,
            'expiration_days' => $program->expiration_days,
            'is_active' => $program->is_active,
            'tiers_count' => $program->tiers()->count(),
            'rewards_count' => $program->rewards()->where('is_active', true)->count(),
        ]);
    }

    /**
     * Update loyalty program configuration.
     */
    public function updateProgram(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'points_per_currency' => ['sometimes', 'required', 'integer', 'min:1'],
            'earn_unit_amount_minor' => ['sometimes', 'required', 'integer', 'min:1'],
            'minimum_earn_amount_minor' => ['sometimes', 'required', 'integer', 'min:0'],
            'redemption_value_minor' => ['sometimes', 'required', 'integer', 'min:1'],
            'rounding_policy' => ['sometimes', 'required', 'in:floor,round,ceil'],
            'expiration_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $program = $this->loyaltyService->updateProgram($tenantId, $validated);

        return ApiResponse::success(
            data: $program,
            message: 'Konfigurasi Loyalty Program berhasil diperbarui.'
        );
    }

    /**
     * List all tiers for loyalty program.
     */
    public function listTiers(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $tiers = $this->loyaltyService->listTiers($tenantId);

        return ApiResponse::success(
            data: $tiers->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'rank' => $t->rank,
                'min_points_earned' => $t->min_points_earned,
                'multiplier_bps' => $t->multiplier_bps,
                'multiplier_formatted' => sprintf('%.1fx', $t->multiplier_bps / 10000),
                'perks' => $t->perks,
                'is_active' => $t->is_active,
            ])->all()
        );
    }

    /**
     * Create a new loyalty tier.
     */
    public function storeTier(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'rank' => ['required', 'integer', 'min:1'],
            'min_points_earned' => ['required', 'integer', 'min:0'],
            'multiplier_bps' => ['nullable', 'integer', 'min:10000'],
            'perks' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tier = $this->loyaltyService->createTier($tenantId, $validated);

        return ApiResponse::success(
            data: $tier,
            message: 'Tier loyalitas berhasil ditambahkan.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Update an existing tier.
     */
    public function updateTier(Request $request, string $tierId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'rank' => ['sometimes', 'required', 'integer', 'min:1'],
            'min_points_earned' => ['sometimes', 'required', 'integer', 'min:0'],
            'multiplier_bps' => ['sometimes', 'required', 'integer', 'min:10000'],
            'perks' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $tier = $this->loyaltyService->updateTier($tenantId, $tierId, $validated);

        return ApiResponse::success(
            data: $tier,
            message: 'Tier loyalitas berhasil diperbarui.'
        );
    }

    /**
     * List rewards.
     */
    public function listRewards(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $this->loyaltyService->assertLoyaltyEntitled($tenantId);

        $rewards = LoyaltyReward::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('points_cost', 'asc')
            ->get();

        return ApiResponse::success(
            data: $rewards->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'description' => $r->description,
                'points_cost' => $r->points_cost,
                'reward_type' => $r->reward_type,
                'discount_amount_minor' => $r->discount_amount_minor,
                'discount_percent' => $r->discount_percent,
                'min_purchase_minor' => $r->min_purchase_minor,
                'stock' => $r->stock,
                'validity_days' => $r->validity_days,
                'is_active' => $r->is_active,
            ])->all()
        );
    }

    /**
     * Store new reward.
     */
    public function storeReward(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $this->loyaltyService->assertLoyaltyEntitled($tenantId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'reward_type' => ['required', 'in:discount_fixed,discount_percent,free_item'],
            'discount_amount_minor' => ['nullable', 'integer', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'min_purchase_minor' => ['nullable', 'integer', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $program = $this->loyaltyService->getOrCreateProgram($tenantId);
        $reward = LoyaltyReward::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'loyalty_program_id' => $program->id,
        ]));

        return ApiResponse::success(
            data: $reward,
            message: 'Reward loyalitas berhasil ditambahkan.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Update existing reward.
     */
    public function updateReward(Request $request, string $rewardId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $this->loyaltyService->assertLoyaltyEntitled($tenantId);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'points_cost' => ['sometimes', 'required', 'integer', 'min:1'],
            'reward_type' => ['sometimes', 'required', 'in:discount_fixed,discount_percent,free_item'],
            'discount_amount_minor' => ['nullable', 'integer', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'min_purchase_minor' => ['nullable', 'integer', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'validity_days' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $reward = LoyaltyReward::where('tenant_id', $tenantId)->findOrFail($rewardId);
        $reward->update($validated);

        return ApiResponse::success(
            data: $reward->fresh(),
            message: 'Reward loyalitas berhasil diperbarui.'
        );
    }

    /**
     * Get customer loyalty account overview.
     */
    public function showCustomerAccount(string $customerId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $account = $this->loyaltyService->getOrCreateAccount($tenantId, $customerId);

        // Next tier calculation
        $nextTier = null;
        $pointsToNextTier = 0;
        $allTiers = LoyaltyTier::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('rank', 'asc')
            ->get();

        foreach ($allTiers as $t) {
            if ($t->min_points_earned > $account->lifetime_earned) {
                $nextTier = $t;
                $pointsToNextTier = $t->min_points_earned - $account->lifetime_earned;
                break;
            }
        }

        return ApiResponse::success([
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'current_balance' => $account->current_balance,
            'lifetime_earned' => $account->lifetime_earned,
            'lifetime_redeemed' => $account->lifetime_redeemed,
            'status' => $account->status,
            'joined_at' => $account->joined_at?->toIso8601String(),
            'current_tier' => $account->tier ? [
                'id' => $account->tier->id,
                'name' => $account->tier->name,
                'rank' => $account->tier->rank,
                'multiplier_bps' => $account->tier->multiplier_bps,
            ] : null,
            'next_tier' => $nextTier ? [
                'name' => $nextTier->name,
                'min_points_earned' => $nextTier->min_points_earned,
                'points_needed' => $pointsToNextTier,
            ] : null,
            'active_vouchers_count' => LoyaltyVoucher::where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->where('status', LoyaltyVoucher::STATUS_ACTIVE)
                ->where('expires_at', '>', now())
                ->count(),
        ]);
    }

    /**
     * Get customer immutable point ledger.
     */
    public function listCustomerLedger(Request $request, string $customerId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $this->loyaltyService->assertLoyaltyEntitled($tenantId);

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = LoyaltyPointLedger::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn ($l) => [
                'id' => $l->id,
                'type' => $l->type,
                'points' => $l->points,
                'balance_after' => $l->balance_after,
                'reference_type' => $l->reference_type,
                'reference_id' => $l->reference_id,
                'notes' => $l->notes,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->all()
        );
    }

    /**
     * Manual controlled points adjustment.
     */
    public function adjustCustomerPoints(Request $request, string $customerId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'points' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');
        $ledger = $this->loyaltyService->adjustPoints(
            tenantId: $tenantId,
            customerId: $customerId,
            points: (int) $validated['points'],
            reason: $validated['reason'],
            actorUserId: $request->user()?->id,
            idempotencyKey: $idempotencyKey
        );

        return ApiResponse::success(
            data: [
                'ledger_id' => $ledger->id,
                'points' => $ledger->points,
                'balance_after' => $ledger->balance_after,
                'notes' => $ledger->notes,
                'created_at' => $ledger->created_at?->toIso8601String(),
            ],
            message: 'Penyesuaian poin berhasil dibukukan ke ledger.'
        );
    }

    /**
     * Customer reward redemption.
     */
    public function redeemReward(Request $request, string $customerId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'reward_id' => ['required', 'uuid'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');
        $voucher = $this->loyaltyService->redeemReward(
            tenantId: $tenantId,
            customerId: $customerId,
            rewardId: $validated['reward_id'],
            idempotencyKey: $idempotencyKey
        );

        return ApiResponse::success(
            data: [
                'voucher_id' => $voucher->id,
                'code' => $voucher->code,
                'status' => $voucher->status,
                'discount_amount_minor' => $voucher->discount_amount_minor,
                'discount_percent' => $voucher->discount_percent,
                'min_purchase_minor' => $voucher->min_purchase_minor,
                'expires_at' => $voucher->expires_at?->toIso8601String(),
                'issued_at' => $voucher->issued_at?->toIso8601String(),
            ],
            message: 'Reward berhasil ditukarkan! Voucher diskon telah diterbitkan.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * List customer vouchers.
     */
    public function listCustomerVouchers(Request $request, string $customerId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $this->loyaltyService->assertLoyaltyEntitled($tenantId);

        $status = $request->query('status');
        $query = LoyaltyVoucher::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->with('reward');

        if ($status) {
            $query->where('status', $status);
        }

        $vouchers = $query->orderBy('issued_at', 'desc')->get();

        return ApiResponse::success(
            data: $vouchers->map(fn ($v) => [
                'id' => $v->id,
                'code' => $v->code,
                'status' => $v->status,
                'reward_name' => $v->reward?->name ?? 'Reward Diskon',
                'discount_amount_minor' => $v->discount_amount_minor,
                'discount_percent' => $v->discount_percent,
                'min_purchase_minor' => $v->min_purchase_minor,
                'is_valid' => $v->isValidForUse(),
                'issued_at' => $v->issued_at?->toIso8601String(),
                'expires_at' => $v->expires_at?->toIso8601String(),
                'used_at' => $v->used_at?->toIso8601String(),
            ])->all()
        );
    }

    /**
     * POS Authoritative Voucher Validation & Discount Calculation.
     */
    public function validateVoucher(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'subtotal_minor' => ['required', 'integer', 'min:0'],
            'customer_id' => ['nullable', 'uuid'],
        ]);

        $res = $this->loyaltyService->validateAndCalculateVoucherDiscount(
            tenantId: $tenantId,
            voucherCode: $validated['code'],
            subtotalMinor: (int) $validated['subtotal_minor'],
            customerId: $validated['customer_id'] ?? null
        );

        /** @var LoyaltyVoucher $voucher */
        $voucher = $res['voucher'];

        return ApiResponse::success([
            'voucher_id' => $voucher->id,
            'code' => $voucher->code,
            'reward_name' => $voucher->reward?->name ?? 'Reward Diskon',
            'discount_minor' => $res['discount_minor'],
            'min_purchase_minor' => $voucher->min_purchase_minor,
            'expires_at' => $voucher->expires_at?->toIso8601String(),
        ], message: 'Voucher valid.');
    }

    /**
     * Audit balance reconciliation check.
     */
    public function reconcileAccount(string $accountId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $res = $this->loyaltyService->reconcileAccountBalance($tenantId, $accountId);

        return ApiResponse::success(
            data: $res,
            message: $res['is_reconciled'] ? 'Saldo terverifikasi selaras dengan ledger.' : 'PERINGATAN: Ditemukan selisih saldo cache dengan akumulasi ledger.'
        );
    }
}
