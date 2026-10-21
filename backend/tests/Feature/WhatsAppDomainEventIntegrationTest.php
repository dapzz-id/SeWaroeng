<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\ShiftClosed;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PaymentReconciliation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Booking\BookingService;
use App\Services\InventoryService;
use App\Services\Order\OrderService;
use App\Services\Payment\Reconciliation\PaymentReconciliationService;
use App\Services\Payment\Reconciliation\ProviderEvidence;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

class WhatsAppDomainEventIntegrationTest extends TenantTestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected Customer $customer;

    protected TenantWhatsAppConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
        MockWhatsAppProvider::reset();

        $tenantData = $this->createTenant('Domain Event Waroeng');
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
                    'source' => 'test_setup',
                ],
            ],
        ]);
        $this->owner->update(['phone_number' => '081234567890']);

        $this->runForTenant(function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenant->id)->update(['plan_id' => $businessPlan->id]);

            $this->customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Budi Santoso',
                'phone' => '081299887766',
                'whatsapp_opt_in' => true,
                'whatsapp_opted_out' => false,
                'is_active' => true,
            ]);
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
    }

    protected function runForTenant(callable $callback, ?Tenant $tenant = null): mixed
    {
        return app(TenantManager::class)->runForTenant($tenant ?? $this->tenant, $callback);
    }

    public function test_shift_closed_with_cash_variance_triggers_anomaly_whatsapp_message(): void
    {
        $this->runForTenant(function () {
            $shift = Shift::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'opened_by_user_id' => $this->owner->id,
                'closed_by_user_id' => $this->owner->id,
                'starting_cash_minor' => 10000000,
                'expected_cash_minor' => 15000000,
                'closing_cash_minor' => 14000000, // Rp 10.000 minus variance
                'variance_minor' => -1000000,
                'status' => Shift::STATUS_CLOSED,
                'opened_at' => now()->subHours(8),
                'closed_at' => now(),
            ]);

            // Fire the domain event
            event(new ShiftClosed($shift));

            $msg = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_MERCHANT_ANOMALY)
                ->first();

            $this->assertNotNull($msg, 'Anomaly alert message must be queued on shift cash variance');
            $this->assertTrue($msg->isSent());
            $this->assertStringContainsString('Selisih Kas Shift', $msg->payload['summary']);
            $this->assertStringContainsString('Kekurangan kas: Rp 10.000', $msg->payload['summary']);
        });
    }

    public function test_order_submission_triggers_customer_order_confirmation(): void
    {
        $this->runForTenant(function () {
            $rawToken = 'test-token-qr-event-1';
            $table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'code' => 'T-01',
                'name' => 'Meja 1',
                'capacity' => 4,
                'status' => Table::STATUS_AVAILABLE,
                'is_active' => true,
                'qr_token_hash' => Table::hashToken($rawToken),
            ]);

            $cat = Category::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Minuman',
            ]);

            $prod = Product::create([
                'tenant_id' => $this->tenant->id,
                'category_id' => $cat->id,
                'name' => 'Es Teh Manis',
                'is_active' => true,
            ]);

            $variant = ProductVariant::create([
                'tenant_id' => $this->tenant->id,
                'product_id' => $prod->id,
                'sku' => 'SKU-ESTEH',
                'name' => 'Reguler',
                'price_minor' => 500000,
                'is_active' => true,
            ]);

            $orderService = app(OrderService::class);
            $order = $orderService->submitOrderFromQr(
                qrToken: $rawToken,
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 2,
                    ],
                ],
                customerNotes: 'Kurang manis',
                idempotencyKey: 'order_test_event_1',
                customerData: [
                    'id' => $this->customer->id,
                    'name' => $this->customer->name,
                    'phone' => $this->customer->phone,
                ]
            );

            $this->assertNotNull($order);

            $msg = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_ORDER_CONFIRMATION)
                ->first();

            $this->assertNotNull($msg, 'Order confirmation must be queued on order submission');
            $this->assertTrue($msg->isSent());
            $this->assertStringContainsString($order->order_number, $msg->payload['summary']);
        });
    }

    public function test_order_status_update_triggers_status_update_notification(): void
    {
        $this->runForTenant(function () {
            $table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'code' => 'T-02',
                'name' => 'Meja 2',
                'capacity' => 4,
                'status' => Table::STATUS_AVAILABLE,
                'is_active' => true,
            ]);

            $session = TableSession::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'table_id' => $table->id,
                'session_code' => 'SESS-TEST-001',
                'status' => TableSession::STATUS_ACTIVE,
                'guest_count' => 2,
                'started_at' => now(),
            ]);

            $order = Order::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'table_id' => $table->id,
                'table_session_id' => $session->id,
                'customer_id' => $this->customer->id,
                'customer_name_snapshot' => $this->customer->name,
                'customer_contact_snapshot' => $this->customer->phone,
                'order_number' => 'ORD-STATUS-001',
                'status' => Order::STATUS_SUBMITTED,
                'subtotal_minor' => 1000000,
                'total_minor' => 1000000,
                'idempotency_key' => 'order_status_event_1',
            ]);

            $orderService = app(OrderService::class);
            $orderService->updateOrderStatus($order, Order::STATUS_READY, $this->owner->id);

            $msg = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_ORDER_STATUS_UPDATE)
                ->first();

            $this->assertNotNull($msg, 'Order status update message must be queued');
            $this->assertTrue($msg->isSent());
            $this->assertStringContainsString('Pesanan Sudah Siap Diambil / Diantar', $msg->payload['summary']);
        });
    }

    public function test_booking_creation_triggers_booking_confirmation(): void
    {
        $this->runForTenant(function () {
            $table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'code' => 'T-BKG-01',
                'name' => 'Meja VIP',
                'capacity' => 6,
                'status' => Table::STATUS_AVAILABLE,
                'is_active' => true,
            ]);

            $bookingService = app(BookingService::class);
            $booking = $bookingService->createBooking(
                $this->tenant,
                $this->outlet->id,
                [
                    'table_id' => $table->id,
                    'customer_id' => $this->customer->id,
                    'customer_name' => $this->customer->name,
                    'customer_contact' => $this->customer->phone,
                    'party_size' => 4,
                    'starts_at' => Carbon::now()->addHours(3)->toIso8601String(),
                    'status' => Booking::STATUS_CONFIRMED,
                ],
                $this->owner->id
            );

            $this->assertNotNull($booking);

            $msg = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_BOOKING_CONFIRMATION)
                ->first();

            $this->assertNotNull($msg, 'Booking confirmation must be queued on confirmed booking creation');
            $this->assertTrue($msg->isSent());
            $this->assertStringContainsString($booking->booking_code, $msg->payload['summary']);
        });
    }

    public function test_low_stock_adjustment_triggers_low_stock_alert(): void
    {
        $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'code' => 'WH-TEST',
                'name' => 'Gudang Tes',
                'type' => 'warehouse',
            ]);

            $item = InventoryItem::create([
                'tenant_id' => $this->tenant->id,
                'sku' => 'SKU-GULA',
                'name' => 'Gula Pasir',
                'base_unit' => 'kg',
                'status' => 'active',
            ]);

            $itemLoc = InventoryItemLocation::create([
                'tenant_id' => $this->tenant->id,
                'inventory_item_id' => $item->id,
                'location_id' => $loc->id,
                'on_hand' => '10.0000',
                'reserved' => '0.0000',
                'reorder_level' => '5.0000',
            ]);

            $inventoryService = app(InventoryService::class);
            // Adjust stock down to 2 kg (below reorder_level 5 kg)
            $inventoryService->recordMovement(
                tenantId: $this->tenant->id,
                inventoryItemId: $item->id,
                locationId: $loc->id,
                movementType: 'adjustment_out',
                delta: -8.0,
                reason: 'Stock count reduction',
                userId: $this->owner->id
            );

            $msg = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_MERCHANT_LOW_STOCK)
                ->first();

            $this->assertNotNull($msg, 'Low stock alert must be queued when stock drops below threshold');
            $this->assertTrue($msg->isSent());
            $this->assertStringContainsString('Gula Pasir', $msg->payload['summary']);
        });
    }

    public function test_payment_reconciliation_settlement_triggers_settlement_alert(): void
    {
        $this->runForTenant(function () {
            $evidence = new ProviderEvidence(
                provider: 'midtrans',
                providerReference: 'midtrans-settle-9988',
                amountMinor: 25000000,
                internalReference: 'ORDER-MID-01',
                channel: 'qris',
                settlementStatus: 'settled',
                occurredAt: now(),
                rawMetadata: ['raw' => 'evidence']
            );

            $reconService = app(PaymentReconciliationService::class);
            $recon = $reconService->reconcileEvidence($this->tenant->id, $evidence, $this->owner->id);

            $this->assertEquals(PaymentReconciliation::SETTLEMENT_SETTLED, $recon->settlement_status);

            $msg = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('message_type', WhatsAppMessage::TYPE_MERCHANT_SETTLEMENT)
                ->first();

            $this->assertNotNull($msg, 'Payment settlement alert must be queued on settled reconciliation');
            $this->assertTrue($msg->isSent());
            $this->assertStringContainsString('midtrans-settle-9988', $msg->payload['summary']);
            $this->assertStringContainsString('Rp 250.000', $msg->payload['summary']);
        });
    }

    public function test_whatsapp_failure_never_rolls_back_shift_or_pos_or_inventory(): void
    {
        $this->runForTenant(function () {
            // Deactivate connection to simulate WhatsApp provider outage
            $this->connection->update(['status' => TenantWhatsAppConnection::STATUS_INACTIVE]);

            // 1. Shift closing still succeeds
            $shift = Shift::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'opened_by_user_id' => $this->owner->id,
                'closed_by_user_id' => $this->owner->id,
                'starting_cash_minor' => 10000000,
                'expected_cash_minor' => 15000000,
                'closing_cash_minor' => 12000000,
                'variance_minor' => -3000000,
                'status' => Shift::STATUS_CLOSED,
                'opened_at' => now()->subHours(8),
                'closed_at' => now(),
            ]);

            event(new ShiftClosed($shift));
            $this->assertEquals(Shift::STATUS_CLOSED, $shift->fresh()->status);

            // 2. Booking creation still succeeds
            $table = Table::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'code' => 'T-FAIL-01',
                'name' => 'Meja Fail Safe',
                'capacity' => 2,
                'status' => Table::STATUS_AVAILABLE,
                'is_active' => true,
            ]);

            $booking = app(BookingService::class)->createBooking(
                $this->tenant,
                $this->outlet->id,
                [
                    'table_id' => $table->id,
                    'customer_id' => $this->customer->id,
                    'customer_name' => $this->customer->name,
                    'customer_contact' => $this->customer->phone,
                    'party_size' => 2,
                    'starts_at' => Carbon::now()->addHours(2)->toIso8601String(),
                    'status' => Booking::STATUS_CONFIRMED,
                ],
                $this->owner->id
            );

            $this->assertNotNull($booking);
            $this->assertEquals(Booking::STATUS_CONFIRMED, $booking->fresh()->status);

            // 3. Inventory adjustment still succeeds
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'code' => 'WH-FAIL',
                'name' => 'Gudang Fail',
                'type' => 'warehouse',
            ]);

            $item = InventoryItem::create([
                'tenant_id' => $this->tenant->id,
                'sku' => 'SKU-FAIL',
                'name' => 'Beras Pandan',
                'base_unit' => 'kg',
                'status' => 'active',
            ]);

            $itemLoc = InventoryItemLocation::create([
                'tenant_id' => $this->tenant->id,
                'inventory_item_id' => $item->id,
                'location_id' => $loc->id,
                'on_hand' => '50.0000',
                'reserved' => '0.0000',
                'reorder_level' => '10.0000',
            ]);

            app(InventoryService::class)->recordMovement(
                tenantId: $this->tenant->id,
                inventoryItemId: $item->id,
                locationId: $loc->id,
                movementType: 'adjustment_out',
                delta: -45.0,
                reason: 'Fail-safe stock test',
                userId: $this->owner->id
            );

            $this->assertEquals('5.0000', $itemLoc->fresh()->on_hand);
        });
    }
}
