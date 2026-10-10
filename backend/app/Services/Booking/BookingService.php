<?php

namespace App\Services\Booking;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\BookingOverlapException;
use App\Exceptions\TableUnavailableException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Outlet;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Services\Notification\NotificationService;
use App\Services\Subscription\SubscriptionEntitlementService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(
        protected TenantManager $tenantManager,
        protected SubscriptionEntitlementService $entitlementService,
        protected NotificationService $notificationService
    ) {}

    /**
     * List bookings with filters.
     */
    public function listBookings(string $tenantId, ?string $outletId = null, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $outletId, $filters, $perPage) {
            $query = Booking::where('tenant_id', $tenantId)->with(['outlet', 'table', 'customer']);

            if ($outletId) {
                $query->where('outlet_id', $outletId);
            } elseif (! empty($filters['assigned_outlet_ids'])) {
                $query->whereIn('outlet_id', $filters['assigned_outlet_ids']);
            }

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (! empty($filters['table_id'])) {
                $query->where('table_id', $filters['table_id']);
            }

            if (! empty($filters['date'])) {
                $query->whereDate('starts_at', $filters['date']);
            }

            if (! empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('booking_code', 'ilike', "%{$search}%")
                        ->orWhere('customer_name_snapshot', 'ilike', "%{$search}%")
                        ->orWhere('customer_contact_snapshot', 'ilike', "%{$search}%");
                });
            }

            return $query->orderBy('starts_at', 'asc')->paginate($perPage);
        });
    }

    /**
     * Create a new booking with pessimistic locking and overlap assertion.
     */
    public function createBooking(Tenant|string $tenant, string $outletId, array $data, ?string $actorUserId = null): Booking
    {
        $tenantId = $tenant instanceof Tenant ? (string) $tenant->id : $tenant;
        $tenantModel = $tenant instanceof Tenant ? $tenant : Tenant::findOrFail($tenantId);

        // Check subscription entitlement
        $this->entitlementService->assertCan($tenantModel, 'booking');

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $outletId, $data, $actorUserId) {
            return DB::transaction(function () use ($tenantId, $outletId, $data, $actorUserId) {
                $outlet = Outlet::where('tenant_id', $tenantId)->findOrFail($outletId);

                $startsAt = Carbon::parse($data['starts_at']);
                $endsAt = Carbon::parse($data['ends_at'] ?? $startsAt->copy()->addHours(2));

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    throw new \InvalidArgumentException('Booking ends_at must be strictly after starts_at.');
                }

                $tableId = $data['table_id'] ?? null;
                $table = null;

                if ($tableId) {
                    // Pessimistic locking on table to eliminate concurrent booking race
                    $table = Table::where('tenant_id', $tenantId)
                        ->where('outlet_id', $outlet->id)
                        ->where('id', $tableId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (! $table->is_active) {
                        throw new TableUnavailableException("Cannot book inactive table [{$table->code}].");
                    }

                    // Assert overlap
                    $this->assertNoBookingOverlap($tenantId, $table->id, $startsAt, $endsAt);
                }

                $customerId = $data['customer_id'] ?? null;
                $customerName = $data['customer_name'] ?? null;
                $customerContact = $data['customer_contact'] ?? null;

                if ($customerId) {
                    $customer = Customer::where('tenant_id', $tenantId)->findOrFail($customerId);
                    $customerName = $customerName ?? $customer->name;
                    $customerContact = $customerContact ?? $customer->phone ?? $customer->email;
                }

                $bookingCode = 'BKG-'.now()->format('ymd').'-'.strtoupper(Str::random(4));

                $booking = Booking::create([
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outlet->id,
                    'table_id' => $table?->id,
                    'customer_id' => $customerId,
                    'booking_code' => $bookingCode,
                    'customer_name_snapshot' => $customerName ?? 'Tamu Walk-In',
                    'customer_contact_snapshot' => $customerContact,
                    'party_size' => (int) ($data['party_size'] ?? 2),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => $data['status'] ?? Booking::STATUS_CONFIRMED,
                    'notes' => $data['notes'] ?? null,
                ]);

                // Update table status to reserved if starts soon or confirmed
                if ($table && in_array($booking->status, [Booking::STATUS_CONFIRMED, Booking::STATUS_PENDING])) {
                    if ($table->status === Table::STATUS_AVAILABLE) {
                        $table->status = Table::STATUS_RESERVED;
                        $table->save();
                    }
                }

                // Audit log
                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actorUserId,
                    'action' => 'booking_created',
                    'entity_type' => Booking::class,
                    'entity_id' => $booking->id,
                    'after' => $booking->toArray(),
                    'created_at' => now(),
                ]);

                // Notification
                $this->notificationService->createNotification($tenantId, [
                    'type' => Notification::TYPE_BOOKING_CREATED,
                    'title' => 'Reservasi Baru',
                    'message' => "Reservasi {$booking->booking_code} atas nama {$booking->customer_name_snapshot} telah dibuat.",
                    'severity' => Notification::SEVERITY_INFO,
                    'dedup_key' => "booking_created:{$booking->id}",
                    'data' => [
                        'booking_id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'outlet_id' => $outlet->id,
                        'table_id' => $table?->id,
                    ],
                ]);

                return $booking;
            });
        });
    }

    /**
     * Check if a table has overlapping active bookings in the given window.
     */
    public function assertNoBookingOverlap(string $tenantId, string $tableId, Carbon $startsAt, Carbon $endsAt, ?string $excludeBookingId = null): void
    {
        $overlapQuery = Booking::where('tenant_id', $tenantId)
            ->where('table_id', $tableId)
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED, Booking::STATUS_CHECKED_IN])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($excludeBookingId) {
            $overlapQuery->where('id', '!=', $excludeBookingId);
        }

        $overlap = $overlapQuery->first();

        if ($overlap) {
            throw new BookingOverlapException(
                "Table is already booked from {$overlap->starts_at->toDateTimeString()} to {$overlap->ends_at->toDateTimeString()} ({$overlap->booking_code})."
            );
        }
    }

    /**
     * Update booking details.
     */
    public function updateBooking(Booking $booking, array $data, ?string $actorUserId = null): Booking
    {
        return $this->tenantManager->runForTenant($booking->tenant_id, function () use ($booking, $data, $actorUserId) {
            return DB::transaction(function () use ($booking, $data, $actorUserId) {
                $before = $booking->toArray();

                $newStartsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $booking->starts_at;
                $newEndsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : $booking->ends_at;
                $newTableId = array_key_exists('table_id', $data) ? $data['table_id'] : $booking->table_id;

                if ($newTableId && ($newTableId !== $booking->table_id || isset($data['starts_at']) || isset($data['ends_at']))) {
                    Table::where('tenant_id', $booking->tenant_id)
                        ->where('id', $newTableId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $this->assertNoBookingOverlap($booking->tenant_id, $newTableId, $newStartsAt, $newEndsAt, $booking->id);
                    $booking->table_id = $newTableId;
                }

                if (isset($data['party_size'])) {
                    $booking->party_size = (int) $data['party_size'];
                }
                if (isset($data['customer_name'])) {
                    $booking->customer_name_snapshot = $data['customer_name'];
                }
                if (isset($data['customer_contact'])) {
                    $booking->customer_contact_snapshot = $data['customer_contact'];
                }
                if (isset($data['notes'])) {
                    $booking->notes = $data['notes'];
                }
                if (isset($data['status'])) {
                    $booking->status = $data['status'];
                }

                $booking->starts_at = $newStartsAt;
                $booking->ends_at = $newEndsAt;
                $booking->save();

                AuditLog::create([
                    'tenant_id' => $booking->tenant_id,
                    'actor_user_id' => $actorUserId,
                    'action' => 'booking_updated',
                    'entity_type' => Booking::class,
                    'entity_id' => $booking->id,
                    'before' => $before,
                    'after' => $booking->toArray(),
                    'created_at' => now(),
                ]);

                return $booking;
            });
        });
    }

    /**
     * Check in customer arrival for a booking, attaching session and updating table.
     */
    public function checkIn(Booking $booking, ?string $targetTableId = null, ?string $actorUserId = null): TableSession
    {
        return $this->tenantManager->runForTenant($booking->tenant_id, function () use ($booking, $targetTableId, $actorUserId) {
            return DB::transaction(function () use ($booking, $targetTableId, $actorUserId) {
                if (in_array($booking->status, [Booking::STATUS_CANCELLED, Booking::STATUS_COMPLETED, Booking::STATUS_NO_SHOW])) {
                    throw new \InvalidArgumentException("Cannot check in booking with status '{$booking->status}'.");
                }

                $tableId = $targetTableId ?? $booking->table_id;
                if (! $tableId) {
                    throw new \InvalidArgumentException('A table must be assigned to check in this booking.');
                }

                $table = Table::where('tenant_id', $booking->tenant_id)
                    ->where('id', $tableId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $table->is_active) {
                    throw new TableUnavailableException("Cannot check in to inactive table [{$table->code}].");
                }

                $sessionCode = 'SESS-'.now()->format('ymd').'-'.strtoupper(Str::random(4));

                $session = TableSession::create([
                    'tenant_id' => $booking->tenant_id,
                    'outlet_id' => $booking->outlet_id,
                    'table_id' => $table->id,
                    'booking_id' => $booking->id,
                    'session_code' => $sessionCode,
                    'status' => TableSession::STATUS_ACTIVE,
                    'guest_count' => $booking->party_size,
                    'opened_by_user_id' => $actorUserId,
                    'started_at' => now(),
                ]);

                $booking->status = Booking::STATUS_CHECKED_IN;
                $booking->table_id = $table->id;
                $booking->save();

                $table->status = Table::STATUS_OCCUPIED;
                $table->save();

                AuditLog::create([
                    'tenant_id' => $booking->tenant_id,
                    'actor_user_id' => $actorUserId,
                    'action' => 'booking_checked_in',
                    'entity_type' => Booking::class,
                    'entity_id' => $booking->id,
                    'after' => ['table_session_id' => $session->id, 'table_id' => $table->id],
                    'created_at' => now(),
                ]);

                return $session;
            });
        });
    }

    /**
     * Cancel a booking without affecting any historical sales or payments.
     */
    public function cancelBooking(Booking $booking, ?string $reason = null, ?string $actorUserId = null): Booking
    {
        return $this->tenantManager->runForTenant($booking->tenant_id, function () use ($booking, $reason, $actorUserId) {
            return DB::transaction(function () use ($booking, $reason, $actorUserId) {
                $before = $booking->toArray();

                $booking->status = Booking::STATUS_CANCELLED;
                if ($reason) {
                    $booking->notes = trim(($booking->notes ?? '')."\nBatal: {$reason}");
                }
                $booking->save();

                // If table was reserved for this booking, release back to available if no other active bookings
                if ($booking->table_id) {
                    $table = Table::where('id', $booking->table_id)->lockForUpdate()->first();
                    if ($table && $table->status === Table::STATUS_RESERVED) {
                        $otherActive = Booking::where('table_id', $table->id)
                            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
                            ->where('id', '!=', $booking->id)
                            ->exists();

                        if (! $otherActive) {
                            $table->status = Table::STATUS_AVAILABLE;
                            $table->save();
                        }
                    }
                }

                AuditLog::create([
                    'tenant_id' => $booking->tenant_id,
                    'actor_user_id' => $actorUserId,
                    'action' => 'booking_cancelled',
                    'entity_type' => Booking::class,
                    'entity_id' => $booking->id,
                    'before' => $before,
                    'after' => $booking->toArray(),
                    'created_at' => now(),
                ]);

                return $booking;
            });
        });
    }
}
