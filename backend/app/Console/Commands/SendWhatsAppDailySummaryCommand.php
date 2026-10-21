<?php

namespace App\Console\Commands;

use App\Domain\Tenant\TenantManager;
use App\Models\Tenant;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWhatsAppDailySummaryCommand extends Command
{
    protected $signature = 'whatsapp:send-daily-summary
                            {--tenant= : Specific tenant UUID to process}
                            {--date= : Specific date (YYYY-MM-DD), defaults to today}
                            {--check-hour : Only process if tenant local time is 22:00}';

    protected $description = 'Dispatch daily sales summary WhatsApp notification to merchant owners';

    public function handle(
        WhatsAppNotificationService $notificationService,
        SubscriptionEntitlementService $entitlementService,
        TenantManager $tenantManager
    ): int {
        $tenantId = $this->option('tenant');
        $date = $this->option('date');
        $checkHour = (bool) $this->option('check-hour');

        $query = Tenant::query()->where('is_active', true);
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();
        $sentCount = 0;
        $skippedCount = 0;

        foreach ($tenants as $tenant) {
            try {
                $tenantManager->runForTenant($tenant, function () use (
                    $tenant,
                    $date,
                    $checkHour,
                    $notificationService,
                    $entitlementService,
                    &$sentCount,
                    &$skippedCount
                ) {
                    if (! $entitlementService->can($tenant, 'whatsapp')) {
                        $skippedCount++;

                        return;
                    }

                    if ($checkHour) {
                        $tz = $tenant->timezone ?? 'Asia/Jakarta';
                        $localHour = Carbon::now($tz)->hour;
                        if ($localHour !== 22) {
                            $skippedCount++;

                            return;
                        }
                    }

                    $message = $notificationService->queueDailySalesSummary($tenant, $date);
                    if ($message) {
                        $sentCount++;
                        $this->info("Queued daily sales summary for tenant {$tenant->name} ({$tenant->id})");
                    } else {
                        $skippedCount++;
                    }
                });
            } catch (\Throwable $e) {
                Log::error("Failed to queue daily sales summary for tenant {$tenant->id}: {$e->getMessage()}");
                $this->error("Error for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info("Completed daily sales summary processing: {$sentCount} queued, {$skippedCount} skipped.");

        return Command::SUCCESS;
    }
}
