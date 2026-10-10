<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Sale;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppMessageController extends Controller
{
    public function __construct(
        protected WhatsAppNotificationService $notificationService,
        protected SubscriptionEntitlementService $entitlementService
    ) {}

    /**
     * List WhatsApp delivery records for active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = WhatsAppMessage::query()
            ->where('tenant_id', $tenantId)
            ->latest('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('message_type')) {
            $query->where('message_type', $request->query('message_type'));
        }

        if ($request->filled('recipient_phone')) {
            $normalized = PhoneNormalizer::normalize($request->query('recipient_phone'));
            $query->where('recipient_phone', 'like', "%{$normalized}%");
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::paginated(paginator: $paginator);
    }

    /**
     * Show details of a specific WhatsApp message.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $message = WhatsAppMessage::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return ApiResponse::success(data: $message);
    }

    /**
     * Manually resend an existing WhatsApp message.
     */
    public function resend(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        /** @var WhatsAppMessage $message */
        $message = WhatsAppMessage::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $resendResult = $this->notificationService->resendMessage($message);

        if ($resendResult->isSent()) {
            return ApiResponse::success(
                data: $resendResult,
                message: 'Pesan WhatsApp berhasil dikirim ulang.'
            );
        }

        return ApiResponse::error(
            message: $resendResult->error_message ?? 'Gagal mengirim ulang pesan WhatsApp.',
            data: $resendResult,
            status: Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    /**
     * Manually trigger WhatsApp receipt for a sale.
     */
    public function sendReceipt(Request $request, string $saleId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        /** @var Sale $sale */
        $sale = Sale::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($saleId);

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:50'],
            'force' => ['nullable', 'boolean'],
        ]);

        $overridePhone = $validated['phone'] ?? null;
        $force = (bool) ($validated['force'] ?? false);

        $message = $this->notificationService->sendReceipt($sale, $overridePhone, $force);

        if (! $message) {
            return ApiResponse::error(
                message: 'Tidak dapat mengirim nota WhatsApp (pastikan nomor telepon valid, fitur WhatsApp aktif, dan koneksi terpasang).',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($message->isSent()) {
            return ApiResponse::success(
                data: $message,
                message: 'Nota berhasil dikirim ke WhatsApp.'
            );
        }

        return ApiResponse::error(
            message: $message->error_message ?? 'Pengiriman nota WhatsApp gagal.',
            data: $message,
            status: Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }
}
