<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * List all categories for the current active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = Category::where('tenant_id', $tenantId)->with('parent:id,name,slug');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($search = $request->query('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($request->boolean('root_only', false)) {
            $query->whereNull('parent_id');
        }

        $categories = $query->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success(data: $categories->map(fn ($c) => [
            'id' => $c->id,
            'tenant_id' => $c->tenant_id,
            'parent_id' => $c->parent_id,
            'parent_name' => $c->parent?->name,
            'name' => $c->name,
            'slug' => $c->slug,
            'sort_order' => $c->sort_order,
            'status' => $c->status,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ]));
    }

    /**
     * Display the specified category.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $category = Category::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['parent:id,name,slug', 'children'])
            ->firstOrFail();

        return ApiResponse::success(data: [
            'id' => $category->id,
            'tenant_id' => $category->tenant_id,
            'parent_id' => $category->parent_id,
            'parent' => $category->parent ? [
                'id' => $category->parent->id,
                'name' => $category->parent->name,
                'slug' => $category->parent->slug,
            ] : null,
            'name' => $category->name,
            'slug' => $category->slug,
            'sort_order' => $category->sort_order,
            'status' => $category->status,
            'children' => $category->children->map(fn ($child) => [
                'id' => $child->id,
                'name' => $child->name,
                'slug' => $child->slug,
                'status' => $child->status,
                'sort_order' => $child->sort_order,
            ]),
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Create a new category.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'parent_id' => 'nullable|uuid',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
        ]);

        $slug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);

        // Check slug uniqueness within tenant
        $slugTaken = Category::where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->exists();

        if ($slugTaken) {
            return ApiResponse::error(
                message: 'A category with this slug already exists in this business.',
                code: 'CATEGORY_SLUG_TAKEN',
                status: 422
            );
        }

        // Validate parent_id if provided
        if (! empty($validated['parent_id'])) {
            $parent = Category::where('id', $validated['parent_id'])
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $parent) {
                return ApiResponse::error(
                    message: 'Parent category not found or belongs to another business.',
                    code: 'CATEGORY_CROSS_TENANT',
                    status: 422
                );
            }
        }

        $category = Category::create([
            'tenant_id' => $tenantId,
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => trim($validated['name']),
            'slug' => $slug,
            'sort_order' => $validated['sort_order'] ?? 0,
            'status' => $validated['status'] ?? 'active',
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'category_created',
            'entity_type' => Category::class,
            'entity_id' => $category->id,
            'before' => null,
            'after' => [
                'name' => $category->name,
                'slug' => $category->slug,
                'parent_id' => $category->parent_id,
                'status' => $category->status,
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $category->id,
                'tenant_id' => $category->tenant_id,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'sort_order' => $category->sort_order,
                'status' => $category->status,
            ],
            message: 'Category created successfully.',
            status: 201
        );
    }

    /**
     * Update an existing category.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $category = Category::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'parent_id' => 'nullable|uuid',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
        ]);

        if (isset($validated['slug'])) {
            $slug = Str::slug($validated['slug']);
            $exists = Category::where('tenant_id', $tenantId)
                ->where('slug', $slug)
                ->where('id', '!=', $category->id)
                ->exists();

            if ($exists) {
                return ApiResponse::error(
                    message: 'A category with this slug already exists in this business.',
                    code: 'CATEGORY_SLUG_TAKEN',
                    status: 422
                );
            }
            $category->slug = $slug;
        }

        if (array_key_exists('parent_id', $validated)) {
            $parentId = $validated['parent_id'];

            if ($parentId) {
                // Prevent self-parenting
                if ((string) $parentId === (string) $category->id) {
                    return ApiResponse::error(
                        message: 'A category cannot be its own parent.',
                        code: 'CATEGORY_SELF_PARENT',
                        status: 422
                    );
                }

                // Verify parent belongs to same tenant
                $parent = Category::where('id', $parentId)
                    ->where('tenant_id', $tenantId)
                    ->first();

                if (! $parent) {
                    return ApiResponse::error(
                        message: 'Parent category not found or belongs to another business.',
                        code: 'CATEGORY_CROSS_TENANT',
                        status: 422
                    );
                }

                // Prevent circular ancestry
                if ($category->wouldCreateCycle($parentId)) {
                    return ApiResponse::error(
                        message: 'Circular parent relationship detected.',
                        code: 'CATEGORY_CIRCULAR_REFERENCE',
                        status: 422
                    );
                }
            }

            $category->parent_id = $parentId;
        }

        if (isset($validated['name'])) {
            $category->name = trim($validated['name']);
        }

        if (isset($validated['sort_order'])) {
            $category->sort_order = $validated['sort_order'];
        }

        $statusChanged = false;
        if (isset($validated['status']) && $validated['status'] !== $category->status) {
            $category->status = $validated['status'];
            $statusChanged = true;
        }

        $before = $category->getOriginal();
        $category->save();

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => $statusChanged ? 'category_status_changed' : 'category_updated',
            'entity_type' => Category::class,
            'entity_id' => $category->id,
            'before' => [
                'name' => $before['name'] ?? null,
                'slug' => $before['slug'] ?? null,
                'parent_id' => $before['parent_id'] ?? null,
                'status' => $before['status'] ?? null,
            ],
            'after' => [
                'name' => $category->name,
                'slug' => $category->slug,
                'parent_id' => $category->parent_id,
                'status' => $category->status,
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $category->id,
                'tenant_id' => $category->tenant_id,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'sort_order' => $category->sort_order,
                'status' => $category->status,
            ],
            message: 'Category updated successfully.'
        );
    }
}
