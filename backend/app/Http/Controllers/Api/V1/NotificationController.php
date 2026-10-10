<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
    ) {}

    /**
     * List notifications accessible to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $user = $request->user();

        $unreadOnly = $request->boolean('unread_only');
        $type = $request->query('type');
        $perPage = min(100, max(1, $request->integer('per_page', 20)));

        $notifications = $this->notificationService->getNotifications(
            tenantId: $tenantId,
            userId: $user?->id,
            filters: [
                'unread_only' => $unreadOnly,
                'type' => $type,
            ],
            perPage: $perPage
        );

        $unreadCount = $this->notificationService->getUnreadCount($tenantId, $user?->id);

        return ApiResponse::success(
            data: $notifications->items(),
            meta: [
                'current_page' => $notifications->currentPage(),
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'last_page' => $notifications->lastPage(),
                'unread_count' => $unreadCount,
            ],
            message: 'Notifications retrieved successfully.'
        );
    }

    /**
     * Retrieve the current count of unread notifications.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $user = $request->user();

        $count = $this->notificationService->getUnreadCount($tenantId, $user?->id);

        return ApiResponse::success(
            data: ['unread_count' => $count],
            message: 'Unread count retrieved successfully.'
        );
    }

    /**
     * Mark a specific notification as read.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $user = $request->user();

        $notification = $this->notificationService->markAsRead($tenantId, $id, $user?->id);

        return ApiResponse::success(
            data: $notification,
            message: 'Notification marked as read successfully.'
        );
    }

    /**
     * Mark all accessible unread notifications as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $user = $request->user();

        $marked = $this->notificationService->markAllAsRead($tenantId, $user?->id);

        return ApiResponse::success(
            data: ['marked_count' => $marked],
            message: 'All notifications marked as read successfully.'
        );
    }
}
