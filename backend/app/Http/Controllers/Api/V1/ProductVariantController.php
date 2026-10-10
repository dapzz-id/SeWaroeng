<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductVariantController extends Controller
{
    /**
     * List all variants for a product.
     */
    public function index(Request $request, string $productId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $product = Product::where('id', $productId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $variants = ProductVariant::where('product_id', $product->id)
            ->where('tenant_id', $tenantId)
            ->with(['barcodes', 'prices'])
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(data: $variants->map(fn ($v) => [
            'id' => $v->id,
            'product_id' => $v->product_id,
            'name' => $v->name,
            'sku' => $v->sku,
            'status' => $v->status,
            'is_default' => $v->is_default,
            'sort_order' => $v->sort_order,
            'barcodes' => $v->barcodes->map(fn ($b) => [
                'id' => $b->id,
                'barcode' => $b->barcode,
                'type' => $b->type,
            ]),
            'prices' => $v->prices->map(fn ($p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'outlet_id' => $p->outlet_id,
                'is_active' => $p->is_active,
                'effective_from' => $p->effective_from?->toIso8601String(),
                'effective_until' => $p->effective_until?->toIso8601String(),
            ]),
        ]));
    }

    /**
     * Create an additional variant for a product.
     */
    public function store(Request $request, string $productId): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $tenantId = (string) $tenant->id;
        $currency = $tenant->currency ?: 'IDR';

        $product = Product::where('id', $productId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,inactive',
            'is_default' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'price' => 'nullable|integer|min:0',
            'barcodes' => 'nullable|array',
            'barcodes.*' => 'string|max:100',
        ]);

        $vSku = ! empty($validated['sku']) ? trim($validated['sku']) : null;
        if ($vSku) {
            $skuExists = ProductVariant::where('tenant_id', $tenantId)->where('sku', $vSku)->exists();
            if ($skuExists) {
                return ApiResponse::error(
                    message: "Variant SKU '{$vSku}' is already registered in this business.",
                    code: 'VARIANT_SKU_TAKEN',
                    status: 422
                );
            }
        }

        $variant = DB::transaction(function () use ($validated, $tenantId, $product, $currency, $vSku, $request) {
            $isDefault = $validated['is_default'] ?? false;

            // If new variant is set as default, remove default from existing variants
            if ($isDefault) {
                ProductVariant::where('product_id', $product->id)
                    ->where('tenant_id', $tenantId)
                    ->update(['is_default' => false]);
            }

            $variant = ProductVariant::create([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'name' => trim($validated['name']),
                'sku' => $vSku,
                'status' => $validated['status'] ?? 'active',
                'is_default' => $isDefault,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            if (isset($validated['price'])) {
                Price::create([
                    'tenant_id' => $tenantId,
                    'variant_id' => $variant->id,
                    'outlet_id' => null,
                    'amount' => (int) $validated['price'],
                    'currency' => $currency,
                    'effective_from' => now(),
                    'is_active' => true,
                ]);
            }

            if (! empty($validated['barcodes'])) {
                foreach ($validated['barcodes'] as $bc) {
                    $bcClean = trim($bc);
                    $bcExists = ProductBarcode::where('tenant_id', $tenantId)->where('barcode', $bcClean)->exists();
                    if ($bcExists) {
                        throw new \InvalidArgumentException("Barcode '{$bcClean}' is already in use in this business.");
                    }
                    ProductBarcode::create([
                        'tenant_id' => $tenantId,
                        'variant_id' => $variant->id,
                        'barcode' => $bcClean,
                    ]);
                }
            }

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $request->user()->id,
                'action' => 'variant_created',
                'entity_type' => ProductVariant::class,
                'entity_id' => $variant->id,
                'before' => null,
                'after' => [
                    'product_id' => $product->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'is_default' => $variant->is_default,
                ],
                'request_id' => $request->header('X-Request-ID'),
            ]);

            return $variant;
        });

        return ApiResponse::success(
            data: [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'is_default' => $variant->is_default,
                'status' => $variant->status,
            ],
            message: 'Variant created successfully.',
            status: 201
        );
    }

    /**
     * Update an existing variant.
     */
    public function update(Request $request, string $productId, string $variantId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $product = Product::where('id', $productId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $variant = ProductVariant::where('id', $variantId)
            ->where('product_id', $product->id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sku' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,inactive',
            'is_default' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        if (isset($validated['sku'])) {
            $vSku = trim($validated['sku']);
            $skuExists = ProductVariant::where('tenant_id', $tenantId)
                ->where('sku', $vSku)
                ->where('id', '!=', $variant->id)
                ->exists();

            if ($skuExists) {
                return ApiResponse::error(
                    message: "Variant SKU '{$vSku}' is already registered in this business.",
                    code: 'VARIANT_SKU_TAKEN',
                    status: 422
                );
            }
            $variant->sku = $vSku;
        }

        // Prevent setting is_default to false if it's the only default variant
        if (isset($validated['is_default']) && $validated['is_default'] === false && $variant->is_default) {
            $otherDefaults = ProductVariant::where('product_id', $product->id)
                ->where('tenant_id', $tenantId)
                ->where('id', '!=', $variant->id)
                ->where('is_default', true)
                ->exists();

            if (! $otherDefaults) {
                return ApiResponse::error(
                    message: 'Every product must have exactly one default variant. Assign another default variant first.',
                    code: 'VARIANT_CANNOT_UNSET_DEFAULT',
                    status: 422
                );
            }
        }

        // Prevent deactivating if this is the final remaining active variant
        if (isset($validated['status']) && $validated['status'] === 'inactive' && $variant->status === 'active') {
            $otherActive = ProductVariant::where('product_id', $product->id)
                ->where('tenant_id', $tenantId)
                ->where('id', '!=', $variant->id)
                ->where('status', 'active')
                ->exists();

            if (! $otherActive) {
                return ApiResponse::error(
                    message: 'Cannot deactivate the final remaining active variant of a product.',
                    code: 'VARIANT_FINAL_CANNOT_DEACTIVATE',
                    status: 422
                );
            }
        }

        DB::transaction(function () use ($validated, $variant, $product, $tenantId, $request) {
            if (! empty($validated['is_default'])) {
                ProductVariant::where('product_id', $product->id)
                    ->where('tenant_id', $tenantId)
                    ->where('id', '!=', $variant->id)
                    ->update(['is_default' => false]);
                $variant->is_default = true;
            }

            if (isset($validated['name'])) {
                $variant->name = trim($validated['name']);
            }
            if (isset($validated['sort_order'])) {
                $variant->sort_order = $validated['sort_order'];
            }
            if (isset($validated['status'])) {
                $variant->status = $validated['status'];
            }

            $before = $variant->getOriginal();
            $variant->save();

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $request->user()->id,
                'action' => 'variant_updated',
                'entity_type' => ProductVariant::class,
                'entity_id' => $variant->id,
                'before' => [
                    'name' => $before['name'] ?? null,
                    'sku' => $before['sku'] ?? null,
                    'status' => $before['status'] ?? null,
                    'is_default' => $before['is_default'] ?? null,
                ],
                'after' => [
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'status' => $variant->status,
                    'is_default' => $variant->is_default,
                ],
                'request_id' => $request->header('X-Request-ID'),
            ]);
        });

        return ApiResponse::success(
            data: [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'is_default' => $variant->is_default,
                'status' => $variant->status,
            ],
            message: 'Variant updated successfully.'
        );
    }

    /**
     * Add a barcode to a variant.
     */
    public function addBarcode(Request $request, string $productId, string $variantId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $variant = ProductVariant::where('id', $variantId)
            ->where('product_id', $productId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'barcode' => 'required|string|max:100',
            'type' => 'nullable|string|max:50',
        ]);

        $barcodeClean = trim($validated['barcode']);

        $barcodeTaken = ProductBarcode::where('tenant_id', $tenantId)
            ->where('barcode', $barcodeClean)
            ->exists();

        if ($barcodeTaken) {
            return ApiResponse::error(
                message: 'This barcode is already associated with an item in your business.',
                code: 'BARCODE_TAKEN',
                status: 422
            );
        }

        $barcode = ProductBarcode::create([
            'tenant_id' => $tenantId,
            'variant_id' => $variant->id,
            'barcode' => $barcodeClean,
            'type' => $validated['type'] ?? 'code128',
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'barcode_created',
            'entity_type' => ProductBarcode::class,
            'entity_id' => $barcode->id,
            'before' => null,
            'after' => [
                'variant_id' => $variant->id,
                'barcode' => $barcode->barcode,
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $barcode->id,
                'variant_id' => $barcode->variant_id,
                'barcode' => $barcode->barcode,
                'type' => $barcode->type,
            ],
            message: 'Barcode added successfully.',
            status: 201
        );
    }

    /**
     * Remove a barcode from a variant.
     */
    public function removeBarcode(Request $request, string $productId, string $variantId, string $barcodeId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $barcode = ProductBarcode::where('id', $barcodeId)
            ->where('variant_id', $variantId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $before = ['barcode' => $barcode->barcode, 'variant_id' => $barcode->variant_id];
        $barcode->delete();

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'barcode_removed',
            'entity_type' => ProductBarcode::class,
            'entity_id' => $barcodeId,
            'before' => $before,
            'after' => null,
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(message: 'Barcode removed successfully.');
    }
}
