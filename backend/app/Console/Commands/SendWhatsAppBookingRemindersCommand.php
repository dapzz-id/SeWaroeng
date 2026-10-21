<?php

namespace App\Console\Commands;

use App\Domain\Tenant\TenantManager;
use App\Models\Booking;
use App\Models\Tenant;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWhatsAppBookingRemindersCommand extends Command
{
    protected $signature = 'whatsapp:booking-reminders
                            {--tenant= : Specific tenant UUID to process}';

    protected $description = 'Dispatch upcoming booking reminders to merchants and opted-in customers';

    public function handle(
        WhatsAppNotificationService $notificationService,
        SubscriptionEntitlementService $entitlementService,
        TenantManager $tenantManager
    ): int {
        $tenantId = $this->option('tenant');

        $query = Tenant::query()->where('is_active', true);
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();
        $merchantReminders = 0;
        $customerReminders = 0;

        foreach ($tenants as $tenant) {
            if (! $entitlementService->can($tenant, 'whatsapp')) {
                continue;
            }

            $tenantManager->runForTenant($tenant, function () use (
                $tenant,
                $notificationService,
                &$merchantReminders,
                &$customerReminders
            ) {
                $tz = $tenant->timezone ?? 'Asia/Jakarta';
                $now = Carbon::now($tz);
                $today = $now->toDateString();

                // Look strictly for upcoming confirmed bookings today (exclude cancelled, completed, no-show, or pending)
                $bookings = Booking::where('tenant_id', $tenant->id)
                    ->where('status', Booking::STATUS_CONFIRMED)
                    ->whereDate('starts_at', $today)
                    ->where('starts_at', '>=', $now->copy()->subMinutes(15))
                    ->with(['customer', 'outlet', 'table'])
                    ->get();

                foreach ($bookings as $booking) {
                    try {
                        // 1. Merchant reminder
                        $mMsg = $notificationService->queueMerchantBookingReminder($booking);
                        if ($mMsg && $mMsg->isPending()) {
                            $merchantReminders++;
                        }

                        // 2. Customer reminder (only if positive consent)
                        if ($booking->customer_id || $booking->customer_contact_snapshot) {
                            $cMsg = $notificationService->queueCustomerBookingReminder($booking);
                            if ($cMsg && $cMsg->isPending()) {
                                $customerReminders++;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Booking reminder failed for booking {$booking->id}: {$e->getMessage()}");
                    }
                }
            });
        }

        $this->info("Booking reminders dispatched: {$merchantReminders} merchant reminders, {$customerReminders} customer reminders.");

        return Command::SUCCESS;
    }
}
