<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Customer extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const SEGMENT_PROSPECT = 'prospect';

    public const SEGMENT_NEW = 'new';

    public const SEGMENT_RETURNING = 'returning';

    public const SEGMENT_HIGH_VALUE = 'high_value';

    public const SEGMENT_INACTIVE = 'inactive';

    public const HIGH_VALUE_THRESHOLD_MINOR = 100000000; // Rp 1.000.000 in minor units

    public const INACTIVE_DAYS_THRESHOLD = 30;

    protected $table = 'customers';

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'notes',
        'is_active',
        'marketing_opt_in',
        'whatsapp_opt_in',
        'whatsapp_opted_out',
        'whatsapp_opted_out_at',
        'whatsapp_consent_history',
    ];

    protected $attributes = [
        'is_active' => true,
        'marketing_opt_in' => false,
        'whatsapp_opt_in' => false,
        'whatsapp_opted_out' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'marketing_opt_in' => 'boolean',
            'whatsapp_opt_in' => 'boolean',
            'whatsapp_opted_out' => 'boolean',
            'whatsapp_opted_out_at' => 'datetime',
            'whatsapp_consent_history' => 'array',
        ];
    }

    /**
     * Check if customer is eligible to receive WhatsApp messages in the given category.
     * Enforces Meta WhatsApp Business Messaging Policy & SeWaroeng consent invariants:
     * 1. Customer must be active.
     * 2. Recipient must NOT have opted out.
     * 3. Valid E.164 phone number required.
     * 4. Multi-tenant isolation: Tenant A cannot use consent from Tenant B.
     * 5. Positive consent required: mere presence of phone does NOT imply consent.
     * 6. Marketing requires explicit marketing opt-in; transactional requires positive transactional opt-in.
     */
    public function isEligibleForWhatsApp(string $category = 'transactional', ?string $forTenantId = null): bool
    {
        if ($this->is_active === false) {
            return false;
        }

        // Opt-out strictly revokes communications
        if ($this->whatsapp_opted_out) {
            return false;
        }

        // Phone number must be present and valid
        if (empty($this->phone) || ! PhoneNormalizer::isValid(PhoneNormalizer::normalize($this->phone))) {
            return false;
        }

        // Multi-tenant isolation: Consent granted to Tenant A cannot be used by Tenant B
        if ($forTenantId !== null && (string) $this->tenant_id !== (string) $forTenantId) {
            return false;
        }

        // Positive consent is mandatory per Meta Business Policy
        if (! $this->whatsapp_opt_in) {
            return false;
        }

        // Marketing category requires explicit marketing opt-in
        if ($category === 'marketing' || $category === 'marketing_promo' || $category === WhatsAppMessage::TYPE_MARKETING_PROMO) {
            return (bool) $this->marketing_opt_in;
        }

        // Transactional category (utility receipts, order updates) requires positive opt-in
        return true;
    }

    /**
     * Opt-out customer from WhatsApp communications.
     * Preserves auditable consent history without mutating historical receipts.
     */
    public function optOutWhatsApp(string $source = 'customer_request', ?string $reason = null, ?string $tenantId = null): void
    {
        $history = $this->whatsapp_consent_history ?? [];
        $history[] = [
            'tenant_id' => $tenantId ?? $this->tenant_id,
            'event' => 'opt_out',
            'category' => 'all',
            'source' => $source,
            'reason' => $reason,
            'granted' => false,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->update([
            'whatsapp_opt_in' => false,
            'whatsapp_opted_out' => true,
            'whatsapp_opted_out_at' => now(),
            'marketing_opt_in' => false,
            'whatsapp_consent_history' => $history,
        ]);
    }

    /**
     * Record auditable consent update.
     * Stores tenant, category, source, timestamp, notes, and granted status.
     */
    public function recordWhatsAppConsent(
        string $category,
        bool $optIn = true,
        string $source = 'pos_checkout',
        ?string $notes = null,
        ?bool $granted = null,
        ?string $tenantId = null
    ): void {
        $grantedBool = $granted ?? $optIn;
        $history = $this->whatsapp_consent_history ?? [];
        $effectiveTenantId = $tenantId ?? $this->tenant_id;

        $history[] = [
            'tenant_id' => $effectiveTenantId,
            'event' => $grantedBool ? 'opt_in' : 'opt_out',
            'category' => $category,
            'source' => $source,
            'notes' => $notes,
            'granted' => $grantedBool,
            'timestamp' => now()->toIso8601String(),
        ];

        $updates = ['whatsapp_consent_history' => $history];

        if ($grantedBool) {
            $updates['whatsapp_opted_out'] = false;
            $updates['whatsapp_opted_out_at'] = null;

            if ($category === 'marketing' || $category === 'marketing_promo') {
                $updates['marketing_opt_in'] = true;
                $updates['whatsapp_opt_in'] = true;
            } elseif ($category === 'transactional' || $category === 'transactional_receipt' || $category === 'all') {
                $updates['whatsapp_opt_in'] = true;
            }
        } else {
            // Opt-out
            if ($category === 'marketing' || $category === 'marketing_promo') {
                $updates['marketing_opt_in'] = false;
            } elseif ($category === 'all' || $category === 'transactional' || $category === 'transactional_receipt') {
                $updates['whatsapp_opt_in'] = false;
                $updates['whatsapp_opted_out'] = true;
                $updates['whatsapp_opted_out_at'] = now();
                $updates['marketing_opt_in'] = false;
            }
        }

        $this->update($updates);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'customer_id');
    }

    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(CustomerLoyaltyAccount::class, 'customer_id');
    }

    public function loyaltyLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointLedger::class, 'customer_id');
    }

    public function loyaltyVouchers(): HasMany
    {
        return $this->hasMany(LoyaltyVoucher::class, 'customer_id');
    }

    /**
     * Compute authoritative CRM summary and segmentation metrics.
     *
     * @return array<string, mixed>
     */
    public function calculateSummary(): array
    {
        $salesQuery = $this->sales()
            ->where('tenant_id', $this->tenant_id);

        $totalTransactions = (int) (clone $salesQuery)->count();

        // Successful transactions: finalized sales with successful or partially refunded payments
        $successfulSales = (clone $salesQuery)
            ->where('status', Sale::STATUS_FINALIZED)
            ->whereHas('payments', function ($q) {
                $q->whereIn('status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED]);
            })
            ->get(['id', 'total_minor', 'finalized_at']);

        $successfulCount = $successfulSales->count();
        $totalSpendMinor = (int) $successfulSales->sum('total_minor');
        $averageOrderValueMinor = $successfulCount > 0 ? (int) round($totalSpendMinor / $successfulCount) : 0;

        /** @var Carbon|null $firstPurchaseAt */
        $firstPurchaseAt = $successfulSales->min('finalized_at');
        /** @var Carbon|null $lastPurchaseAt */
        $lastPurchaseAt = $successfulSales->max('finalized_at');

        // Deterministic segmentation rule
        $segment = self::determineSegment($successfulCount, $totalSpendMinor, $lastPurchaseAt);

        $loyaltyAccount = $this->loyaltyAccount()->with('tier')->first();

        return [
            'customer_id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'total_transactions' => $totalTransactions,
            'successful_transactions' => $successfulCount,
            'total_spend_minor' => $totalSpendMinor,
            'average_order_value_minor' => $averageOrderValueMinor,
            'first_purchase_at' => $firstPurchaseAt?->toIso8601String(),
            'last_purchase_at' => $lastPurchaseAt?->toIso8601String(),
            'segment' => $segment,
            'segment_label' => self::getSegmentLabel($segment),
            'loyalty' => [
                'has_account' => $loyaltyAccount !== null,
                'tier' => $loyaltyAccount?->tier?->name,
                'current_points' => $loyaltyAccount?->current_balance ?? 0,
                'lifetime_earned' => $loyaltyAccount?->lifetime_earned ?? 0,
                'lifetime_redeemed' => $loyaltyAccount?->lifetime_redeemed ?? 0,
                'active_vouchers_count' => $this->loyaltyVouchers()->where('status', LoyaltyVoucher::STATUS_ACTIVE)->where('expires_at', '>', now())->count(),
            ],
        ];
    }

    /**
     * Determine segment using deterministic business rules.
     */
    public static function determineSegment(
        int $successfulCount,
        int $totalSpendMinor,
        ?Carbon $lastPurchaseAt
    ): string {
        if ($successfulCount === 0) {
            return self::SEGMENT_PROSPECT;
        }

        // Inactive if last purchase is older than threshold
        if ($lastPurchaseAt !== null && $lastPurchaseAt->lt(now()->subDays(self::INACTIVE_DAYS_THRESHOLD))) {
            return self::SEGMENT_INACTIVE;
        }

        // High value threshold check
        if ($totalSpendMinor >= self::HIGH_VALUE_THRESHOLD_MINOR) {
            return self::SEGMENT_HIGH_VALUE;
        }

        // Returning customer check
        if ($successfulCount >= 2) {
            return self::SEGMENT_RETURNING;
        }

        // Exactly 1 purchase
        return self::SEGMENT_NEW;
    }

    public static function getSegmentLabel(string $segment): string
    {
        return match ($segment) {
            self::SEGMENT_PROSPECT => 'Prospek',
            self::SEGMENT_NEW => 'Pelanggan Baru',
            self::SEGMENT_RETURNING => 'Pelanggan Setia',
            self::SEGMENT_HIGH_VALUE => 'Pelanggan Prioritas',
            self::SEGMENT_INACTIVE => 'Tidak Aktif',
            default => 'Umum',
        };
    }
}
