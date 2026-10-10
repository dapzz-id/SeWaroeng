<?php

namespace App\Providers;

use App\Domain\Tenant\TenantManager;
use App\Events\CashMovementCreated;
use App\Events\PaymentFailed;
use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Events\SaleCancelled;
use App\Events\SaleFinalized;
use App\Events\ShiftClosed;
use App\Models\CashMovement;
use App\Models\Notification;
use App\Models\PersonalAccessToken;
use App\Services\Finance\FinanceService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Notification\NotificationService;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Subscription\ManualSubscriptionBillingProvider;
use App\Services\Subscription\SubscriptionBillingProviderInterface;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantManager::class, function () {
            return new TenantManager;
        });

        $this->app->singleton(PaymentProviderManager::class, function () {
            return new PaymentProviderManager;
        });

        $this->app->singleton(WhatsAppProviderManager::class, function () {
            return new WhatsAppProviderManager;
        });

        $this->app->singleton(FinanceService::class, function () {
            return new FinanceService;
        });

        $this->app->bind(
            SubscriptionBillingProviderInterface::class,
            ManualSubscriptionBillingProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Financial Journal Event Listeners (Phase 1G) & Loyalty Points (Phase 1K)
        Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
            $payment = $event->payment->fresh(['sale.payments']);
            if ($payment && $payment->sale && $payment->sale->isFinalized() && $payment->sale->getPaidAmountMinor() >= $payment->sale->total_minor) {
                app(FinanceService::class)->postSale($payment->sale);
                try {
                    app(LoyaltyService::class)->consumeVoucherForSale($payment->sale);
                } catch (\Throwable $e) {
                    Log::warning('Loyalty voucher consumption failed on payment: '.$e->getMessage());
                }
                try {
                    app(LoyaltyService::class)->awardPointsForSale($payment->sale);
                } catch (\Throwable $e) {
                    Log::warning('Loyalty point award failed on payment: '.$e->getMessage());
                }
                try {
                    app(WhatsAppNotificationService::class)->handlePaymentSucceeded($payment);
                } catch (\Throwable $e) {
                    Log::warning('WhatsApp receipt dispatch failed on payment: '.$e->getMessage());
                }
            }
        });

        Event::listen(SaleFinalized::class, function (SaleFinalized $event) {
            $sale = $event->sale->fresh(['payments']);
            if ($sale && $sale->getPaidAmountMinor() >= $sale->total_minor) {
                if ($sale->total_minor > 0) {
                    app(FinanceService::class)->postSale($sale);
                }
                try {
                    app(LoyaltyService::class)->consumeVoucherForSale($sale);
                } catch (\Throwable $e) {
                    Log::warning('Loyalty voucher consumption failed on finalize: '.$e->getMessage());
                }
                try {
                    app(LoyaltyService::class)->awardPointsForSale($sale);
                } catch (\Throwable $e) {
                    Log::warning('Loyalty point award failed on finalize: '.$e->getMessage());
                }
                try {
                    app(WhatsAppNotificationService::class)->handleSaleFinalized($sale);
                } catch (\Throwable $e) {
                    Log::warning('WhatsApp receipt dispatch failed on finalize: '.$e->getMessage());
                }
            }
        });

        Event::listen(SaleCancelled::class, function (SaleCancelled $event) {
            try {
                app(LoyaltyService::class)->releaseVoucherReservation($event->sale);
            } catch (\Throwable $e) {
                Log::warning('Loyalty voucher release failed on sale cancellation: '.$e->getMessage());
            }
        });

        Event::listen(PaymentRefunded::class, function (PaymentRefunded $event) {
            $refund = $event->refund->fresh(['payment.sale']);
            if ($refund && $refund->isCompleted()) {
                app(FinanceService::class)->postRefund($refund);
            }
        });

        Event::listen(CashMovementCreated::class, function (CashMovementCreated $event) {
            $movement = $event->movement;
            if (in_array($movement->type, [CashMovement::TYPE_CASH_IN, CashMovement::TYPE_CASH_OUT], true)) {
                app(FinanceService::class)->postCashMovement($movement);
            }
        });

        // Operational Notification Listeners (Phase 1H)
        Event::listen(ShiftClosed::class, function (ShiftClosed $event) {
            $shift = $event->shift;
            $variance = (int) ($shift->variance_minor ?? ($shift->closing_cash_minor - $shift->expected_cash_minor));
            if ($variance !== 0) {
                app(NotificationService::class)->createNotification($shift->tenant_id, [
                    'type' => Notification::TYPE_SHIFT_VARIANCE,
                    'title' => 'Selisih Kas Shift',
                    'message' => 'Perbedaan kas pada penutupan shift perlu ditinjau.',
                    'severity' => Notification::SEVERITY_WARNING,
                    'data' => [
                        'shift_id' => $shift->id,
                        'outlet_id' => $shift->outlet_id,
                        'opened_by_user_id' => $shift->opened_by_user_id,
                        'expected_closing_cash' => $shift->expected_cash_minor,
                        'actual_closing_cash' => $shift->closing_cash_minor,
                        'variance' => $variance,
                    ],
                    'dedup_key' => "shift_variance:shift_{$shift->id}",
                ]);
            }
        });

        Event::listen(PaymentFailed::class, function (PaymentFailed $event) {
            $payment = $event->payment;
            app(NotificationService::class)->createNotification($payment->tenant_id, [
                'type' => Notification::TYPE_PAYMENT_STATUS,
                'title' => 'Pembayaran Gagal',
                'message' => "Pembayaran {$payment->reference_number} gagal diproses.",
                'severity' => Notification::SEVERITY_CRITICAL,
                'data' => [
                    'status' => 'failed',
                    'payment_id' => $payment->id,
                    'sale_id' => $payment->sale_id,
                    'reference_number' => $payment->reference_number,
                    'amount_minor' => $payment->amount_minor,
                    'method' => $payment->method,
                    'reason' => $event->reason,
                ],
                'dedup_key' => "payment_status:payment_{$payment->id}:failed",
            ]);
        });
    }
}
