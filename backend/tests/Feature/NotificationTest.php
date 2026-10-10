<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\PaymentFailed;
use App\Events\ShiftClosed;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class NotificationTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $manager;

    protected User $cashier;

    protected InventoryLocation $kitchenLocation;

    protected NotificationService $notificationService;

    protected InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Barokah Notif');
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manajer Siti', [$outlet->id]);
        $this->manager = $managerData['user'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Budi', [$outlet->id]);
        $this->cashier = $cashierData['user'];

        $this->notificationService = app(NotificationService::class);
        $this->inventoryService = app(InventoryService::class);

        $this->kitchenLocation = $this->runForTenant(function () use ($tenant, $outlet) {
            $loc = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'name' => 'Dapur Utama',
                'code' => 'DAP-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $outlet->update(['default_pos_location_id' => $loc->id]);

            return $loc;
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_low_stock_notification_creation_and_deduplication(): void
    {
        $tenant = $this->tenantData['tenant'];

        $item = $this->runForTenant(function () use ($tenant) {
            $invItem = InventoryItem::create([
                'tenant_id' => $tenant->id,
                'sku' => 'TELUR-AYAM',
                'name' => 'Telur Ayam',
                'item_type' => 'raw_material',
                'base_unit' => 'kg',
                'status' => 'active',
                'allow_negative_stock' => true,
            ]);

            // Set initial balance with reorder_level = 5.0, on_hand = 10.0
            $itemLoc = InventoryItemLocation::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $invItem->id,
                'location_id' => $this->kitchenLocation->id,
                'on_hand' => 10.0,
                'reserved' => 0.0,
                'reorder_level' => 5.0,
            ]);

            return $invItem;
        });

        // 1. Consume 6 kg (on_hand becomes 4.0 <= reorder_level 5.0) -> triggers low stock notification
        $this->runForTenant(function () use ($tenant, $item) {
            $this->inventoryService->recordMovement(
                tenantId: $tenant->id,
                inventoryItemId: $item->id,
                locationId: $this->kitchenLocation->id,
                movementType: 'sales_consumption',
                delta: -6.0,
                unit: 'kg',
            );

            // Assert exactly 1 notification created
            $notifications = Notification::where('tenant_id', $tenant->id)
                ->where('type', Notification::TYPE_LOW_STOCK)
                ->get();

            $this->assertCount(1, $notifications);
            $this->assertEquals(Notification::SEVERITY_WARNING, $notifications->first()->severity);
            $this->assertFalse($notifications->first()->isRead());

            // 2. Consume another 2 kg (on_hand becomes 2.0) -> re-evaluates low stock
            // Invariant: MUST NOT create duplicate notification (dedup_key protects it)
            $this->inventoryService->recordMovement(
                tenantId: $tenant->id,
                inventoryItemId: $item->id,
                locationId: $this->kitchenLocation->id,
                movementType: 'sales_consumption',
                delta: -2.0,
                unit: 'kg',
            );

            $this->assertCount(1, Notification::where('tenant_id', $tenant->id)->where('type', Notification::TYPE_LOW_STOCK)->get());
        });
    }

    public function test_low_stock_notification_resolves_when_stock_recovers(): void
    {
        $tenant = $this->tenantData['tenant'];

        $item = $this->runForTenant(function () use ($tenant) {
            $invItem = InventoryItem::create([
                'tenant_id' => $tenant->id,
                'sku' => 'MINYAK-GRG',
                'name' => 'Minyak Goreng',
                'item_type' => 'raw_material',
                'base_unit' => 'liter',
                'status' => 'active',
                'allow_negative_stock' => true,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $invItem->id,
                'location_id' => $this->kitchenLocation->id,
                'on_hand' => 4.0, // Initial on hand <= reorder level 10.0
                'reserved' => 0.0,
                'reorder_level' => 10.0,
            ]);

            // Create initial low stock alert
            $this->notificationService->createNotification($tenant->id, [
                'type' => Notification::TYPE_LOW_STOCK,
                'title' => 'Stok Menipis',
                'message' => 'Minyak goreng menipis.',
                'severity' => Notification::SEVERITY_WARNING,
                'dedup_key' => "low_stock:item_{$invItem->id}:loc_{$this->kitchenLocation->id}",
            ]);

            return $invItem;
        });

        // Assert notification is initially unread
        $this->runForTenant(function () use ($tenant, $item) {
            $notif = Notification::where('tenant_id', $tenant->id)
                ->where('dedup_key', "low_stock:item_{$item->id}:loc_{$this->kitchenLocation->id}")
                ->first();
            $this->assertNotNull($notif);
            $this->assertFalse($notif->isRead());

            // Restock: +20 liters -> on_hand becomes 24.0 > 10.0
            $this->inventoryService->recordMovement(
                tenantId: $tenant->id,
                inventoryItemId: $item->id,
                locationId: $this->kitchenLocation->id,
                movementType: 'purchase_receipt',
                delta: 20.0,
                unit: 'liter',
            );

            // Assert notification is automatically marked as resolved (resolved_at is set, read_at remains distinct)
            $notifFresh = $notif->fresh();
            $this->assertTrue($notifFresh->isResolved());
            $this->assertFalse($notifFresh->isRead());
        });
    }

    public function test_low_stock_re_trigger_lifecycle_after_resolution(): void
    {
        $tenant = $this->tenantData['tenant'];

        $item = $this->runForTenant(function () use ($tenant) {
            $invItem = InventoryItem::create([
                'tenant_id' => $tenant->id,
                'sku' => 'GULA-PASIR',
                'name' => 'Gula Pasir',
                'item_type' => 'raw_material',
                'base_unit' => 'kg',
                'status' => 'active',
                'allow_negative_stock' => true,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $invItem->id,
                'location_id' => $this->kitchenLocation->id,
                'on_hand' => 10.0,
                'reserved' => 0.0,
                'reorder_level' => 5.0,
            ]);

            return $invItem;
        });

        $this->runForTenant(function () use ($tenant, $item) {
            // 1. Stock drops <= reorder_level -> active notification created
            $this->inventoryService->recordMovement(
                tenantId: $tenant->id,
                inventoryItemId: $item->id,
                locationId: $this->kitchenLocation->id,
                movementType: 'sales_consumption',
                delta: -6.0,
                unit: 'kg',
            );

            $notif1 = Notification::where('tenant_id', $tenant->id)
                ->where('dedup_key', "low_stock:item_{$item->id}:loc_{$this->kitchenLocation->id}")
                ->whereNull('resolved_at')
                ->first();
            $this->assertNotNull($notif1);

            // 2. Mark notification read
            $notif1->markAsRead();
            $this->assertTrue($notif1->fresh()->isRead());
            $this->assertFalse($notif1->fresh()->isResolved());

            // 3. Stock recovers > reorder_level -> notification resolved
            $this->inventoryService->recordMovement(
                tenantId: $tenant->id,
                inventoryItemId: $item->id,
                locationId: $this->kitchenLocation->id,
                movementType: 'purchase_receipt',
                delta: 10.0,
                unit: 'kg',
            );

            $this->assertTrue($notif1->fresh()->isResolved());

            // 4. Stock drops again <= reorder_level -> new active notification created!
            $this->inventoryService->recordMovement(
                tenantId: $tenant->id,
                inventoryItemId: $item->id,
                locationId: $this->kitchenLocation->id,
                movementType: 'sales_consumption',
                delta: -12.0,
                unit: 'kg',
            );

            $allNotifs = Notification::where('tenant_id', $tenant->id)
                ->where('dedup_key', "low_stock:item_{$item->id}:loc_{$this->kitchenLocation->id}")
                ->orderBy('created_at')
                ->get();

            // Total 2 historical notifications in audit trail
            $this->assertCount(2, $allNotifs);
            $this->assertTrue($allNotifs[0]->isResolved());
            $this->assertFalse($allNotifs[1]->isResolved()); // Active alert

            // 5. Event replay x3 does NOT create duplicates of the active alert
            $this->inventoryService->recordMovement(
                tenantId: $tenant->id,
                inventoryItemId: $item->id,
                locationId: $this->kitchenLocation->id,
                movementType: 'sales_consumption',
                delta: -1.0,
                unit: 'kg',
            );
            $this->assertCount(2, Notification::where('tenant_id', $tenant->id)
                ->where('dedup_key', "low_stock:item_{$item->id}:loc_{$this->kitchenLocation->id}")
                ->get());
        });
    }

    public function test_shift_closed_with_variance_creates_shift_variance_notification(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $this->runForTenant(function () use ($tenant, $outlet) {
            $shift = Shift::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'opened_by_user_id' => $this->cashier->id,
                'closed_by_user_id' => $this->cashier->id,
                'status' => Shift::STATUS_CLOSED,
                'opened_at' => now()->subHours(8),
                'closed_at' => now(),
                'opening_cash_minor' => 10000000,
                'expected_cash_minor' => 15000000,
                'closing_cash_minor' => 14000000, // Rp 10.000 shortage
                'variance_minor' => -1000000,
            ]);

            // Dispatch event 3 times to test replay idempotency
            ShiftClosed::dispatch($shift);
            ShiftClosed::dispatch($shift);
            ShiftClosed::dispatch($shift);

            $notifications = Notification::where('tenant_id', $tenant->id)
                ->where('type', Notification::TYPE_SHIFT_VARIANCE)
                ->get();

            $this->assertCount(1, $notifications);
            $notif = $notifications->first();
            $this->assertEquals(Notification::SEVERITY_WARNING, $notif->severity);
            $this->assertEquals(-1000000, $notif->data['variance']);
        });
    }

    public function test_payment_failed_event_creates_notification_and_replays_idempotently(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $this->runForTenant(function () use ($tenant, $outlet) {
            $sale = Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'reference_number' => 'SALE-FAIL-99',
                'status' => Sale::STATUS_OPEN,
                'subtotal_minor' => 7500000,
                'total_minor' => 7500000,
                'cashier_user_id' => $this->cashier->id,
            ]);

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-FAIL-99',
                'method' => Payment::METHOD_QRIS,
                'provider' => 'mock',
                'amount_minor' => 7500000,
                'currency' => 'IDR',
                'status' => Payment::STATUS_FAILED,
            ]);

            // Dispatch PaymentFailed 3 times
            PaymentFailed::dispatch($payment, 'QRIS expired');
            PaymentFailed::dispatch($payment, 'QRIS expired');
            PaymentFailed::dispatch($payment, 'QRIS expired');

            $notifications = Notification::where('tenant_id', $tenant->id)
                ->where('type', Notification::TYPE_PAYMENT_STATUS)
                ->get();

            $this->assertCount(1, $notifications);
            $notif = $notifications->first();
            $this->assertEquals('Pembayaran Gagal', $notif->title);
            $this->assertEquals('failed', $notif->data['status']);
            $this->assertEquals(Notification::SEVERITY_CRITICAL, $notif->severity);
        });
    }

    public function test_notification_api_endpoints_index_unread_count_and_mark_read(): void
    {
        $tenant = $this->tenantData['tenant'];

        $n1 = null;
        $n2 = null;
        $this->runForTenant(function () use ($tenant, &$n1, &$n2) {
            $n1 = Notification::create([
                'tenant_id' => $tenant->id,
                'type' => Notification::TYPE_LOW_STOCK,
                'title' => 'Stok Cabai Rawit',
                'message' => 'Cabai rawit menipis',
                'severity' => Notification::SEVERITY_WARNING,
            ]);

            $n2 = Notification::create([
                'tenant_id' => $tenant->id,
                'type' => Notification::TYPE_SHIFT_VARIANCE,
                'title' => 'Selisih Kas',
                'message' => 'Terdapat selisih kas',
                'severity' => Notification::SEVERITY_WARNING,
            ]);
        });

        // 1. Check unread count API
        $resCount = $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson('/api/v1/notifications/unread-count');
        $resCount->assertStatus(200)
            ->assertJsonPath('data.unread_count', 2);

        // 2. Index API
        $resIndex = $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson('/api/v1/notifications');
        $resIndex->assertStatus(200)
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonCount(2, 'data');

        // 3. Mark single notification as read
        $resRead = $this->authenticateTenantUser($this->manager, $tenant)
            ->postJson("/api/v1/notifications/{$n1->id}/read");
        $resRead->assertStatus(200);

        // Verify count decreases to 1
        $resCountAfter = $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson('/api/v1/notifications/unread-count');
        $resCountAfter->assertJsonPath('data.unread_count', 1);

        // 4. Mark all read
        $resAllRead = $this->authenticateTenantUser($this->manager, $tenant)
            ->postJson('/api/v1/notifications/read-all');
        $resAllRead->assertStatus(200);

        $resCountZero = $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson('/api/v1/notifications/unread-count');
        $resCountZero->assertJsonPath('data.unread_count', 0);
    }

    public function test_tenant_isolation_on_notifications(): void
    {
        $tenant1 = $this->tenantData['tenant'];
        $tenant2Data = $this->createTenant('Waroeng Sebelah 2');
        $tenant2 = $tenant2Data['tenant'];
        $owner2 = $tenant2Data['owner'];

        $notif1 = $this->runForTenant(function () use ($tenant1) {
            return Notification::create([
                'tenant_id' => $tenant1->id,
                'type' => Notification::TYPE_LOW_STOCK,
                'title' => 'Tenant 1 Alert',
                'message' => 'Private Tenant 1 stock alert',
                'severity' => Notification::SEVERITY_WARNING,
            ]);
        });

        // Tenant 2 attempts to view notifications -> must receive 0
        $resIndex = $this->authenticateTenantUser($owner2, $tenant2)
            ->getJson('/api/v1/notifications');
        $resIndex->assertStatus(200)->assertJsonCount(0, 'data');

        // Tenant 2 attempts to mark Tenant 1's notification as read -> 404 (not found under Tenant 2 due to RLS)
        $resMark = $this->authenticateTenantUser($owner2, $tenant2)
            ->postJson("/api/v1/notifications/{$notif1->id}/read");
        $resMark->assertStatus(404);
    }
}
