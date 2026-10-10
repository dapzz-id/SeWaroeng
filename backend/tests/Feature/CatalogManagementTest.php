<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\PriceNotConfiguredException;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerUser;

    protected Tenant $tenantA;

    protected Outlet $outletA1;

    protected Outlet $outletA2;

    protected string $tokenA;

    protected Tenant $tenantB;

    protected Outlet $outletB1;

    protected User $ownerUserB;

    protected string $tokenB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // 1. Setup Tenant A
        $this->ownerUser = User::factory()->create(['name' => 'Owner A', 'is_active' => true]);
        $this->tenantA = Tenant::create([
            'name' => 'Tenant A Business',
            'code' => 'TENANTA',
            'slug' => 'tenant-a',
            'business_type' => 'fnb',
            'status' => 'active',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'owner_user_id' => $this->ownerUser->id,
        ]);
        app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $this->outletA1 = Outlet::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Outlet A1 Pusat',
                'code' => 'PUSAT',
                'status' => 'active',
            ]);
            $this->outletA2 = Outlet::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Outlet A2 Cabang',
                'code' => 'CABANG',
                'status' => 'active',
            ]);
        });
        $ownerRole = Role::where('slug', 'owner')->first();
        UserTenantMembership::create([
            'user_id' => $this->ownerUser->id,
            'tenant_id' => $this->tenantA->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);
        $this->tokenA = $this->ownerUser->createToken('test-device-a')->plainTextToken;

        // 2. Setup Tenant B
        $this->ownerUserB = User::factory()->create(['name' => 'Owner B', 'is_active' => true]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B Business',
            'code' => 'TENANTB',
            'slug' => 'tenant-b',
            'business_type' => 'retail',
            'status' => 'active',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'owner_user_id' => $this->ownerUserB->id,
        ]);
        app(TenantManager::class)->runForTenant($this->tenantB->id, function () {
            $this->outletB1 = Outlet::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Outlet B1 Utama',
                'code' => 'UTAMA',
                'status' => 'active',
            ]);
        });
        UserTenantMembership::create([
            'user_id' => $this->ownerUserB->id,
            'tenant_id' => $this->tenantB->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);
        $this->tokenB = $this->ownerUserB->createToken('test-device-b')->plainTextToken;
    }

    public function test_category_crud_and_hierarchy_invariants(): void
    {
        // 1. Create root category
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-cat-root-1',
        ])->postJson('/api/v1/categories', [
            'name' => 'Coffee',
            'slug' => 'coffee',
        ]);

        $response->assertStatus(201);
        $rootId = $response->json('data.id');
        $this->assertNotNull($rootId);

        // 2. Create child category
        $childResponse = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-cat-child-1',
        ])->postJson('/api/v1/categories', [
            'name' => 'Espresso Based',
            'slug' => 'espresso-based',
            'parent_id' => $rootId,
        ]);

        $childResponse->assertStatus(201);
        $childId = $childResponse->json('data.id');

        // 3. Self-parenting rejected
        $selfParentRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-cat-self-parent-1',
        ])->patchJson("/api/v1/categories/{$rootId}", [
            'parent_id' => $rootId,
        ]);
        $selfParentRes->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_SELF_PARENT');

        // 4. Circular ancestry rejected (Child -> Parent -> Child)
        $circularRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-cat-circular-1',
        ])->patchJson("/api/v1/categories/{$rootId}", [
            'parent_id' => $childId,
        ]);
        $circularRes->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_CIRCULAR_REFERENCE');

        // 5. Cross-tenant parent rejected
        auth()->forgetGuards();
        $crossCatRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
            'Idempotency-Key' => 'idemp-cat-cross-1',
        ])->postJson('/api/v1/categories', [
            'name' => 'Tenant B Beverage',
            'parent_id' => $rootId, // Belongs to Tenant A!
        ]);
        $crossCatRes->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_CROSS_TENANT');

        // 6. Tenant-scoped slug uniqueness (Tenant B can have same slug 'coffee')
        $sameSlugBRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
            'Idempotency-Key' => 'idemp-cat-same-slug-b',
        ])->postJson('/api/v1/categories', [
            'name' => 'Coffee',
            'slug' => 'coffee',
        ]);
        $sameSlugBRes->assertStatus(201);
    }

    public function test_product_atomic_creation_with_default_variant_and_price(): void
    {
        $cat = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            return Category::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Beverage',
                'slug' => 'beverage',
            ]);
        });

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-prod-create-1',
        ])->postJson('/api/v1/products', [
            'name' => 'Americano',
            'slug' => 'americano',
            'sku' => 'AME-001',
            'category_id' => $cat->id,
            'product_type' => 'product',
            'price' => 18000,
            'barcode' => '8991234567890',
        ]);

        $response->assertStatus(201);
        $productId = $response->json('data.id');

        // Verify Product and Variant records in Tenant A context
        app(TenantManager::class)->runForTenant($this->tenantA->id, function () use ($productId) {
            $product = Product::with(['variants.prices', 'variants.barcodes'])->findOrFail($productId);
            $this->assertEquals('Americano', $product->name);
            $this->assertCount(1, $product->variants);

            $defaultVariant = $product->variants->first();
            $this->assertTrue($defaultVariant->is_default);
            $this->assertEquals('Standard', $defaultVariant->name);

            // Verify price
            $this->assertCount(1, $defaultVariant->prices);
            $this->assertEquals(18000, $defaultVariant->prices->first()->amount);
            $this->assertNull($defaultVariant->prices->first()->outlet_id);

            // Verify barcode
            $this->assertCount(1, $defaultVariant->barcodes);
            $this->assertEquals('8991234567890', $defaultVariant->barcodes->first()->barcode);
        });
    }

    public function test_product_with_multi_variants_and_exactly_one_default(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/products', [
            'name' => 'Es Kopi Susu',
            'slug' => 'es-kopi-susu',
            'variants' => [
                [
                    'name' => 'Regular',
                    'sku' => 'EKS-REG',
                    'price' => 15000,
                    'is_default' => true,
                    'barcodes' => ['8990001'],
                ],
                [
                    'name' => 'Large',
                    'sku' => 'EKS-LRG',
                    'price' => 20000,
                    'is_default' => false,
                    'barcodes' => ['8990002'],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $productId = $response->json('data.id');

        app(TenantManager::class)->runForTenant($this->tenantA->id, function () use ($productId) {
            $product = Product::with('variants')->findOrFail($productId);
            $this->assertCount(2, $product->variants);
            $defaults = $product->variants->where('is_default', true);
            $this->assertCount(1, $defaults);
            $this->assertEquals('Regular', $defaults->first()->name);
        });
    }

    public function test_price_resolution_precedence_and_database_overlap_constraint(): void
    {
        // 1. Create product & variant
        [$product, $variant] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $prod = Product::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Matcha Latte',
                'slug' => 'matcha-latte',
            ]);
            $var = ProductVariant::create([
                'tenant_id' => $this->tenantA->id,
                'product_id' => $prod->id,
                'name' => 'Standard',
                'is_default' => true,
            ]);

            return [$prod, $var];
        });

        // 2. Create tenant-wide default price: Rp 25.000
        $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/prices', [
            'variant_id' => $variant->id,
            'amount' => 25000,
            'currency' => 'IDR',
        ])->assertStatus(201);

        // 3. Query without outlet context -> resolves default Rp 25.000
        $listRes1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson('/api/v1/products');
        $this->assertEquals(25000, $listRes1->json('data.items.0.price.amount'));

        // 4. Create outlet-specific price for Outlet A1: Rp 28.000
        $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/prices', [
            'variant_id' => $variant->id,
            'outlet_id' => $this->outletA1->id,
            'amount' => 28000,
            'currency' => 'IDR',
        ])->assertStatus(201);

        // 5. Query with Outlet A1 context -> resolves outlet override Rp 28.000
        $listResA1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/products?outlet_id={$this->outletA1->id}");
        $this->assertEquals(28000, $listResA1->json('data.items.0.price.amount'));

        // 6. Query with Outlet A2 context -> falls back to default Rp 25.000
        $listResA2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/products?outlet_id={$this->outletA2->id}");
        $this->assertEquals(25000, $listResA2->json('data.items.0.price.amount'));

        // 7. Test PostgreSQL exclusion constraint: Overlapping active price for same variant & default scope
        $overlapRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/prices', [
            'variant_id' => $variant->id,
            'outlet_id' => null, // Same default scope!
            'amount' => 30000,
        ]);
        $overlapRes->assertStatus(422)
            ->assertJsonPath('code', 'PRICE_OVERLAPPING');
    }

    public function test_outlet_specific_product_availability(): void
    {
        $product = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $prod = Product::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Croissant',
                'slug' => 'croissant',
            ]);
            ProductVariant::create([
                'tenant_id' => $this->tenantA->id,
                'product_id' => $prod->id,
                'name' => 'Standard',
                'is_default' => true,
            ]);

            return $prod;
        });

        // Default: available at Outlet A1 and A2
        $this->assertTrue($product->isAvailableAtOutlet($this->outletA1->id));
        $this->assertTrue($product->isAvailableAtOutlet($this->outletA2->id));

        // Set explicit override: unavailable at Outlet A2
        $availRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/products/{$product->id}/availability", [
            'outlet_id' => $this->outletA2->id,
            'is_available' => false,
        ]);
        $availRes->assertStatus(200);

        // Verify availability in queries
        $qA1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/products?outlet_id={$this->outletA1->id}");
        $this->assertTrue($qA1->json('data.items.0.is_available'));

        $qA2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/products?outlet_id={$this->outletA2->id}");
        $this->assertFalse($qA2->json('data.items.0.is_available'));
    }

    public function test_modifier_groups_and_selection_constraints(): void
    {
        // 1. Create modifier group with selection constraint min=1, max=2
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/modifier-groups', [
            'name' => 'Pilihan Susu',
            'selection_mode' => 'single',
            'min_selection' => 1,
            'max_selection' => 1,
            'is_required' => true,
            'modifiers' => [
                ['name' => 'Fresh Milk', 'price_delta' => 0],
                ['name' => 'Oat Milk', 'price_delta' => 6000],
            ],
        ]);

        $response->assertStatus(201);
        $groupId = $response->json('data.id');

        // 2. Reject invalid selection constraint (min > max)
        $invalidRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/modifier-groups', [
            'name' => 'Invalid Group',
            'min_selection' => 3,
            'max_selection' => 1,
        ]);
        $invalidRes->assertStatus(422)
            ->assertJsonPath('code', 'MODIFIER_SELECTION_INVALID');
    }

    public function test_cashier_cannot_mutate_catalog(): void
    {
        $cashierUser = User::factory()->create(['is_active' => true]);
        $cashierRole = Role::where('slug', 'cashier')->first();
        UserTenantMembership::create([
            'user_id' => $cashierUser->id,
            'tenant_id' => $this->tenantA->id,
            'role_id' => $cashierRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);
        $cashierToken = $cashierUser->createToken('cashier-dev')->plainTextToken;

        // Cashier CAN view products
        $viewRes = $this->withHeaders([
            'Authorization' => "Bearer {$cashierToken}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson('/api/v1/products');
        $viewRes->assertStatus(200);

        // Cashier CANNOT create products
        $createRes = $this->withHeaders([
            'Authorization' => "Bearer {$cashierToken}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/products', [
            'name' => 'Unauthorized Product',
        ]);
        $createRes->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_PERMISSION');
    }

    public function test_cross_tenant_composite_foreign_key_violation_prevented_at_database_level(): void
    {
        // Direct SQL test: attempt to create product in Tenant A referencing Category from Tenant B
        $catB = app(TenantManager::class)->runForTenant($this->tenantB->id, function () {
            return Category::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Category B',
                'slug' => 'cat-b',
            ]);
        });

        $this->expectException(QueryException::class);

        // Bypassing application validation directly on DB to test PostgreSQL composite FK constraint
        app(TenantManager::class)->runForTenant($this->tenantA->id, function () use ($catB) {
            DB::statement('INSERT INTO products (id, tenant_id, category_id, name, slug, product_type, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, now(), now())', [
                (string) Str::uuid(),
                $this->tenantA->id,
                $catB->id, // Belongs to Tenant B!
                'Direct SQL Injection Product',
                'direct-sql-prod',
                'product',
                'active',
            ]);
        });
    }

    public function test_unpriced_variant_cannot_be_treated_as_zero_and_yields_price_not_configured(): void
    {
        // 1. Create a variant without any configured price
        [$product, $variant] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $prod = Product::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Unpriced Artisan Tea',
                'slug' => 'unpriced-artisan-tea',
            ]);
            $var = ProductVariant::create([
                'tenant_id' => $this->tenantA->id,
                'product_id' => $prod->id,
                'name' => 'Standard',
                'is_default' => true,
            ]);

            return [$prod, $var];
        });

        // 2. Direct model method invariants: resolvePrice() is null (NOT 0), getEffectivePrice() throws
        $this->assertNull($variant->resolvePrice());
        $this->assertNull($variant->resolvePrice($this->outletA1->id));

        try {
            $variant->getEffectivePrice();
            $this->fail('Expected PriceNotConfiguredException was not thrown.');
        } catch (PriceNotConfiguredException $e) {
            $this->assertStringContainsString('No active price configured', $e->getMessage());
        }

        // 3. API price resolve endpoint MUST reject unpriced variant with stable PRICE_NOT_CONFIGURED 422
        $resolveRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/prices/resolve?variant_id={$variant->id}");

        $resolveRes->assertStatus(422)
            ->assertJsonPath('code', 'PRICE_NOT_CONFIGURED');

        // 4. Products list API must return price as null, NEVER 0
        $listRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson('/api/v1/products?search=Unpriced');

        $listRes->assertStatus(200);
        $this->assertNull($listRes->json('data.items.0.price'));
        $this->assertNull($listRes->json('data.items.0.variants.0.price'));

        // 5. When price is configured, resolve endpoint succeeds
        $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/prices', [
            'variant_id' => $variant->id,
            'amount' => 15000,
            'currency' => 'IDR',
        ])->assertStatus(201);

        $resolveResSuccess = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/prices/resolve?variant_id={$variant->id}");

        $resolveResSuccess->assertStatus(200)
            ->assertJsonPath('data.amount', 15000);
    }
}
