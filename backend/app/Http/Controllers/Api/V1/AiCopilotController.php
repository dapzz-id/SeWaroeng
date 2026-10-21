<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\WhatsApp\WhatsAppBusinessQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiCopilotController extends Controller
{
    public function __construct(
        protected WhatsAppBusinessQueryService $queryService
    ) {}

    /**
     * In-App AI Business Copilot natural language query.
     */
    public function query(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'max:500'],
        ]);

        $tenantContext = app(TenantContext::class);
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $result = $this->queryService->executeQuery(
            tenant: $tenant,
            queryText: $request->input('query'),
            user: $user
        );

        return ApiResponse::success(data: [
            'intent' => $result['intent'],
            'success' => $result['success'],
            'reply_text' => $result['reply_text'],
            'data' => $result['data'],
            'source' => 'SeWaroeng Analytics & POS Engine',
            'period' => 'Realtime / '.now()->toDateString(),
        ]);
    }
}
