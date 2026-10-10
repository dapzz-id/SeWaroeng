<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\JournalEntry;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\PaymentRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\Reconciliation\PaymentReconciliationService;
use App\Services\Payment\Reconciliation\ProviderEvidence;
use App\Services\PosService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class PaymentReconciliationTest extends TenantTestCase
{
    protected array $tenantData;

    protected Outlet $outlet1;

    protected Outlet $outlet2;

    protected User $owner;

    protected array $managerOutlet1;

    protected array $cashierOutlet1;

    protected ProductVariant $variant;

    protected InventoryLocation $location1;

    protected InventoryLocation $location2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Rekonsiliasi');
        $this->outlet1 = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $this->location1 = app(TenantManager::class)->runForTenant($this->tenantData['tenant'], function () {
            return InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet1->id,
                'name' => 'Gudang Cabang 1',
                'code' => 'LOC-CB1',
                'type' => 'store',
                'is_active' => true,
            ]);
        });

        // Create Outlet 2
        $this->outlet2 = app(TenantManager::class)->runForTenant($this->tenantData['tenant'], function () {
            $o = Outlet::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Waroeng Cabang 2',
                'code' => 'OUT-RECON-02',
                'is_active' => true,
            ]);

            $this->location2 = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $o->id,
                'name' => 'Gudang Cabang 2',
                'code' => 'LOC-CB2',
                'type' => 'store',
                'is_active' => true,
            ]);

            return $o;
        });

        // Manager assigned only to Outlet 1
        $this->managerOutlet1 = $this->addMemberToTenant(
            $this->tenantData['tenant'],
            'manager',
            'Manajer Outlet 1',
            [(string) $this->outlet1->id]
        );

        // Cashier assigned only to Outlet 1
        $this->cashierOutlet1 = $this->addMemberToTenant(
            $this->tenantData['tenant'],
            'cashier',
            'Kasir Outlet 1',
            [(string) $this->outlet1->id]
        );

        // Create Product & Variant
        $this->variant = app(TenantManager::class)->runForTenant($this->tenantData['tenant'], function () {
            $cat = Category::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Makanan Utama',
                'slug' => 'makanan-utama-'.Str::random(5),
            ]);

            $prod = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $cat->id,
                'name' => 'Nasi Rawon Spesial',
                'slug' => 'nasi-rawon-'.Str::random(5),
                'is_active' => true,
            ]);

            $var = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $prod->id,
                'name' => 'Porsi Komplit',
                'sku' => 'RWN-01',
                'is_default' => true,
                'is_active' => true,
            ]);

            $var->prices()->create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'amount' => 50000,
                'currency' => 'IDR',
                'effective_from' => now()->subDay(),
                'is_active' => true,
            ]);

            $invItem = InventoryItem::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_variant_id' => $var->id,
                'name' => 'Stok Rawon',
                'sku' => 'STK-RWN-01',
                'unit' => 'porsi',
            ]);

            $invItem->itemLocations()->create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'location_id' => $this->location1->id,
                'on_hand' => 100,
                'reserved' => 0,
            ]);

            $invItem->itemLocations()->create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'location_id' => $this->location2->id,
                'on_hand' => 100,
                'reserved' => 0,
            ]);

            return $var;
        });
    }

    protected function runForTenant(callable $callback, ?Tenant $tenant = null): mixed
    {
        return app(TenantManager::class)->runForTenant($tenant ?? $this->tenantData['tenant'], $callback);
    }

    private function createSaleWithPayment(Outlet $outlet, int $amountMinor, string $status = Payment::STATUS_SUCCEEDED, string $providerPaymentId = 'MIDTRANS-PROV-101'): array
    {
        return $this->runForTenant(function () use ($outlet, $amountMinor, $status, $providerPaymentId) {
            $actingUser = $outlet->id === $this->outlet2->id
                ? $this->owner
                : $this->cashierOutlet1['user'];

            $posService = app(PosService::class);
            $sale = $posService->createSale([
                'outlet_id' => $outlet->id,
                'items' => [
                    ['product_variant_id' => $this->variant->id, 'quantity' => 1],
                ],
            ], $actingUser->id);

            $sale = $posService->finalizeSale($sale, [], $actingUser->id);

            $payment = Payment::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
                'provider_payment_id' => $providerPaymentId,
                'amount_minor' => $amountMinor,
                'currency' => 'IDR',
                'status' => $status,
                'succeeded_at' => $status === Payment::STATUS_SUCCEEDED ? now() : null,
                'failed_at' => $status === Payment::STATUS_FAILED ? now() : null,
                'cancelled_at' => $status === Payment::STATUS_CANCELLED ? now() : null,
            ]);

            return ['sale' => $sale, 'payment' => $payment];
        });
    }

    public function test_successful_match_when_provider_reference_and_amount_align(): void
    {
        $setup = $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-REF-001');
        $payment = $setup['payment'];

        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'MIDTRANS-REF-001',
            amountMinor: 50000,
            feeMinor: 1000,
            currency: 'IDR',
            channel: 'qris',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);
        $recon = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);

        $this->assertEquals(PaymentReconciliation::STATUS_MATCHED, $recon->reconciliation_status);
        $this->assertEquals(PaymentReconciliation::SETTLEMENT_SETTLED, $recon->settlement_status);
        $this->assertEquals($payment->id, $recon->payment_id);
        $this->assertEquals($this->outlet1->id, $recon->outlet_id);
        $this->assertEquals(50000, $recon->expected_amount_minor);
        $this->assertEquals(50000, $recon->provider_amount_minor);
        $this->assertEquals(1000, $recon->fee_amount_minor);
        $this->assertEquals(49000, $recon->net_amount_minor);
        $this->assertNull($recon->mismatch_reason);
    }

    public function test_unmatched_when_internal_payment_not_found(): void
    {
        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'GHOST-REF-999999',
            amountMinor: 75000,
            currency: 'IDR',
            channel: 'qris',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);
        $recon = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);

        $this->assertEquals(PaymentReconciliation::STATUS_UNMATCHED, $recon->reconciliation_status);
        $this->assertEquals(PaymentReconciliation::MISMATCH_PAYMENT_NOT_FOUND, $recon->mismatch_reason);
        $this->assertNull($recon->payment_id);
        $this->assertNull($recon->expected_amount_minor);
        $this->assertEquals(75000, $recon->provider_amount_minor);
    }

    public function test_mismatched_when_amount_differs(): void
    {
        $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-AMOUNT-MISMATCH');

        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'MIDTRANS-AMOUNT-MISMATCH',
            amountMinor: 40000, // Provider reports 40k instead of 50k
            currency: 'IDR',
            channel: 'qris',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);
        $recon = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);

        $this->assertEquals(PaymentReconciliation::STATUS_MISMATCHED, $recon->reconciliation_status);
        $this->assertEquals(PaymentReconciliation::MISMATCH_AMOUNT, $recon->mismatch_reason);
        $this->assertEquals(50000, $recon->expected_amount_minor);
        $this->assertEquals(40000, $recon->provider_amount_minor);
    }

    public function test_mismatched_when_currency_is_invalid(): void
    {
        $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-CURRENCY-MISMATCH');

        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'MIDTRANS-CURRENCY-MISMATCH',
            amountMinor: 50000,
            currency: 'USD', // Invalid foreign currency
            channel: 'qris',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);
        $recon = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);

        $this->assertEquals(PaymentReconciliation::STATUS_MISMATCHED, $recon->reconciliation_status);
        $this->assertEquals(PaymentReconciliation::MISMATCH_CURRENCY, $recon->mismatch_reason);
    }

    public function test_mismatched_when_status_conflicts(): void
    {
        // Payment failed internally, but provider claims settled
        $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_FAILED, 'MIDTRANS-STATUS-CONFLICT');

        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'MIDTRANS-STATUS-CONFLICT',
            amountMinor: 50000,
            currency: 'IDR',
            channel: 'qris',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);
        $recon = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);

        $this->assertEquals(PaymentReconciliation::STATUS_MISMATCHED, $recon->reconciliation_status);
        $this->assertEquals(PaymentReconciliation::MISMATCH_STATUS_CONFLICT, $recon->mismatch_reason);
    }

    public function test_idempotency_repeated_reconciliation_does_not_duplicate_records(): void
    {
        $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-IDEM-001');

        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'MIDTRANS-IDEM-001',
            amountMinor: 50000,
            currency: 'IDR',
            channel: 'qris',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);

        $recon1 = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);
        $recon2 = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);

        $this->assertEquals($recon1->id, $recon2->id);

        $totalCount = $this->runForTenant(function () {
            return PaymentReconciliation::where('tenant_id', $this->tenantData['tenant']->id)
                ->where('provider', 'midtrans')
                ->where('provider_reference', 'MIDTRANS-IDEM-001')
                ->count();
        });

        $this->assertEquals(1, $totalCount);
    }

    public function test_batch_import_handles_valid_and_invalid_rows_safely(): void
    {
        $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-BATCH-MATCH');
        $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-BATCH-MISMATCH');

        $items = [
            [
                'provider' => 'midtrans',
                'provider_reference' => 'MIDTRANS-BATCH-MATCH',
                'provider_amount_minor' => 50000,
                'currency' => 'IDR',
                'settlement_status' => 'settled',
            ],
            [
                'provider' => 'midtrans',
                'provider_reference' => 'MIDTRANS-BATCH-MISMATCH',
                'provider_amount_minor' => 30000,
                'currency' => 'IDR',
                'settlement_status' => 'settled',
            ],
            [
                'provider' => 'midtrans',
                'provider_reference' => 'MIDTRANS-BATCH-UNMATCHED',
                'provider_amount_minor' => 15000,
                'currency' => 'IDR',
                'settlement_status' => 'settled',
            ],
            [
                // Malformed row missing provider_reference
                'provider' => 'midtrans',
                'provider_amount_minor' => 10000,
            ],
        ];

        $response = $this->authenticateTenantUser($this->owner, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/payment-reconciliation/import', [
                'outlet_id' => $this->outlet1->id,
                'items' => $items,
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(4, $data['total_processed']);
        $this->assertEquals(1, $data['matched_count']);
        $this->assertEquals(1, $data['mismatched_count']);
        $this->assertEquals(1, $data['unmatched_count']);
        $this->assertCount(1, $data['errors']);
    }

    public function test_manual_resolution_of_discrepancy_with_audit_trail(): void
    {
        $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-RESOLVE-TEST');

        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'MIDTRANS-RESOLVE-TEST',
            amountMinor: 45000,
            currency: 'IDR',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);
        $recon = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);
        $this->assertEquals(PaymentReconciliation::STATUS_MISMATCHED, $recon->reconciliation_status);

        // Resolve discrepancy via API
        $response = $this->authenticateTenantUser($this->managerOutlet1['user'], $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/payment-reconciliation/{$recon->id}/resolve", [
                'resolution_notes' => 'Selisih Rp 5.000 telah diverifikasi sebagai biaya gateway promosi.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.reconciliation_status', 'resolved');
        $response->assertJsonPath('data.resolution_notes', 'Selisih Rp 5.000 telah diverifikasi sebagai biaya gateway promosi.');

        // Re-running reconciliation does not overwrite 'resolved' state
        $reRecon = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);
        $this->assertEquals(PaymentReconciliation::STATUS_RESOLVED, $reRecon->reconciliation_status);

        // Verify AuditLog recorded
        $auditExists = AuditLog::where('tenant_id', $this->tenantData['tenant']->id)
            ->where('action', 'payment_reconciliation_resolved')
            ->where('entity_id', $recon->id)
            ->exists();
        $this->assertTrue($auditExists);
    }

    public function test_accounting_integrity_reconciliation_never_duplicates_journal_entries(): void
    {
        $setup = $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-ACCOUNTING-CHECK');
        $sale = $setup['sale'];

        $initialJournalCount = JournalEntry::where('tenant_id', $this->tenantData['tenant']->id)
            ->where('source_type', JournalEntry::SOURCE_SALE)
            ->where('source_id', $sale->id)
            ->count();

        // Ingest reconciliation
        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'MIDTRANS-ACCOUNTING-CHECK',
            amountMinor: 50000,
            currency: 'IDR',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);
        $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);

        $afterJournalCount = JournalEntry::where('tenant_id', $this->tenantData['tenant']->id)
            ->where('source_type', JournalEntry::SOURCE_SALE)
            ->where('source_id', $sale->id)
            ->count();

        // Reconciliation MUST NOT duplicate journal entries!
        $this->assertEquals($initialJournalCount, $afterJournalCount);
    }

    public function test_multi_tenant_isolation_prevents_cross_tenant_access(): void
    {
        $tenantB = $this->createTenant('Tenant Rival');
        $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-TENANT-A-01');

        $evidence = new ProviderEvidence(
            provider: 'midtrans',
            providerReference: 'MIDTRANS-TENANT-A-01',
            amountMinor: 50000,
            currency: 'IDR',
            settlementStatus: 'settled',
        );

        $service = app(PaymentReconciliationService::class);
        $reconA = $service->reconcileEvidence($this->tenantData['tenant']->id, $evidence, $this->owner->id);

        // Tenant B owner attempts to view Tenant A's reconciliation record
        $response = $this->authenticateTenantUser($tenantB['owner'], $tenantB['tenant'])
            ->getJson("/api/v1/payment-reconciliation/{$reconA->id}");

        $response->assertStatus(404);
    }

    public function test_multi_outlet_authorization_matrix(): void
    {
        $setup1 = $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-OUTLET1');
        $setup2 = $this->createSaleWithPayment($this->outlet2, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-OUTLET2');

        $service = app(PaymentReconciliationService::class);

        $recon1 = $service->reconcileEvidence(
            $this->tenantData['tenant']->id,
            new ProviderEvidence('midtrans', 'MIDTRANS-OUTLET1', 50000),
            $this->owner->id
        );

        $recon2 = $service->reconcileEvidence(
            $this->tenantData['tenant']->id,
            new ProviderEvidence('midtrans', 'MIDTRANS-OUTLET2', 50000),
            $this->owner->id
        );

        // 1. Owner can access tenant-wide summary and both outlets
        $ownerResp = $this->authenticateTenantUser($this->owner, $this->tenantData['tenant'])
            ->getJson('/api/v1/payment-reconciliation/summary');
        $ownerResp->assertStatus(200);
        $this->assertEquals(2, $ownerResp->json('data.total_records'));

        // 2. Manager assigned only to Outlet 1 can query summary for Outlet 1
        $managerResp1 = $this->authenticateTenantUser($this->managerOutlet1['user'], $this->tenantData['tenant'])
            ->getJson("/api/v1/payment-reconciliation/summary?outlet_id={$this->outlet1->id}");
        $managerResp1->assertStatus(200);
        $this->assertEquals(1, $managerResp1->json('data.total_records'));

        // 3. Manager assigned only to Outlet 1 requesting Outlet 2 receives 403 OUTLET_ACCESS_DENIED
        $managerResp2 = $this->authenticateTenantUser($this->managerOutlet1['user'], $this->tenantData['tenant'])
            ->getJson("/api/v1/payment-reconciliation/summary?outlet_id={$this->outlet2->id}");
        $managerResp2->assertStatus(403);
        $managerResp2->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // 4. Manager cannot access single record belonging to Outlet 2
        $detailBlocked = $this->authenticateTenantUser($this->managerOutlet1['user'], $this->tenantData['tenant'])
            ->getJson("/api/v1/payment-reconciliation/{$recon2->id}");
        $detailBlocked->assertStatus(403);
        $detailBlocked->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // 5. Manager CAN access single record belonging to Outlet 1
        $detailAllowed = $this->authenticateTenantUser($this->managerOutlet1['user'], $this->tenantData['tenant'])
            ->getJson("/api/v1/payment-reconciliation/{$recon1->id}");
        $detailAllowed->assertStatus(200);

        // 6. Cashier without payment.reconcile permission is rejected with 403 Forbidden
        $cashierBlocked = $this->authenticateTenantUser($this->cashierOutlet1['user'], $this->tenantData['tenant'])
            ->getJson('/api/v1/payment-reconciliation');
        $cashierBlocked->assertStatus(403);
    }

    public function test_split_payment_reconciliation_verifies_each_payment_independently(): void
    {
        // Sale split across two payments
        $posService = app(PosService::class);
        $sale = $this->runForTenant(function () use ($posService) {
            $s = $posService->createSale([
                'outlet_id' => $this->outlet1->id,
                'items' => [
                    ['product_variant_id' => $this->variant->id, 'quantity' => 2],
                ],
            ], $this->cashierOutlet1['user']->id);

            return $posService->finalizeSale($s, [], $this->cashierOutlet1['user']->id);
        });

        $this->runForTenant(function () use ($sale) {
            Payment::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-SPLIT-1',
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
                'provider_payment_id' => 'MIDTRANS-SPLIT-1',
                'amount_minor' => 60000,
                'currency' => 'IDR',
                'status' => Payment::STATUS_SUCCEEDED,
                'succeeded_at' => now(),
            ]);

            Payment::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-SPLIT-2',
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
                'provider_payment_id' => 'MIDTRANS-SPLIT-2',
                'amount_minor' => 40000,
                'currency' => 'IDR',
                'status' => Payment::STATUS_SUCCEEDED,
                'succeeded_at' => now(),
            ]);
        });

        $service = app(PaymentReconciliationService::class);

        $recon1 = $service->reconcileEvidence(
            $this->tenantData['tenant']->id,
            new ProviderEvidence('midtrans', 'MIDTRANS-SPLIT-1', 60000),
            $this->owner->id
        );

        $recon2 = $service->reconcileEvidence(
            $this->tenantData['tenant']->id,
            new ProviderEvidence('midtrans', 'MIDTRANS-SPLIT-2', 40000),
            $this->owner->id
        );

        $this->assertEquals(PaymentReconciliation::STATUS_MATCHED, $recon1->reconciliation_status);
        $this->assertEquals(PaymentReconciliation::STATUS_MATCHED, $recon2->reconciliation_status);
        $this->assertEquals(60000, $recon1->expected_amount_minor);
        $this->assertEquals(40000, $recon2->expected_amount_minor);
    }

    public function test_refund_reconciliation_matches_against_payment_refund(): void
    {
        $setup = $this->createSaleWithPayment($this->outlet1, 50000, Payment::STATUS_SUCCEEDED, 'MIDTRANS-REFUND-BASE');
        $payment = $setup['payment'];

        // Create partial refund
        $this->runForTenant(function () use ($payment) {
            PaymentRefund::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'payment_id' => $payment->id,
                'reference_number' => 'REF-001',
                'amount_minor' => 20000,
                'reason' => 'Customer requested refund',
                'status' => PaymentRefund::STATUS_COMPLETED,
                'provider_reference' => 'MIDTRANS-REFUND-EXT-01',
                'completed_at' => now(),
            ]);
        });

        $service = app(PaymentReconciliationService::class);
        $recon = $service->reconcileEvidence(
            $this->tenantData['tenant']->id,
            new ProviderEvidence('midtrans', 'MIDTRANS-REFUND-EXT-01', 20000, settlementStatus: 'settled'),
            $this->owner->id
        );

        $this->assertEquals(PaymentReconciliation::STATUS_MATCHED, $recon->reconciliation_status);
        $this->assertEquals(20000, $recon->expected_amount_minor);
        $this->assertEquals(20000, $recon->provider_amount_minor);
    }
}
