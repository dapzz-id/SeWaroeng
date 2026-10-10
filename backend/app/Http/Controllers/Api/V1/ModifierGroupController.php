<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModifierGroupController extends Controller
{
    /**
     * List all modifier groups and their modifiers.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = ModifierGroup::where('tenant_id', $tenantId)->with('modifiers');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $groups = $query->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success(data: $groups->map(fn ($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'selection_mode' => $g->selection_mode,
            'min_selection' => $g->min_selection,
            'max_selection' => $g->max_selection,
            'is_required' => $g->is_required,
            'status' => $g->status,
            'sort_order' => $g->sort_order,
            'modifiers' => $g->modifiers->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'price_delta' => $m->price_delta,
                'status' => $m->status,
                'sort_order' => $m->sort_order,
            ]),
        ]));
    }

    /**
     * Create a new modifier group (with optional initial modifiers).
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'selection_mode' => 'nullable|in:single,multiple',
            'min_selection' => 'nullable|integer|min:0',
            'max_selection' => 'nullable|integer|min:0',
            'is_required' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
            'modifiers' => 'nullable|array',
            'modifiers.*.name' => 'required_with:modifiers|string|max:255',
            'modifiers.*.price_delta' => 'nullable|integer',
        ]);

        $min = $validated['min_selection'] ?? 0;
        $max = $validated['max_selection'] ?? 1;

        if ($min > $max) {
            return ApiResponse::error(
                message: 'Minimum selection cannot be greater than maximum selection.',
                code: 'MODIFIER_SELECTION_INVALID',
                status: 422
            );
        }

        $group = DB::transaction(function () use ($validated, $tenantId, $min, $max, $request) {
            $group = ModifierGroup::create([
                'tenant_id' => $tenantId,
                'name' => trim($validated['name']),
                'selection_mode' => $validated['selection_mode'] ?? 'single',
                'min_selection' => $min,
                'max_selection' => $max,
                'is_required' => $validated['is_required'] ?? false,
                'status' => $validated['status'] ?? 'active',
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            if (! empty($validated['modifiers']) && is_array($validated['modifiers'])) {
                foreach ($validated['modifiers'] as $idx => $mData) {
                    Modifier::create([
                        'tenant_id' => $tenantId,
                        'modifier_group_id' => $group->id,
                        'name' => trim($mData['name']),
                        'price_delta' => $mData['price_delta'] ?? 0,
                        'status' => 'active',
                        'sort_order' => $idx,
                    ]);
                }
            }

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $request->user()->id,
                'action' => 'modifier_group_created',
                'entity_type' => ModifierGroup::class,
                'entity_id' => $group->id,
                'before' => null,
                'after' => [
                    'name' => $group->name,
                    'selection_mode' => $group->selection_mode,
                    'min_selection' => $group->min_selection,
                    'max_selection' => $group->max_selection,
                ],
                'request_id' => $request->header('X-Request-ID'),
            ]);

            return $group;
        });

        return ApiResponse::success(
            data: [
                'id' => $group->id,
                'name' => $group->name,
                'selection_mode' => $group->selection_mode,
                'min_selection' => $group->min_selection,
                'max_selection' => $group->max_selection,
                'is_required' => $group->is_required,
                'status' => $group->status,
            ],
            message: 'Modifier group created successfully.',
            status: 201
        );
    }

    /**
     * Update an existing modifier group.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $group = ModifierGroup::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'selection_mode' => 'nullable|in:single,multiple',
            'min_selection' => 'nullable|integer|min:0',
            'max_selection' => 'nullable|integer|min:0',
            'is_required' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);

        $min = $validated['min_selection'] ?? $group->min_selection;
        $max = $validated['max_selection'] ?? $group->max_selection;

        if ($min > $max) {
            return ApiResponse::error(
                message: 'Minimum selection cannot be greater than maximum selection.',
                code: 'MODIFIER_SELECTION_INVALID',
                status: 422
            );
        }

        $before = $group->getOriginal();

        if (isset($validated['name'])) {
            $group->name = trim($validated['name']);
        }
        if (isset($validated['selection_mode'])) {
            $group->selection_mode = $validated['selection_mode'];
        }
        if (isset($validated['min_selection'])) {
            $group->min_selection = $validated['min_selection'];
        }
        if (isset($validated['max_selection'])) {
            $group->max_selection = $validated['max_selection'];
        }
        if (isset($validated['is_required'])) {
            $group->is_required = $validated['is_required'];
        }
        if (isset($validated['status'])) {
            $group->status = $validated['status'];
        }
        if (isset($validated['sort_order'])) {
            $group->sort_order = $validated['sort_order'];
        }

        $group->save();

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'modifier_group_updated',
            'entity_type' => ModifierGroup::class,
            'entity_id' => $group->id,
            'before' => [
                'name' => $before['name'] ?? null,
                'min_selection' => $before['min_selection'] ?? null,
                'max_selection' => $before['max_selection'] ?? null,
            ],
            'after' => [
                'name' => $group->name,
                'min_selection' => $group->min_selection,
                'max_selection' => $group->max_selection,
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $group->id,
                'name' => $group->name,
                'selection_mode' => $group->selection_mode,
                'min_selection' => $group->min_selection,
                'max_selection' => $group->max_selection,
                'is_required' => $group->is_required,
                'status' => $group->status,
            ],
            message: 'Modifier group updated successfully.'
        );
    }

    /**
     * Add a modifier to a modifier group.
     */
    public function addModifier(Request $request, string $groupId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $group = ModifierGroup::where('id', $groupId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price_delta' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);

        $modifier = Modifier::create([
            'tenant_id' => $tenantId,
            'modifier_group_id' => $group->id,
            'name' => trim($validated['name']),
            'price_delta' => $validated['price_delta'] ?? 0,
            'status' => $validated['status'] ?? 'active',
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'modifier_created',
            'entity_type' => Modifier::class,
            'entity_id' => $modifier->id,
            'before' => null,
            'after' => [
                'group_id' => $group->id,
                'name' => $modifier->name,
                'price_delta' => $modifier->price_delta,
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $modifier->id,
                'modifier_group_id' => $modifier->modifier_group_id,
                'name' => $modifier->name,
                'price_delta' => $modifier->price_delta,
                'status' => $modifier->status,
            ],
            message: 'Modifier added successfully.',
            status: 201
        );
    }

    /**
     * Update an existing modifier.
     */
    public function updateModifier(Request $request, string $groupId, string $modifierId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $modifier = Modifier::where('id', $modifierId)
            ->where('modifier_group_id', $groupId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'price_delta' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);

        $before = $modifier->getOriginal();

        if (isset($validated['name'])) {
            $modifier->name = trim($validated['name']);
        }
        if (isset($validated['price_delta'])) {
            $modifier->price_delta = (int) $validated['price_delta'];
        }
        if (isset($validated['status'])) {
            $modifier->status = $validated['status'];
        }
        if (isset($validated['sort_order'])) {
            $modifier->sort_order = $validated['sort_order'];
        }

        $modifier->save();

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'modifier_updated',
            'entity_type' => Modifier::class,
            'entity_id' => $modifier->id,
            'before' => [
                'name' => $before['name'] ?? null,
                'price_delta' => $before['price_delta'] ?? null,
                'status' => $before['status'] ?? null,
            ],
            'after' => [
                'name' => $modifier->name,
                'price_delta' => $modifier->price_delta,
                'status' => $modifier->status,
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $modifier->id,
                'modifier_group_id' => $modifier->modifier_group_id,
                'name' => $modifier->name,
                'price_delta' => $modifier->price_delta,
                'status' => $modifier->status,
            ],
            message: 'Modifier updated successfully.'
        );
    }
}
