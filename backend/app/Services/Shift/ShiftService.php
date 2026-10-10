<?php

namespace App\Services\Shift;

use App\Events\CashMovementCreated;
use App\Events\ShiftClosed;
use App\Events\ShiftOpened;
use App\Exceptions\ActiveShiftAlreadyExistsException;
use App\Exceptions\InsufficientDrawerCashException;
use App\Exceptions\ShiftClosedException;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ShiftService
{
    /**
     * Open a new shift for the given outlet.
     */
    public function openShift(Outlet $outlet, int $openingCashMinor, User $user, ?string $notes = null): Shift
    {
        if ($openingCashMinor < 0) {
            throw new InvalidArgumentException('Opening cash cannot be negative.');
        }

        return DB::transaction(function () use ($outlet, $openingCashMinor, $user, $notes) {
            // Lock active shift check for this outlet
            $existing = Shift::where('outlet_id', $outlet->id)
                ->where('status', Shift::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new ActiveShiftAlreadyExistsException("An active shift is already open for outlet [{$outlet->name}].");
            }

            try {
                $shift = Shift::create([
                    'tenant_id' => $outlet->tenant_id,
                    'outlet_id' => $outlet->id,
                    'opened_by_user_id' => $user->id,
                    'status' => Shift::STATUS_OPEN,
                    'opened_at' => Carbon::now(),
                    'opening_cash_minor' => $openingCashMinor,
                    'notes' => $notes,
                ]);
            } catch (QueryException $e) {
                // Catch PostgreSQL unique constraint violation on uq_shifts_active_outlet
                if (str_contains($e->getMessage(), 'uq_shifts_active_outlet') || $e->getCode() === '23505') {
                    throw new ActiveShiftAlreadyExistsException("An active shift is already open for outlet [{$outlet->name}].");
                }
                throw $e;
            }

            // Create initial opening cash movement if opening cash > 0
            if ($openingCashMinor > 0) {
                CashMovement::create([
                    'tenant_id' => $outlet->tenant_id,
                    'outlet_id' => $outlet->id,
                    'shift_id' => $shift->id,
                    'type' => CashMovement::TYPE_OPENING,
                    'direction' => CashMovement::DIRECTION_IN,
                    'amount_minor' => $openingCashMinor,
                    'reference_type' => 'shift_opening',
                    'reference_id' => $shift->id,
                    'reason' => 'Opening Cash Float',
                    'performed_by_user_id' => $user->id,
                    'metadata' => [
                        'notes' => $notes,
                    ],
                ]);
            }

            AuditLog::create([
                'tenant_id' => $outlet->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'shift_opened',
                'entity_type' => 'shift',
                'entity_id' => $shift->id,
                'after' => [
                    'outlet_id' => $outlet->id,
                    'opening_cash_minor' => $openingCashMinor,
                    'opened_at' => $shift->opened_at->toIso8601String(),
                ],
                'created_at' => Carbon::now(),
            ]);

            event(new ShiftOpened($shift));

            return $shift;
        });
    }

    /**
     * Get the active shift for the given outlet.
     */
    public function getCurrentShift(Outlet $outlet): ?Shift
    {
        return Shift::where('outlet_id', $outlet->id)
            ->where('status', Shift::STATUS_OPEN)
            ->with(['openedBy', 'movements.performedBy'])
            ->first();
    }

    /**
     * Calculate authoritative cash summary for a shift.
     * Expected Cash = opening_cash + cash_sales + cash_in - cash_out - cash_refund +/- adjustments
     */
    public function getCashSummary(Shift $shift): array
    {
        $movements = CashMovement::where('shift_id', $shift->id)->get();

        $openingCash = $shift->opening_cash_minor;
        $cashSales = 0;
        $cashIn = 0;
        $cashOut = 0;
        $cashRefund = 0;
        $adjustments = 0;

        foreach ($movements as $m) {
            switch ($m->type) {
                case CashMovement::TYPE_SALE:
                    $cashSales += $m->amount_minor;
                    break;
                case CashMovement::TYPE_CASH_IN:
                    $cashIn += $m->amount_minor;
                    break;
                case CashMovement::TYPE_CASH_OUT:
                    $cashOut += $m->amount_minor;
                    break;
                case CashMovement::TYPE_REFUND:
                    $cashRefund += $m->amount_minor;
                    break;
                case CashMovement::TYPE_ADJUSTMENT:
                    if ($m->direction === CashMovement::DIRECTION_IN) {
                        $adjustments += $m->amount_minor;
                    } else {
                        $adjustments -= $m->amount_minor;
                    }
                    break;
            }
        }

        $expectedClosing = $openingCash + $cashSales + $cashIn - $cashOut - $cashRefund + $adjustments;

        return [
            'shift_id' => $shift->id,
            'status' => $shift->status,
            'opening_cash' => $openingCash,
            'cash_sales' => $cashSales,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'cash_refund' => $cashRefund,
            'adjustments' => $adjustments,
            'expected_closing' => $expectedClosing,
            'actual_closing' => $shift->closing_cash_minor,
            'variance' => $shift->variance_minor,
        ];
    }

    /**
     * Record a manual cash in.
     */
    public function cashIn(Shift $shift, int $amountMinor, string $reason, User $user): CashMovement
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Cash in amount must be greater than zero.');
        }

        return DB::transaction(function () use ($shift, $amountMinor, $reason, $user) {
            $lockedShift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if (! $lockedShift->isOpen()) {
                throw new ShiftClosedException('Cannot perform cash in on a closed shift.');
            }

            $movement = CashMovement::create([
                'tenant_id' => $lockedShift->tenant_id,
                'outlet_id' => $lockedShift->outlet_id,
                'shift_id' => $lockedShift->id,
                'type' => CashMovement::TYPE_CASH_IN,
                'direction' => CashMovement::DIRECTION_IN,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'performed_by_user_id' => $user->id,
            ]);

            AuditLog::create([
                'tenant_id' => $lockedShift->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'cash_in_created',
                'entity_type' => 'cash_movement',
                'entity_id' => $movement->id,
                'after' => [
                    'shift_id' => $lockedShift->id,
                    'outlet_id' => $lockedShift->outlet_id,
                    'amount_minor' => $amountMinor,
                    'reason' => $reason,
                ],
                'created_at' => Carbon::now(),
            ]);

            event(new CashMovementCreated($movement));

            return $movement;
        });
    }

    /**
     * Record a manual cash out.
     */
    public function cashOut(Shift $shift, int $amountMinor, string $reason, User $user): CashMovement
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Cash out amount must be greater than zero.');
        }

        return DB::transaction(function () use ($shift, $amountMinor, $reason, $user) {
            $lockedShift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if (! $lockedShift->isOpen()) {
                throw new ShiftClosedException('Cannot perform cash out on a closed shift.');
            }

            $summary = $this->getCashSummary($lockedShift);
            if ($summary['expected_closing'] - $amountMinor < 0) {
                throw new InsufficientDrawerCashException(
                    "Cash out of [{$amountMinor}] exceeds current drawer cash [{$summary['expected_closing']}]."
                );
            }

            $movement = CashMovement::create([
                'tenant_id' => $lockedShift->tenant_id,
                'outlet_id' => $lockedShift->outlet_id,
                'shift_id' => $lockedShift->id,
                'type' => CashMovement::TYPE_CASH_OUT,
                'direction' => CashMovement::DIRECTION_OUT,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'performed_by_user_id' => $user->id,
            ]);

            AuditLog::create([
                'tenant_id' => $lockedShift->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'cash_out_created',
                'entity_type' => 'cash_movement',
                'entity_id' => $movement->id,
                'after' => [
                    'shift_id' => $lockedShift->id,
                    'outlet_id' => $lockedShift->outlet_id,
                    'amount_minor' => $amountMinor,
                    'reason' => $reason,
                ],
                'created_at' => Carbon::now(),
            ]);

            event(new CashMovementCreated($movement));

            return $movement;
        });
    }

    /**
     * Record a cash adjustment (e.g. audit discrepancy or correction).
     */
    public function cashAdjust(Shift $shift, int $amountMinor, string $direction, string $reason, User $user): CashMovement
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Cash adjustment amount must be greater than zero.');
        }

        if (! in_array($direction, [CashMovement::DIRECTION_IN, CashMovement::DIRECTION_OUT], true)) {
            throw new InvalidArgumentException("Invalid adjustment direction [{$direction}].");
        }

        return DB::transaction(function () use ($shift, $amountMinor, $direction, $reason, $user) {
            $lockedShift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if (! $lockedShift->isOpen()) {
                throw new ShiftClosedException('Cannot perform cash adjustment on a closed shift.');
            }

            if ($direction === CashMovement::DIRECTION_OUT) {
                $summary = $this->getCashSummary($lockedShift);
                if ($summary['expected_closing'] - $amountMinor < 0) {
                    throw new InsufficientDrawerCashException(
                        "Cash adjustment out of [{$amountMinor}] exceeds current drawer cash [{$summary['expected_closing']}]."
                    );
                }
            }

            $movement = CashMovement::create([
                'tenant_id' => $lockedShift->tenant_id,
                'outlet_id' => $lockedShift->outlet_id,
                'shift_id' => $lockedShift->id,
                'type' => CashMovement::TYPE_ADJUSTMENT,
                'direction' => $direction,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'performed_by_user_id' => $user->id,
            ]);

            AuditLog::create([
                'tenant_id' => $lockedShift->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'cash_adjustment_created',
                'entity_type' => 'cash_movement',
                'entity_id' => $movement->id,
                'after' => [
                    'shift_id' => $lockedShift->id,
                    'outlet_id' => $lockedShift->outlet_id,
                    'amount_minor' => $amountMinor,
                    'direction' => $direction,
                    'reason' => $reason,
                ],
                'created_at' => Carbon::now(),
            ]);

            event(new CashMovementCreated($movement));

            return $movement;
        });
    }

    /**
     * Atomically record cash impact for a successful cash payment.
     */
    public function recordSaleCashPayment(Payment $payment): ?CashMovement
    {
        if ($payment->method !== 'cash' || $payment->status !== Payment::STATUS_SUCCEEDED) {
            return null;
        }

        $sale = $payment->sale;
        if (! $sale || ! $sale->outlet_id) {
            return null;
        }

        // Check if an active shift exists for this outlet
        $activeShift = Shift::where('outlet_id', $sale->outlet_id)
            ->where('status', Shift::STATUS_OPEN)
            ->first();

        if (! $activeShift) {
            return null;
        }

        // Replay / idempotency check via unique index uq_cash_movements_reference
        $existing = CashMovement::where('shift_id', $activeShift->id)
            ->where('reference_type', 'payment')
            ->where('reference_id', $payment->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $movement = CashMovement::create([
            'tenant_id' => $activeShift->tenant_id,
            'outlet_id' => $activeShift->outlet_id,
            'shift_id' => $activeShift->id,
            'type' => CashMovement::TYPE_SALE,
            'direction' => CashMovement::DIRECTION_IN,
            'amount_minor' => $payment->amount_minor,
            'reference_type' => 'payment',
            'reference_id' => $payment->id,
            'reason' => "Cash Sale payment [{$payment->payment_reference}]",
            'performed_by_user_id' => $payment->cashier_user_id ?? $sale->cashier_id ?? $activeShift->opened_by_user_id,
            'metadata' => [
                'sale_id' => $sale->id,
                'payment_id' => $payment->id,
            ],
        ]);

        event(new CashMovementCreated($movement));

        return $movement;
    }

    /**
     * Atomically record cash impact for a completed cash refund.
     */
    public function recordRefundCashPayment(PaymentRefund $refund): ?CashMovement
    {
        if ($refund->status !== PaymentRefund::STATUS_COMPLETED) {
            return null;
        }

        $payment = $refund->payment;
        if (! $payment || $payment->method !== 'cash') {
            return null;
        }

        $sale = $payment->sale;
        if (! $sale || ! $sale->outlet_id) {
            return null;
        }

        // Active shift check for this outlet
        $activeShift = Shift::where('outlet_id', $sale->outlet_id)
            ->where('status', Shift::STATUS_OPEN)
            ->first();

        if (! $activeShift) {
            return null;
        }

        // Deduplication check
        $existing = CashMovement::where('shift_id', $activeShift->id)
            ->where('reference_type', 'payment_refund')
            ->where('reference_id', $refund->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $movement = CashMovement::create([
            'tenant_id' => $activeShift->tenant_id,
            'outlet_id' => $activeShift->outlet_id,
            'shift_id' => $activeShift->id,
            'type' => CashMovement::TYPE_REFUND,
            'direction' => CashMovement::DIRECTION_OUT,
            'amount_minor' => $refund->amount_minor,
            'reference_type' => 'payment_refund',
            'reference_id' => $refund->id,
            'reason' => "Cash Refund for payment [{$payment->payment_reference}]",
            'performed_by_user_id' => $refund->initiated_by_user_id ?? $activeShift->opened_by_user_id,
            'metadata' => [
                'refund_id' => $refund->id,
                'payment_id' => $payment->id,
            ],
        ]);

        event(new CashMovementCreated($movement));

        return $movement;
    }

    /**
     * Close an active shift with physical cash count.
     */
    public function closeShift(Shift $shift, int $actualClosingCashMinor, User $user, ?string $notes = null): Shift
    {
        if ($actualClosingCashMinor < 0) {
            throw new InvalidArgumentException('Actual closing cash cannot be negative.');
        }

        return DB::transaction(function () use ($shift, $actualClosingCashMinor, $user, $notes) {
            $lockedShift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if (! $lockedShift->isOpen()) {
                throw new ShiftClosedException("Shift [{$lockedShift->id}] is already closed.");
            }

            // Calculate expected cash closing
            $summary = $this->getCashSummary($lockedShift);
            $expectedClosingCash = $summary['expected_closing'];
            $variance = $actualClosingCashMinor - $expectedClosingCash;

            $now = Carbon::now();

            $lockedShift->update([
                'status' => Shift::STATUS_CLOSED,
                'closed_by_user_id' => $user->id,
                'closed_at' => $now,
                'closing_cash_minor' => $actualClosingCashMinor,
                'expected_cash_minor' => $expectedClosingCash,
                'variance_minor' => $variance,
                'notes' => $notes ?? $lockedShift->notes,
            ]);

            // Add final closing movement for tracking
            CashMovement::create([
                'tenant_id' => $lockedShift->tenant_id,
                'outlet_id' => $lockedShift->outlet_id,
                'shift_id' => $lockedShift->id,
                'type' => CashMovement::TYPE_CLOSING,
                'direction' => CashMovement::DIRECTION_OUT,
                'amount_minor' => $actualClosingCashMinor,
                'reference_type' => 'shift_closing',
                'reference_id' => $lockedShift->id,
                'reason' => 'Shift Closing Count',
                'performed_by_user_id' => $user->id,
                'metadata' => [
                    'expected_cash_minor' => $expectedClosingCash,
                    'variance_minor' => $variance,
                    'notes' => $notes,
                ],
            ]);

            AuditLog::create([
                'tenant_id' => $lockedShift->tenant_id,
                'actor_user_id' => $user->id,
                'action' => 'shift_closed',
                'entity_type' => 'shift',
                'entity_id' => $lockedShift->id,
                'after' => [
                    'outlet_id' => $lockedShift->outlet_id,
                    'closed_at' => $now->toIso8601String(),
                    'closing_cash_minor' => $actualClosingCashMinor,
                    'expected_cash_minor' => $expectedClosingCash,
                    'variance_minor' => $variance,
                ],
                'created_at' => $now,
            ]);

            event(new ShiftClosed($lockedShift));

            return $lockedShift;
        });
    }
}
