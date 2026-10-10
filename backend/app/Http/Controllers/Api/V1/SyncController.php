<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Sync\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        protected SyncService $syncService,
    ) {}

    /**
     * Batch sync offline operations.
     */
    public function sync(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $userId = auth()->id();

        $validated = $request->validate([
            'client_id' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'checkpoint' => ['nullable', 'string', 'max:255'],
            'operations' => ['required', 'array', 'max:100'],
            'operations.*.id' => ['required', 'string'],
            'operations.*.command_type' => ['required', 'string'],
            'operations.*.aggregate_type' => ['required', 'string'],
            'operations.*.aggregate_id' => ['required', 'string'],
            'operations.*.idempotency_key' => ['nullable', 'string'],
            'operations.*.causation_id' => ['nullable', 'string'],
            'operations.*.payload' => ['required', 'array'],
        ]);

        $batchResult = $this->syncService->processBatch(
            batch: $validated,
            tenantId: $tenantId,
            userId: $userId,
        );

        return ApiResponse::success(
            data: $batchResult,
            message: 'Sync operations processed.'
        );
    }
}
