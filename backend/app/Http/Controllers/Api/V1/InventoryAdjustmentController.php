<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * Set opening balance for an inventory item at a location.
     */
    public function storeOpeningBalance(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'inventory_item_id' => 'required|uuid',
            'location_id' => 'required|uuid',
            'quantity' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        $movement = $this->inventoryService->setOpeningBalance(
            tenantId: $tenantId,
            inventoryItemId: $validated['inventory_item_id'],
            locationId: $validated['location_id'],
            quantity: $validated['quantity'],
            note: $validated['note'] ?? null,
            userId: $request->user()->id,
        );

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'opening_balance_created',
            'entity_type' => InventoryMovement::class,
            'entity_id' => $movement->id,
            'after' => $movement->toArray(),
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $movement->id,
                'movement_number' => $movement->movement_number,
                'inventory_item_id' => $movement->inventory_item_id,
                'location_id' => $movement->location_id,
                'movement_type' => $movement->movement_type,
                'quantity_delta' => $movement->quantity_delta,
                'on_hand' => $movement->after_on_hand,
            ],
            message: 'Opening balance recorded successfully.',
            status: 201
        );
    }

    /**
     * Perform command-based stock adjustment (delta).
     */
    public function storeAdjustment(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'inventory_item_id' => 'required|uuid',
            'location_id' => 'required|uuid',
            'quantity_delta' => 'required|numeric',
            'reason' => 'required|string|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        $movement = $this->inventoryService->adjustStock(
            tenantId: $tenantId,
            inventoryItemId: $validated['inventory_item_id'],
            locationId: $validated['location_id'],
            quantityDelta: $validated['quantity_delta'],
            reason: $validated['reason'],
            note: $validated['note'] ?? null,
            userId: $request->user()->id,
        );

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'inventory_adjusted',
            'entity_type' => InventoryMovement::class,
            'entity_id' => $movement->id,
            'after' => $movement->toArray(),
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $movement->id,
                'movement_number' => $movement->movement_number,
                'inventory_item_id' => $movement->inventory_item_id,
                'location_id' => $movement->location_id,
                'movement_type' => $movement->movement_type,
                'quantity_delta' => $movement->quantity_delta,
                'before_on_hand' => $movement->before_on_hand,
                'after_on_hand' => $movement->after_on_hand,
                'reason' => $movement->reason,
            ],
            message: 'Stock adjusted successfully.',
            status: 201
        );
    }
}
