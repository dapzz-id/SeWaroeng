<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'receipts';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'sale_id',
        'receipt_number',
        'issued_at',
        'reprint_count',
        'last_reprinted_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'last_reprinted_at' => 'datetime',
            'reprint_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    /**
     * Build an immutable presentation document snapshot for cashier/customer receipt display.
     * Derived purely from authoritative models. Never exposes sensitive gateway credentials.
     *
     * @return array<string, mixed>
     */
    public function formatPresentation(): array
    {
        /** @var Sale $sale */
        $sale = $this->sale()->with([
            'items.modifiers',
            'payments.refunds',
            'cashier',
            'customer',
            'outlet',
        ])->firstOrFail();

        $outlet = $sale->outlet;
        $tenant = $sale->tenant;
        $customer = $sale->customer;
        $cashier = $sale->cashier;

        $items = $sale->items->map(function (SaleItem $item) {
            return [
                'id' => $item->id,
                'product_name' => $item->product_name_snapshot,
                'variant_name' => $item->variant_name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price_minor' => (int) $item->unit_price_minor,
                'subtotal_minor' => (int) $item->subtotal_minor,
                'discount_minor' => (int) $item->discount_minor,
                'line_total_minor' => (int) $item->total_minor,
                'modifiers' => $item->modifiers->map(fn ($m) => [
                    'name' => $m->modifier_name_snapshot,
                    'quantity' => (float) $m->quantity,
                    'price_minor' => (int) $m->unit_price_minor,
                    'total_minor' => (int) $m->subtotal_minor,
                ])->all(),
            ];
        })->all();

        $payments = $sale->payments->map(function (Payment $p) {
            return [
                'id' => $p->id,
                'reference_number' => $p->reference_number,
                'method' => $p->method,
                'method_label' => strtoupper($p->method),
                'amount_minor' => (int) $p->amount_minor,
                'status' => $p->status,
                'succeeded_at' => $p->succeeded_at?->toIso8601String(),
                'provider' => $p->provider,
            ];
        })->all();

        $paidMinor = $sale->getPaidAmountMinor();
        $refundedMinor = $sale->getRefundedAmountMinor();
        $netPaidMinor = $paidMinor - $refundedMinor;
        $changeMinor = max(0, $paidMinor - (int) $sale->total_minor);

        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference_number,
            'status' => $sale->status,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'transaction_time' => ($sale->finalized_at ?? $sale->opened_at)?->toIso8601String(),
            'reprint_count' => $this->reprint_count,
            'last_reprinted_at' => $this->last_reprinted_at?->toIso8601String(),
            'business' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'outlet' => [
                'id' => $outlet->id,
                'name' => $outlet->name,
                'address' => $outlet->address_line ?? null,
                'phone' => $outlet->phone_number ?? null,
            ],
            'cashier' => [
                'id' => $cashier?->id,
                'name' => $cashier?->name ?? 'Kasir',
            ],
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ] : null,
            'items' => $items,
            'subtotal_minor' => (int) $sale->subtotal_minor,
            'discount_minor' => (int) $sale->discount_minor,
            'tax_minor' => (int) $sale->tax_minor,
            'total_minor' => (int) $sale->total_minor,
            'payments' => $payments,
            'paid_amount_minor' => $paidMinor,
            'refunded_amount_minor' => $refundedMinor,
            'net_paid_minor' => $netPaidMinor,
            'change_amount_minor' => $changeMinor,
            'is_fully_paid' => $sale->getOutstandingAmountMinor() === 0,
        ];
    }
}
