<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Sale;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\WhatsAppBusinessQueryService;
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

    /**
     * Natural language business query mapped to safe query (PRD Section 19).
     */
    public function query(Request $request, WhatsAppBusinessQueryService $queryService): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:255'],
        ]);

        $result = $queryService->executeQuery($tenant, $validated['query'], $request->user());

        return ApiResponse::success(data: $result);
    }

    /**
     * Send order confirmation to customer.
     */
    public function sendOrderConfirmation(Request $request, string $orderId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        /** @var Order $order */
        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($orderId);

        $message = $this->notificationService->queueOrderConfirmation($order);

        if (! $message) {
            return ApiResponse::error(
                message: 'Tidak dapat mengirim konfirmasi pesanan (pastikan nomor telepon pelanggan tersedia).',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success(
            data: $message,
            message: 'Konfirmasi pesanan telah diproses ke outbox WhatsApp.'
        );
    }

    /**
     * Send booking confirmation to customer.
     */
    public function sendBookingConfirmation(Request $request, string $bookingId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        /** @var Booking $booking */
        $booking = Booking::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($bookingId);

        $message = $this->notificationService->queueBookingConfirmation($booking);

        if (! $message) {
            return ApiResponse::error(
                message: 'Tidak dapat mengirim konfirmasi reservasi.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success(
            data: $message,
            message: 'Konfirmasi reservasi telah diproses ke outbox WhatsApp.'
        );
    }

    /**
     * Send booking reminder to customer.
     */
    public function sendBookingReminder(Request $request, string $bookingId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        /** @var Booking $booking */
        $booking = Booking::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($bookingId);

        $message = $this->notificationService->queueCustomerBookingReminder($booking);

        if (! $message) {
            return ApiResponse::error(
                message: 'Tidak dapat mengirim pengingat reservasi.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success(
            data: $message,
            message: 'Pengingat reservasi telah diproses ke outbox WhatsApp.'
        );
    }

    /**
     * Send promotional marketing message to customer (requires affirmative marketing opt-in).
     */
    public function sendPromotion(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();

        $validated = $request->validate([
            'customer_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:1000'],
            'campaign_id' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var Customer $customer */
        $customer = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($validated['customer_id']);

        $message = $this->notificationService->queuePromotion(
            tenant: $tenant,
            customer: $customer,
            promoTitle: $validated['title'],
            promoBody: $validated['body'],
            campaignId: $validated['campaign_id'] ?? null
        );

        if (! $message) {
            return ApiResponse::error(
                message: 'Tidak dapat mengirim promo ke pelanggan.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($message->isFailed()) {
            return ApiResponse::error(
                message: $message->error_message ?? 'Pelanggan tidak memenuhi syarat menerima promo (memerlukan persetujuan marketing eksplisit).',
                data: $message,
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success(
            data: $message,
            message: 'Pesan promosi telah diproses ke outbox WhatsApp.'
        );
    }

    /**
     * Trigger manual daily sales summary generation.
     */
    public function sendDailySummary(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $message = $this->notificationService->queueDailySalesSummary(
            tenant: $tenant,
            date: $validated['date'] ?? null,
            overridePhone: $validated['phone'] ?? null
        );

        if (! $message) {
            return ApiResponse::error(
                message: 'Tidak dapat memproses ringkasan harian (periksa konfigurasi nomor merchant dan fitur WhatsApp).',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return ApiResponse::success(
            data: $message,
            message: 'Ringkasan penjualan harian telah diproses ke outbox WhatsApp.'
        );
    }
}
