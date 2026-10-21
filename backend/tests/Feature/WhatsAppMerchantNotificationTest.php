<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Booking;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Carbon\Carbon;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

class WhatsAppMerchantNotificationTest extends TenantTestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected TenantWhatsAppConnection $connection;

    protected WhatsAppNotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
        MockWhatsAppProvider::reset();

        $tenantData = $this->createTenant('Merchant Notif Waroeng');
        $this->tenant = $tenantData['tenant'];
        $this->outlet = $tenantData['outlet'];
        $this->owner = $tenantData['owner'];

        $this->tenant->update([
            'phone' => '081234567890',
            'settings' => [
                'whatsapp_notifications' => [
                    'consent_given' => true,
                    'merchant_phone' => '081234567890',
                    'daily_sales_summary' => true,
                    'low_stock' => true,
                    'booking_reminder' => true,
                    'payment_settlement' => true,
                    'anomaly_alert' => true,
                ],
                'whatsapp_notifications_provenance' => [
                    'consent_status' => 'consented',
                    'consented_at' => now()->toIso8601String(),
                    'source' => 'merchant_portal_settings',
                ],
            ],
        ]);
        $this->owner->update(['phone_number' => '081234567890']);

        $this->runForTenant(function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenant->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($this->tenant);

        $this->connection = $this->runForTenant(function () {
            return TenantWhatsAppConnection::create([
                'tenant_id' => $this->tenant->id,
                'provider' => 'mock',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'is_default' => true,
                'phone_number_id' => '1122334455',
                'encrypted_credentials' => [
                    'access_token' => 'mock_secret_token',
                    'webhook_verify_token' => 'verify_secret',
                ],
                'configuration' => [
                    'api_version' => 'v21.0',
                ],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
            ]);
        });

        $this->notificationService = app(WhatsAppNotificationService::class);
    }

    protected function runForTenant(callable $callback, ?Tenant $tenant = null): mixed
    {
        return app(TenantManager::class)->runForTenant($tenant ?? $this->tenant, $callback);
    }

    public function test_daily_sales_summary_generation_and_idempotency(): void
    {
        $this->runForTenant(function () {
            // 1. Create finalized sales for today
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-001',
                'receipt_number' => 'REC-001',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 5000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 5000000,
                'finalized_at' => now(),
            ]);

            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-002',
                'receipt_number' => 'REC-002',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 3000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 3000000,
                'finalized_at' => now(),
            ]);

            // 2. Queue daily sales summary
            $message = $this->notificationService->queueDailySalesSummary($this->tenant);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::TYPE_MERCHANT_DAILY_SUMMARY, $message->message_type);
            $this->assertEquals(8000000, $message->payload['total_minor']);
            $this->assertEquals(2, $message->payload['transaction_count']);
            $this->assertEquals(4000000, $message->payload['average_ticket_minor']);
            $this->assertTrue($message->isSent());

            // 3. Idempotency assertion: dispatching again for today does not create a new record
            $message2 = $this->notificationService->queueDailySalesSummary($this->tenant);
            $this->assertEquals($message->id, $message2->id);

            $count = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_MERCHANT_DAILY_SUMMARY)
                ->count();
            $this->assertEquals(1, $count);
        });
    }

    public function test_daily_sales_summary_artisan_command(): void
    {
        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-CMD-01',
                'receipt_number' => 'REC-CMD-01',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 2000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 2000000,
                'finalized_at' => now(),
            ]);
        });

        $exitCode = Artisan::call('whatsapp:send-daily-summary', [
            '--tenant' => $this->tenant->id,
        ]);

        $this->assertEquals(0, $exitCode);

        $this->runForTenant(function () {
            $msg = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_MERCHANT_DAILY_SUMMARY)
                ->first();

            $this->assertNotNull($msg);
            $this->assertEquals(2000000, $msg->payload['total_minor']);
        });
    }

    public function test_daily_sales_summary_respects_merchant_preference_disabled(): void
    {
        $this->runForTenant(function () {
            $this->tenant->settings = [
                'whatsapp_notifications' => [
                    'daily_sales_summary' => false,
                ],
            ];
            $this->tenant->save();

            $msg = $this->notificationService->queueDailySalesSummary($this->tenant);
            $this->assertNull($msg);
        });
    }

    public function test_low_stock_alert_generation_and_idempotency(): void
    {
        $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'code' => 'WH-01',
                'name' => 'Gudang Utama',
                'type' => 'warehouse',
            ]);

            $item = InventoryItem::create([
                'tenant_id' => $this->tenant->id,
                'sku' => 'SKU-KOPI',
                'name' => 'Biji Kopi Arabika',
                'base_unit' => 'kg',
                'status' => 'active',
            ]);

            $itemLoc = InventoryItemLocation::create([
                'tenant_id' => $this->tenant->id,
                'inventory_item_id' => $item->id,
                'location_id' => $loc->id,
                'on_hand' => '2.0000',
                'reserved' => '0.0000',
                'reorder_level' => '5.0000',
            ]);

            $this->assertTrue($itemLoc->isLowStock());

            // 1. Queue alert
            $message = $this->notificationService->queueLowStockAlert($this->tenant, $itemLoc);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::TYPE_MERCHANT_LOW_STOCK, $message->message_type);
            $this->assertTrue($message->isSent());
            $this->assertStringContainsString('Biji Kopi Arabika', $message->payload['summary']);

            // 2. Idempotency on same day
            $message2 = $this->notificationService->queueLowStockAlert($this->tenant, $itemLoc);
            $this->assertEquals($message->id, $message2->id);
        });
    }

    public function test_merchant_booking_reminder(): void
    {
        $this->runForTenant(function () {
            $table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Meja VIP 1',
                'code' => 'T-VIP-1',
                'capacity' => 6,
                'status' => Table::STATUS_AVAILABLE,
            ]);

            $booking = Booking::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'table_id' => $table->id,
                'booking_code' => 'BKG-VIP-888',
                'customer_name_snapshot' => 'Budi Santoso',
                'customer_contact_snapshot' => '081234567890',
                'party_size' => 4,
                'starts_at' => now()->addHours(2),
                'ends_at' => now()->addHours(4),
                'status' => Booking::STATUS_CONFIRMED,
            ]);

            $message = $this->notificationService->queueMerchantBookingReminder($booking);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::TYPE_MERCHANT_BOOKING_REMINDER, $message->message_type);
            $this->assertStringContainsString('BKG-VIP-888', $message->payload['summary']);
            $this->assertTrue($message->isSent());

            // Idempotent duplicate
            $message2 = $this->notificationService->queueMerchantBookingReminder($booking);
            $this->assertEquals($message->id, $message2->id);
        });
    }

    public function test_payment_settlement_notification(): void
    {
        $this->runForTenant(function () {
            $settlementData = [
                'reference' => 'SETTLE-20261010-001',
                'provider' => 'Duitku Gateway',
                'amount_minor' => 12500000,
                'transaction_count' => 15,
            ];

            $message = $this->notificationService->queuePaymentSettlementAlert($this->tenant, $settlementData);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::TYPE_MERCHANT_SETTLEMENT, $message->message_type);
            $this->assertStringContainsString('SETTLE-20261010-001', $message->payload['summary']);
            $this->assertTrue($message->isSent());

            // Idempotency
            $message2 = $this->notificationService->queuePaymentSettlementAlert($this->tenant, $settlementData);
            $this->assertEquals($message->id, $message2->id);
        });
    }

    public function test_anomaly_alert_for_shift_variance(): void
    {
        $this->runForTenant(function () {
            $message = $this->notificationService->queueAnomalyAlert(
                $this->tenant,
                'shift_cash_variance',
                'Terdapat selisih kas fisik closing shift sebesar -Rp 150.000 (Expected: Rp 1.000.000, Actual: Rp 850.000)',
                ['reference_id' => 'shift_var_001']
            );

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::TYPE_MERCHANT_ANOMALY, $message->message_type);
            $this->assertStringContainsString('selisih kas fisik', $message->payload['summary']);
            $this->assertTrue($message->isSent());

            // Idempotency
            $message2 = $this->notificationService->queueAnomalyAlert(
                $this->tenant,
                'shift_cash_variance',
                'Duplicate alert attempt',
                ['reference_id' => 'shift_var_001']
            );
            $this->assertEquals($message->id, $message2->id);
        });
    }

    public function test_multi_tenant_isolation_on_merchant_notifications(): void
    {
        $tenantBData = $this->createTenant('Tenant B Waroeng');
        $tenantB = $tenantBData['tenant'];

        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-ISOL-A',
                'receipt_number' => 'REC-ISOL-A',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 10000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 10000000,
                'finalized_at' => now(),
            ]);

            $this->notificationService->queueDailySalesSummary($this->tenant);
        });

        // Under Tenant B context, Tenant A message must not be visible
        $this->runForTenant(function () {
            $messages = WhatsAppMessage::where('tenant_id', $this->tenant->id)->get();
            $this->assertEmpty($messages, 'Tenant B cannot see Tenant A whatsapp messages');
        }, $tenantB);
    }

    public function test_merchant_without_positive_consent_is_not_sent_notifications(): void
    {
        $this->runForTenant(function () {
            // Unset WhatsApp notification settings to simulate tenant without consent
            $this->tenant->settings = [];
            $this->tenant->save();

            $msg = $this->notificationService->queueDailySalesSummary($this->tenant);
            $this->assertNull($msg, 'Merchant without explicit consent must not be queued');
        });
    }

    public function test_merchant_opt_out_after_enqueue_prevents_delivery(): void
    {
        $this->runForTenant(function () {
            config(['queue.defer_whatsapp_in_testing' => true]);

            // 1. Enqueue message when merchant is consented
            $message = $this->notificationService->queueDailySalesSummary($this->tenant);
            $this->assertNotNull($message);
            $this->assertTrue($message->isPending());

            // 2. Merchant revokes consent / disables daily sales summary before queue job executes
            $this->tenant->settings = [
                'whatsapp_notifications' => [
                    'consent_given' => true,
                    'daily_sales_summary' => false,
                ],
            ];
            $this->tenant->save();

            // 3. Worker attempts execution
            $job = new SendWhatsAppMessageJob($message->id);
            $job->handle(
                $this->notificationService,
                app(WhatsAppProviderManager::class),
                app(SubscriptionEntitlementService::class),
                app(TenantManager::class)
            );

            $message->refresh();
            $this->assertTrue($message->isFailed(), 'Message must fail when merchant revoked consent prior to delivery');
            $this->assertEquals('MERCHANT_OPTED_OUT', $message->error_code);

            config(['queue.defer_whatsapp_in_testing' => false]);
        });
    }

    public function test_merchant_preferences_provenance_is_persisted_via_api(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->putJson('/api/v1/whatsapp/preferences', [
            'merchant_phone' => '08987654321',
            'consent_given' => true,
            'daily_sales_summary' => true,
            'low_stock' => false,
            'booking_reminder' => true,
            'payment_settlement' => true,
            'anomaly_alert' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.merchant_phone', '08987654321')
            ->assertJsonPath('data.consent_given', true)
            ->assertJsonPath('data.provenance.consent_status', 'consented')
            ->assertJsonPath('data.provenance.source', 'merchant_portal_settings');

        $this->tenant->refresh();
        $this->assertEquals('consented', $this->tenant->settings['whatsapp_notifications_provenance']['consent_status']);
        $this->assertFalse($this->tenant->settings['whatsapp_notifications']['low_stock']);
    }

    public function test_booking_reminder_skipped_for_cancelled_or_completed_booking(): void
    {
        $this->runForTenant(function () {
            $table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Meja VIP 1',
                'code' => 'T-VIP-01',
                'capacity' => 4,
                'status' => Table::STATUS_AVAILABLE,
            ]);

            $booking = Booking::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'table_id' => $table->id,
                'booking_code' => 'BKG-CANCEL-99',
                'customer_name_snapshot' => 'Budi Tamu',
                'customer_contact_snapshot' => '081233334444',
                'party_size' => 4,
                'starts_at' => now()->addHour(),
                'ends_at' => now()->addHours(2),
                'status' => Booking::STATUS_CANCELLED,
            ]);

            // Merchant reminder must return null and be skipped
            $mMsg = $this->notificationService->queueMerchantBookingReminder($booking);
            $this->assertNull($mMsg, 'Cancelled booking must not trigger merchant reminder');

            // Customer reminder must return null and be skipped
            $cMsg = $this->notificationService->queueCustomerBookingReminder($booking);
            $this->assertNull($cMsg, 'Cancelled booking must not trigger customer reminder');

            // Change to completed, must still be skipped
            $booking->update(['status' => Booking::STATUS_COMPLETED]);
            $this->assertNull($this->notificationService->queueMerchantBookingReminder($booking));
            $this->assertNull($this->notificationService->queueCustomerBookingReminder($booking));
        });
    }

    public function test_daily_sales_summary_with_check_hour_and_timezone_boundary(): void
    {
        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-TZ-01',
                'receipt_number' => 'REC-TZ-01',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 1500000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 1500000,
                'finalized_at' => now(),
            ]);

            // Set tenant to Asia/Jayapura
            $this->tenant->update(['timezone' => 'Asia/Jayapura']);
        });

        // Run with --check-hour flag: if current hour in Asia/Jayapura is not 22, it skips
        $currentJayapuraHour = Carbon::now('Asia/Jayapura')->hour;
        $exitCode = Artisan::call('whatsapp:send-daily-summary', [
            '--tenant' => $this->tenant->id,
            '--check-hour' => true,
        ]);
        $this->assertEquals(0, $exitCode);

        $this->runForTenant(function () use ($currentJayapuraHour) {
            $msg = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_MERCHANT_DAILY_SUMMARY)
                ->first();

            if ($currentJayapuraHour === 22) {
                $this->assertNotNull($msg);
            } else {
                $this->assertNull($msg, 'Command with --check-hour must skip when local hour is not 22:00');
            }
        });
    }

    public function test_manual_resend_revalidates_merchant_preference(): void
    {
        $message = $this->runForTenant(function () {
            return WhatsAppMessage::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'recipient_phone' => '+6281234567890',
                'message_type' => WhatsAppMessage::TYPE_MERCHANT_DAILY_SUMMARY,
                'status' => WhatsAppMessage::STATUS_FAILED,
                'error_code' => 'SEND_FAILED',
                'provider' => 'mock',
                'idempotency_key' => 'whatsapp:test:resend_merchant',
                'payload' => ['summary' => 'Daily summary'],
            ]);
        });

        // Revoke merchant consent in settings
        $this->runForTenant(function () {
            $this->tenant->update([
                'settings' => [
                    'whatsapp_notifications' => [
                        'consent_given' => false,
                        'daily_sales_summary' => false,
                    ],
                ],
            ]);
        });

        // Manual resend must fail fast because consent was revoked
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Merchant telah mematikan notifikasi 'daily_sales_summary'");
        $this->notificationService->resendMessage($message);
    }
}
