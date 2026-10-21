<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\WhatsAppBusinessQueryService;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

class WhatsAppBusinessQueryTest extends TenantTestCase
{
    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected WhatsAppBusinessQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);

        $tenantData = $this->createTenant('Waroeng Makmur Jaya');
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
        });

        $this->queryService = app(WhatsAppBusinessQueryService::class);
    }

    protected function runForTenant(callable $callback, ?Tenant $tenant = null): mixed
    {
        return app(TenantManager::class)->runForTenant($tenant ?? $this->tenant, $callback);
    }

    public function test_business_query_daily_sales(): void
    {
        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-QRY-01',
                'receipt_number' => 'REC-QRY-01',
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
                'reference_number' => 'REF-QRY-02',
                'receipt_number' => 'REC-QRY-02',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 2500000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 2500000,
                'finalized_at' => now(),
            ]);

            $result = $this->queryService->executeQuery($this->tenant, 'Berapa omzet hari ini?');

            $this->assertEquals('daily_sales', $result['intent']);
            $this->assertTrue($result['success']);
            $this->assertStringContainsString('Omzet Hari Ini', $result['reply_text']);
            $this->assertStringContainsString('Rp 75.000', $result['reply_text']);
            $this->assertEquals(7500000, $result['data']['net_sales_minor']);
            $this->assertEquals(2, $result['data']['transaction_count']);
        });
    }

    public function test_business_query_top_products(): void
    {
        $this->runForTenant(function () {
            $result = $this->queryService->executeQuery($this->tenant, 'Produk paling laku minggu ini?');

            $this->assertEquals('top_products', $result['intent']);
            $this->assertTrue($result['success']);
            $this->assertNotNull($result['reply_text']);
        });
    }

    public function test_business_query_inventory_stock(): void
    {
        $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'code' => 'LOC-01',
                'name' => 'Gudang Pusat',
                'type' => 'warehouse',
            ]);

            $item = InventoryItem::create([
                'tenant_id' => $this->tenant->id,
                'sku' => 'SKU-GULA',
                'name' => 'Gula Pasir',
                'base_unit' => 'kg',
                'status' => 'active',
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $this->tenant->id,
                'inventory_item_id' => $item->id,
                'location_id' => $loc->id,
                'on_hand' => '25.0000',
                'reserved' => '0.0000',
                'reorder_level' => '10.0000',
            ]);

            $result = $this->queryService->executeQuery($this->tenant, 'Stok Gula cukup sampai besok?');

            $this->assertEquals('inventory_stock', $result['intent']);
            $this->assertTrue($result['success']);
            $this->assertStringContainsString('Cek Stok', $result['reply_text']);
            $this->assertStringContainsString('Gula Pasir', $result['reply_text']);
            $this->assertStringContainsString('Aman', $result['reply_text']);
        });
    }

    public function test_business_query_unrecognized_safe_fallback(): void
    {
        $this->runForTenant(function () {
            $result = $this->queryService->executeQuery($this->tenant, 'Berapa modal awal buka warung?');

            $this->assertEquals('unsupported_query', $result['intent']);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('Pertanyaan belum dikenali', $result['reply_text']);
            $this->assertStringContainsString('Omzet hari ini', $result['reply_text']);
            $this->assertStringContainsString('Produk paling laku', $result['reply_text']);
            $this->assertStringContainsString('Stok [nama item]', $result['reply_text']);
        });
    }

    public function test_business_query_multi_tenant_isolation(): void
    {
        $tenantBData = $this->createTenant('Tenant B Food');
        $tenantB = $tenantBData['tenant'];

        // Create sale under Tenant A
        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-A-999',
                'receipt_number' => 'REC-A-999',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 10000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 10000000,
                'finalized_at' => now(),
            ]);
        });

        $this->runForTenant(function () use ($tenantB) {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $tenantB->id)->update(['plan_id' => $businessPlan->id]);
        }, $tenantB);
        app(SubscriptionEntitlementService::class)->clearCache($tenantB);

        // Querying from Tenant B must show 0 sales, not Tenant A's 10.000.000
        $resultB = $this->queryService->executeQuery($tenantB, 'Omzet hari ini?');
        $this->assertEquals(0, $resultB['data']['net_sales_minor']);
        $this->assertEquals(0, $resultB['data']['transaction_count']);
    }

    public function test_business_query_api_endpoint(): void
    {
        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-API-01',
                'receipt_number' => 'REC-API-01',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 3000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 3000000,
                'finalized_at' => now(),
            ]);
        });

        Sanctum::actingAs($this->owner);

        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenant->id,
        ])->postJson('/api/v1/whatsapp/query', [
            'query' => 'Omzet hari ini?',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.intent', 'daily_sales')
            ->assertJsonPath('data.data.net_sales_minor', 3000000);
    }

    public function test_inbound_webhook_natural_language_query_from_authorized_merchant(): void
    {
        // 1. Setup sales and merchant settings
        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-INB-01',
                'receipt_number' => 'REC-INB-01',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 7500000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 7500000,
                'finalized_at' => now(),
            ]);

            $this->tenant->update([
                'phone' => '081234567890',
                'settings' => [
                    'whatsapp_notifications' => [
                        'consent_given' => true,
                        'merchant_phone' => '081234567890',
                    ],
                ],
            ]);
            $this->owner->update(['phone_number' => '081234567890']);
        });

        // 2. Simulate inbound WhatsApp message webhook from authorized owner phone
        $response = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenant->id.'/mock', [
            'messages' => [
                [
                    'id' => 'wamid.INBOUND_QUERY_001',
                    'from' => '081234567890',
                    'type' => 'text',
                    'text' => [
                        'body' => 'Omzet hari ini?',
                    ],
                ],
            ],
        ], [
            'X-Mock-Signature' => 'mock_signature',
        ]);

        $response->assertStatus(200);

        // 3. Verify reply message was queued in outbox
        $this->runForTenant(function () {
            $reply = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('idempotency_key', 'whatsapp:inbound_reply:wamid.INBOUND_QUERY_001')
                ->first();

            $this->assertNotNull($reply);
            $this->assertEquals(WhatsAppMessage::TYPE_MERCHANT_QUERY_REPLY, $reply->message_type);
            $this->assertStringContainsString('75.000', $reply->payload['body']);
            $this->assertEquals('daily_sales', $reply->payload['intent']);
        });
    }

    public function test_inbound_webhook_rejects_unauthorized_sender(): void
    {
        $this->runForTenant(function () {
            $this->tenant->update([
                'phone' => '081234567890',
                'settings' => [
                    'whatsapp_notifications' => [
                        'consent_given' => true,
                        'merchant_phone' => '081234567890',
                    ],
                ],
            ]);
            $this->owner->update(['phone_number' => '081234567890']);
        });

        // Unknown/unauthorized sender phone number
        $response = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenant->id.'/mock', [
            'messages' => [
                [
                    'id' => 'wamid.UNAUTHORIZED_001',
                    'from' => '089999999999',
                    'type' => 'text',
                    'text' => [
                        'body' => 'Omzet hari ini?',
                    ],
                ],
            ],
        ], [
            'X-Mock-Signature' => 'mock_signature',
        ]);

        $response->assertStatus(200);

        // No reply message created in outbox for unauthorized sender!
        $this->runForTenant(function () {
            $reply = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('idempotency_key', 'whatsapp:inbound_reply:wamid.UNAUTHORIZED_001')
                ->first();

            $this->assertNull($reply);
        });
    }

    public function test_inbound_webhook_keyword_opt_out_for_customer(): void
    {
        /** @var Customer $customer */
        $customer = $this->runForTenant(function () {
            return Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Pelanggan Setia',
                'phone' => '081299998888',
                'whatsapp_opt_in' => true,
                'marketing_opt_in' => true,
            ]);
        });

        $this->assertTrue($customer->whatsapp_opt_in);

        // Customer sends 'STOP' keyword
        $response = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenant->id.'/mock', [
            'messages' => [
                [
                    'id' => 'wamid.STOP_KEYWORD_01',
                    'from' => '081299998888',
                    'type' => 'text',
                    'text' => [
                        'body' => 'STOP',
                    ],
                ],
            ],
        ], [
            'X-Mock-Signature' => 'mock_signature',
        ]);

        $response->assertStatus(200);

        // Customer is now opted out
        $this->runForTenant(function () use ($customer) {
            $customer->refresh();
            $this->assertFalse($customer->whatsapp_opt_in);
            $this->assertTrue($customer->whatsapp_opted_out);
            $this->assertNotNull($customer->whatsapp_opted_out_at);
        });
    }

    public function test_inbound_webhook_duplicate_message_does_not_duplicate_reply(): void
    {
        $this->runForTenant(function () {
            $this->tenant->update([
                'phone' => '081234567890',
                'settings' => [
                    'whatsapp_notifications' => [
                        'consent_given' => true,
                        'merchant_phone' => '081234567890',
                    ],
                ],
            ]);
            $this->owner->update(['phone_number' => '081234567890']);
        });

        $payload = [
            'messages' => [
                [
                    'id' => 'wamid.DEDUP_TEST_001',
                    'from' => '081234567890',
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'Omzet hari ini?'],
                ],
            ],
        ];

        // First delivery
        $res1 = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenant->id.'/mock', $payload, [
            'X-Mock-Signature' => 'mock_signature',
        ]);
        $res1->assertStatus(200);

        // Duplicate delivery
        $res2 = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenant->id.'/mock', $payload, [
            'X-Mock-Signature' => 'mock_signature',
        ]);
        $res2->assertStatus(200);

        // Only exactly 1 reply created
        $count = $this->runForTenant(function () {
            return WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('idempotency_key', 'whatsapp:inbound_reply:wamid.DEDUP_TEST_001')
                ->count();
        });

        $this->assertEquals(1, $count);
    }

    public function test_inbound_reply_delayed_past_24_hours_fails_with_expired_service_window(): void
    {
        $this->runForTenant(function () {
            $this->tenant->update([
                'phone' => '081234567890',
                'settings' => [
                    'whatsapp_notifications' => [
                        'consent_given' => true,
                        'merchant_phone' => '081234567890',
                    ],
                ],
            ]);
            $this->owner->update(['phone_number' => '081234567890']);
        });

        // Simulate inbound webhook received 25 hours ago
        $pastTimestamp = now()->subHours(25)->timestamp;
        $response = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenant->id.'/mock', [
            'messages' => [
                [
                    'id' => 'wamid.WINDOW_EXP_001',
                    'from' => '081234567890',
                    'timestamp' => (string) $pastTimestamp,
                    'type' => 'text',
                    'text' => ['body' => 'Omzet hari ini?'],
                ],
            ],
        ], [
            'X-Mock-Signature' => 'mock_signature',
        ]);
        $response->assertStatus(200);

        // Worker executes job now (after 25 hours have passed)
        $reply = $this->runForTenant(function () {
            return WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('idempotency_key', 'whatsapp:inbound_reply:wamid.WINDOW_EXP_001')
                ->first();
        });

        $this->assertNotNull($reply);
        $job = new SendWhatsAppMessageJob($reply->id);
        $job->handle(
            app(WhatsAppNotificationService::class),
            app(WhatsAppProviderManager::class),
            app(SubscriptionEntitlementService::class),
            app(TenantManager::class)
        );

        $freshReply = $this->runForTenant(fn () => $reply->fresh());
        $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $freshReply->status);
        $this->assertEquals('EXPIRED_SERVICE_WINDOW', $freshReply->error_code);
    }

    public function test_manager_scoped_to_assigned_outlet_cannot_query_other_outlet(): void
    {
        $outletB = $this->runForTenant(function () {
            return Outlet::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Outlet Cabang Barat',
                'code' => 'OUT-BARAT',
                'status' => 'active',
            ]);
        });

        // Create manager user assigned ONLY to outlet A
        $managerUser = User::create([
            'name' => 'Manager Outlet A',
            'email' => 'manager.outleta@example.com',
            'phone_number' => '081255556666',
            'password' => bcrypt('password'),
        ]);

        $managerRole = Role::where('slug', 'manager')->first();
        UserTenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $managerUser->id,
            'role_id' => $managerRole->id,
            'status' => 'active',
            'assigned_outlet_ids' => [$this->outlet->id], // ONLY outlet A
        ]);

        // Manager asks for Outlet Cabang Barat data
        $result = $this->queryService->executeQuery($this->tenant, 'Omzet outlet Barat hari ini?', $managerUser);

        $this->assertFalse($result['success']);
        $this->assertEquals('unauthorized_outlet', $result['intent']);
        $this->assertStringContainsString('Akses ditolak', $result['reply_text']);
        $this->assertStringContainsString('Bantuan & Eskalasi', $result['reply_text']);
    }

    public function test_manager_receives_only_assigned_outlet_metrics(): void
    {
        $outletB = $this->runForTenant(function () {
            return Outlet::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Outlet Timur',
                'code' => 'OUT-TIMUR',
                'status' => 'active',
            ]);
        });

        // Seed sale in Outlet A (50.000)
        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-OA-01',
                'receipt_number' => 'REC-OA-01',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 5000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 5000000,
                'finalized_at' => now(),
            ]);
        });

        // Seed sale in Outlet B (100.000)
        $this->runForTenant(function () use ($outletB) {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $outletB->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'REF-OB-01',
                'receipt_number' => 'REC-OB-01',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 10000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 10000000,
                'finalized_at' => now(),
            ]);
        });

        // Create Manager assigned ONLY to Outlet A
        $managerUser = User::create([
            'name' => 'Manager A Only',
            'email' => 'managera.only@example.com',
            'phone_number' => '081277778888',
            'password' => bcrypt('password'),
        ]);

        $managerRole = Role::where('slug', 'manager')->first();
        UserTenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $managerUser->id,
            'role_id' => $managerRole->id,
            'status' => 'active',
            'assigned_outlet_ids' => [$this->outlet->id],
        ]);

        // General query "Omzet hari ini?" from Manager A
        $result = $this->queryService->executeQuery($this->tenant, 'Omzet hari ini?', $managerUser);

        $this->assertTrue($result['success']);
        // Manager A must ONLY see Rp 50.000 from Outlet A, not Rp 150.000
        $this->assertEquals(5000000, $result['data']['net_sales_minor']);
        $this->assertStringContainsString('50.000', $result['reply_text']);
        $this->assertStringNotContainsString('150.000', $result['reply_text']);

        // Owner query sees full tenant total (150.000)
        $ownerResult = $this->queryService->executeQuery($this->tenant, 'Omzet hari ini?', $this->owner);
        $this->assertTrue($ownerResult['success']);
        $this->assertEquals(15000000, $ownerResult['data']['net_sales_minor']);
        $this->assertStringContainsString('150.000', $ownerResult['reply_text']);
    }

    public function test_inbound_query_includes_human_escalation_path(): void
    {
        config(['services.whatsapp.support_contact' => 'cs@sewaroeng.id']);

        $result = $this->queryService->executeQuery($this->tenant, 'Pertanyaan acak yang tidak jelas?');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Bantuan & Eskalasi', $result['reply_text']);
        $this->assertStringContainsString('cs@sewaroeng.id', $result['reply_text']);
    }

    public function test_escalation_footer_with_valid_configured_phone(): void
    {
        config(['services.whatsapp.support_contact' => '+6281234567899']);

        $footer = $this->queryService->getEscalationFooter();

        $this->assertStringContainsString('Bantuan & Eskalasi', $footer);
        $this->assertStringContainsString('+6281234567899', $footer);
    }

    public function test_escalation_footer_with_empty_contact_uses_safe_fallback(): void
    {
        config(['services.whatsapp.support_contact' => null]);

        $footer = $this->queryService->getEscalationFooter();

        $this->assertStringContainsString('Bantuan & Eskalasi', $footer);
        $this->assertStringContainsString('Hubungi staf atau admin outlet bisnis secara langsung', $footer);
        $this->assertStringNotContainsString('@', $footer);
    }

    public function test_escalation_footer_with_invalid_contact_rejects_and_uses_safe_fallback(): void
    {
        config(['services.whatsapp.support_contact' => 'not_a_valid_email_or_phone_or_url']);

        $footer = $this->queryService->getEscalationFooter();

        $this->assertStringContainsString('Bantuan & Eskalasi', $footer);
        $this->assertStringContainsString('Hubungi staf atau admin outlet bisnis secara langsung', $footer);
        $this->assertStringNotContainsString('not_a_valid_email_or_phone_or_url', $footer);
    }

    public function test_forecasting_query_returns_projected_sales_and_model_version(): void
    {
        $this->runForTenant(function () {
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'SALE-FC-1',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 10000000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'service_charge_minor' => 0,
                'rounding_minor' => 0,
                'total_minor' => 10000000,
                'finalized_at' => now()->subDays(2),
            ]);
            Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->owner->id,
                'reference_number' => 'SALE-FC-2',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 15000000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'service_charge_minor' => 0,
                'rounding_minor' => 0,
                'total_minor' => 15000000,
                'finalized_at' => now()->subDays(1),
            ]);
        });

        $result = $this->queryService->executeQuery($this->tenant, 'Prediksi penjualan minggu depan?', $this->owner);

        $this->assertTrue($result['success']);
        $this->assertEquals('sales_forecasting', $result['intent']);
        $this->assertEquals('sewaroeng-linear-trend-v1.0', $result['data']['model_version']);
        $this->assertEquals('7_days', $result['data']['horizon']);
        $this->assertEquals(88, $result['data']['confidence_percentage']);
        $this->assertStringContainsString('Proyeksi & Prediksi Penjualan', $result['reply_text']);
        $this->assertStringContainsString('Horizon: 7 hari ke depan', $result['reply_text']);
    }

    public function test_anomaly_detection_query_returns_safe_scan_results(): void
    {
        $result = $this->queryService->executeQuery($this->tenant, 'Cek anomali hari ini?', $this->owner);

        $this->assertTrue($result['success']);
        $this->assertEquals('anomaly_detection', $result['intent']);
        $this->assertArrayHasKey('has_anomalies', $result['data']);
        $this->assertStringContainsString('Pemindaian Anomali Operasional', $result['reply_text']);
    }
}
