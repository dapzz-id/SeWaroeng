<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppNotificationService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TenantTestCase;

class WhatsAppCustomerMessagingTest extends TenantTestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected Customer $customer;

    protected MockWhatsAppProvider $mockProvider;

    protected WhatsAppNotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
        MockWhatsAppProvider::reset();

        $tenantData = $this->createTenant('Waroeng Enak Rasa');
        $this->tenant = $tenantData['tenant'];
        $this->outlet = $tenantData['outlet'];
        $this->owner = $tenantData['owner'];

        $this->runForTenant(function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenant->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($this->tenant);

        $this->runForTenant(function () {

            TenantWhatsAppConnection::create([
                'tenant_id' => $this->tenant->id,
                'provider' => 'mock',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'is_default' => true,
                'phone_number_id' => '100200300',
                'business_account_id' => '900800700',
                'display_phone_number' => '+6281200001111',
                'encrypted_credentials' => ['access_token' => 'mock_token'],
                'configuration' => ['api_version' => 'v21.0'],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
            ]);

            $this->customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Rina Sasmita',
                'phone' => '+6281234567890',
                'whatsapp_opt_in' => true,
                'whatsapp_opted_out' => false,
                'marketing_opt_in' => false,
            ]);

            $this->table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Meja Utama 1',
                'code' => 'T-01',
                'capacity' => 4,
                'status' => Table::STATUS_AVAILABLE,
            ]);

            $this->tableSession = TableSession::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'table_id' => $this->table->id,
                'session_code' => 'SESS-001',
                'status' => 'active',
                'guest_count' => 2,
                'started_at' => now(),
            ]);
        });

        $this->notificationService = app(WhatsAppNotificationService::class);
    }

    protected function runForTenant(callable $callback, ?Tenant $tenant = null): mixed
    {
        return app(TenantManager::class)->runForTenant($tenant ?? $this->tenant, $callback);
    }

    public function test_order_confirmation_notification(): void
    {
        $this->runForTenant(function () {
            $order = Order::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $this->customer->id,
                'table_id' => $this->table->id,
                'table_session_id' => $this->tableSession->id,
                'order_number' => 'ORD-1010-001',
                'status' => Order::STATUS_ACCEPTED,
                'total_minor' => 4500000,
            ]);

            $message = $this->notificationService->queueOrderConfirmation($order);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::TYPE_ORDER_CONFIRMATION, $message->message_type);
            $this->assertTrue($message->isSent());
            $this->assertStringContainsString('ORD-1010-001', $message->payload['summary']);

            // Idempotency check
            $duplicate = $this->notificationService->queueOrderConfirmation($order);
            $this->assertEquals($message->id, $duplicate->id);
        });
    }

    public function test_booking_confirmation_notification(): void
    {
        $this->runForTenant(function () {
            $table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Meja VIP 2',
                'code' => 'T-VIP-2',
                'capacity' => 4,
                'status' => Table::STATUS_AVAILABLE,
            ]);

            $booking = Booking::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $this->customer->id,
                'table_id' => $table->id,
                'booking_code' => 'BKG-VIP-999',
                'customer_name_snapshot' => $this->customer->name,
                'customer_contact_snapshot' => $this->customer->phone,
                'party_size' => 4,
                'starts_at' => now()->addDay()->setHour(19),
                'ends_at' => now()->addDay()->setHour(21),
                'status' => Booking::STATUS_CONFIRMED,
            ]);

            $message = $this->notificationService->queueBookingConfirmation($booking);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::TYPE_BOOKING_CONFIRMATION, $message->message_type);
            $this->assertTrue($message->isSent());
            $this->assertStringContainsString('BKG-VIP-999', $message->payload['summary']);

            // Idempotency check
            $duplicate = $this->notificationService->queueBookingConfirmation($booking);
            $this->assertEquals($message->id, $duplicate->id);
        });
    }

    public function test_customer_booking_reminder_and_command(): void
    {
        $this->runForTenant(function () {
            $table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Meja Reservasi 1',
                'code' => 'T-RES-1',
                'capacity' => 2,
                'status' => Table::STATUS_AVAILABLE,
            ]);

            $booking = Booking::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $this->customer->id,
                'table_id' => $table->id,
                'booking_code' => 'BKG-TODAY-001',
                'customer_name_snapshot' => $this->customer->name,
                'customer_contact_snapshot' => $this->customer->phone,
                'party_size' => 2,
                'starts_at' => now()->addHours(2),
                'ends_at' => now()->addHours(4),
                'status' => Booking::STATUS_CONFIRMED,
            ]);

            // Direct service queue
            $message = $this->notificationService->queueCustomerBookingReminder($booking);
            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::TYPE_BOOKING_REMINDER, $message->message_type);
            $this->assertTrue($message->isSent());
        });

        // Test CLI command execution
        $exitCode = Artisan::call('whatsapp:booking-reminders', [
            '--tenant' => $this->tenant->id,
        ]);
        $this->assertEquals(0, $exitCode);
    }

    public function test_promotion_requires_strict_positive_marketing_opt_in(): void
    {
        $this->runForTenant(function () {
            // Customer initially has marketing_opt_in = false
            $this->assertFalse($this->customer->marketing_opt_in);

            $failedMessage = $this->notificationService->queuePromotion(
                $this->tenant,
                $this->customer,
                'Diskon Akhir Pekan 20%',
                'Dapatkan diskon 20% untuk semua menu makanan akhir pekan ini!'
            );

            // Must fail-closed due to absence of affirmative marketing consent
            $this->assertNotNull($failedMessage);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $failedMessage->status);
            $this->assertEquals('MARKETING_OPT_IN_REQUIRED', $failedMessage->error_code);

            // Now grant affirmative marketing opt-in
            $this->customer->marketing_opt_in = true;
            $this->customer->save();

            $successMessage = $this->notificationService->queuePromotion(
                $this->tenant,
                $this->customer,
                'Diskon Akhir Pekan 20%',
                'Dapatkan diskon 20% untuk semua menu makanan akhir pekan ini!',
                'campaign_weekend_01'
            );

            $this->assertNotNull($successMessage);
            $this->assertEquals(WhatsAppMessage::STATUS_SENT, $successMessage->status);
            $this->assertEquals(WhatsAppMessage::TYPE_MARKETING_PROMO, $successMessage->message_type);
            $this->assertStringContainsString('Diskon Akhir Pekan', $successMessage->payload['summary']);

            // Customer opts out completely
            $this->customer->whatsapp_opted_out = true;
            $this->customer->save();

            $optedOutMessage = $this->notificationService->queuePromotion(
                $this->tenant,
                $this->customer,
                'Diskon Lainnya',
                'Promo spesial lainnya',
                'campaign_weekend_02'
            );

            $this->assertNotNull($optedOutMessage);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $optedOutMessage->status);
            $this->assertEquals('CUSTOMER_OPTED_OUT', $optedOutMessage->error_code);
        });
    }

    public function test_order_status_update_notification(): void
    {
        $this->runForTenant(function () {
            $order = Order::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $this->customer->id,
                'table_id' => $this->table->id,
                'table_session_id' => $this->tableSession->id,
                'order_number' => 'ORD-STATUS-01',
                'status' => Order::STATUS_ACCEPTED,
                'total_minor' => 3000000,
            ]);

            // Preparing update
            $msg1 = $this->notificationService->queueOrderStatusUpdate($order, 'preparing');
            $this->assertNotNull($msg1);
            $this->assertEquals(WhatsAppMessage::TYPE_ORDER_STATUS_UPDATE, $msg1->message_type);
            $this->assertTrue($msg1->isSent());
            $this->assertStringContainsString('Pesanan Sedang Disiapkan', $msg1->payload['summary']);

            // Ready update
            $msg2 = $this->notificationService->queueOrderStatusUpdate($order, 'ready');
            $this->assertNotNull($msg2);
            $this->assertTrue($msg2->isSent());
            $this->assertStringContainsString('Siap Diambil', $msg2->payload['summary']);
        });
    }

    public function test_customer_messaging_multi_tenant_isolation(): void
    {
        $tenantBData = $this->createTenant('Tenant B Cafe');
        $tenantB = $tenantBData['tenant'];

        $this->runForTenant(function () {
            $order = Order::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $this->customer->id,
                'table_id' => $this->table->id,
                'table_session_id' => $this->tableSession->id,
                'order_number' => 'ORD-ISOL-01',
                'status' => Order::STATUS_ACCEPTED,
                'total_minor' => 2000000,
            ]);

            $this->notificationService->queueOrderConfirmation($order);
        });

        // In Tenant B context, Tenant A customer message cannot be queried
        $this->runForTenant(function () {
            $messages = WhatsAppMessage::where('tenant_id', $this->tenant->id)->get();
            $this->assertEmpty($messages, 'Tenant B context cannot see Tenant A whatsapp messages');
        }, $tenantB);
    }
}
