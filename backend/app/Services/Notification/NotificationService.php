<?php

namespace App\Services\Notification;

use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class NotificationService
{
    /**
     * Create an idempotent notification.
     *
     * @param  array{
     *     recipient_user_id?: string|null,
     *     type: string,
     *     title: string,
     *     message: string,
     *     severity?: string,
     *     data?: array|null,
     *     dedup_key?: string|null
     * }  $data
     */
    public function createNotification(string $tenantId, array $data): Notification
    {
        $dedupKey = $data['dedup_key'] ?? null;

        if ($dedupKey) {
            $existing = Notification::where('tenant_id', $tenantId)
                ->where('dedup_key', $dedupKey)
                ->whereNull('resolved_at')
                ->first();

            if ($existing) {
                // If the active alert content needs an update
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($tenantId, $data, $dedupKey) {
                return Notification::create([
                    'tenant_id' => $tenantId,
                    'recipient_user_id' => $data['recipient_user_id'] ?? null,
                    'type' => $data['type'],
                    'title' => $data['title'],
                    'message' => $data['message'],
                    'severity' => $data['severity'] ?? Notification::SEVERITY_INFO,
                    'data' => $data['data'] ?? null,
                    'dedup_key' => $dedupKey,
                    'read_at' => null,
                    'resolved_at' => null,
                ]);
            });
        } catch (QueryException $e) {
            if ($dedupKey && (($e->errorInfo[0] ?? null) === '23505' || str_contains($e->getMessage(), 'uq_notifications_tenant_dedup'))) {
                $existing = Notification::where('tenant_id', $tenantId)
                    ->where('dedup_key', $dedupKey)
                    ->whereNull('resolved_at')
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /**
     * Resolve / close an active notification by dedup key.
     */
    public function resolveNotification(string $tenantId, string $dedupKey): void
    {
        Notification::where('tenant_id', $tenantId)
            ->where('dedup_key', $dedupKey)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    /**
     * Retrieve paginated notifications accessible by the specified user.
     *
     * @param  array{
     *     unread_only?: bool,
     *     type?: string|null
     * }  $filters
     */
    public function getNotifications(
        string $tenantId,
        ?string $userId,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = Notification::where('tenant_id', $tenantId);

        if ($userId) {
            $query->where(function ($q) use ($userId) {
                $q->whereNull('recipient_user_id')
                    ->orWhere('recipient_user_id', $userId);
            });
        }

        if (! empty($filters['unread_only'])) {
            $query->whereNull('read_at');
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Efficiently count unread notifications for a user.
     */
    public function getUnreadCount(string $tenantId, ?string $userId): int
    {
        $query = Notification::where('tenant_id', $tenantId)->whereNull('read_at');

        if ($userId) {
            $query->where(function ($q) use ($userId) {
                $q->whereNull('recipient_user_id')
                    ->orWhere('recipient_user_id', $userId);
            });
        }

        return $query->count();
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(string $tenantId, string $notificationId, string $userId): Notification
    {
        $notification = Notification::where('tenant_id', $tenantId)
            ->where('id', $notificationId)
            ->firstOrFail();

        if ($notification->recipient_user_id !== null && $notification->recipient_user_id !== $userId) {
            throw new AccessDeniedHttpException('Cannot access notification belonging to another user.');
        }

        $notification->markAsRead();

        return $notification;
    }

    /**
     * Mark all accessible unread notifications as read.
     */
    public function markAllAsRead(string $tenantId, string $userId): int
    {
        $query = Notification::where('tenant_id', $tenantId)->whereNull('read_at');

        if ($userId) {
            $query->where(function ($q) use ($userId) {
                $q->whereNull('recipient_user_id')
                    ->orWhere('recipient_user_id', $userId);
            });
        }

        return $query->update(['read_at' => now()]);
    }
}
