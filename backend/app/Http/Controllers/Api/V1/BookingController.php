<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    public function __construct(
        protected BookingService $bookingService
    ) {}

    /**
     * List bookings for tenant / outlet.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $outletId = $request->query('outlet_id');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $filters = $request->only(['status', 'table_id', 'date', 'search']);
        if ($outletId) {
            $this->assertOutletAccess($outletId);
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $filters['assigned_outlet_ids'] = $tenantContext->membership()->assigned_outlet_ids ?? [];
        }

        $paginator = $this->bookingService->listBookings($tenantId, $outletId, $filters, $perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn (Booking $b) => $this->formatBooking($b))->all()
        );
    }

    /**
     * Create a new booking.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'outlet_id' => ['required', 'uuid'],
            'table_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_contact' => ['nullable', 'string', 'max:100'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['nullable', 'string', 'in:pending,confirmed'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->assertOutletAccess($validated['outlet_id']);

        $booking = $this->bookingService->createBooking(
            tenant: $tenantId,
            outletId: $validated['outlet_id'],
            data: $validated,
            actorUserId: $request->user()?->id
        );

        return ApiResponse::success(
            data: $this->formatBooking($booking),
            message: 'Booking created successfully.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Show booking details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = Booking::where('tenant_id', $tenantId)
            ->with(['outlet', 'table', 'customer'])
            ->findOrFail($id);

        $this->assertOutletAccess($booking->outlet_id);

        return ApiResponse::success(data: $this->formatBooking($booking));
    }

    /**
     * Update booking details.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = Booking::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($booking->outlet_id);

        $validated = $request->validate([
            'table_id' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_contact' => ['nullable', 'string', 'max:100'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:pending,confirmed,checked_in,cancelled,completed,no_show'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->bookingService->updateBooking($booking, $validated, $request->user()?->id);

        return ApiResponse::success(
            data: $this->formatBooking($updated),
            message: 'Booking updated successfully.'
        );
    }

    /**
     * Check in customer arrival for a booking.
     */
    public function checkIn(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = Booking::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($booking->outlet_id);

        $validated = $request->validate([
            'table_id' => ['nullable', 'uuid'],
        ]);

        $session = $this->bookingService->checkIn(
            $booking,
            $validated['table_id'] ?? null,
            $request->user()?->id
        );

        return ApiResponse::success(
            data: [
                'session' => $session,
                'booking' => $this->formatBooking($booking->fresh(['table'])),
            ],
            message: 'Booking checked in successfully.'
        );
    }

    /**
     * Cancel a booking.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $booking = Booking::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($booking->outlet_id);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $cancelled = $this->bookingService->cancelBooking($booking, $validated['reason'] ?? null, $request->user()?->id);

        return ApiResponse::success(
            data: $this->formatBooking($cancelled),
            message: 'Booking cancelled successfully.'
        );
    }

    protected function assertOutletAccess(string $outletId): void
    {
        $context = app(TenantContext::class);
        if (! $context->hasOutletAccess($outletId)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to this outlet.');
        }
    }

    protected function formatBooking(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'tenant_id' => $booking->tenant_id,
            'outlet_id' => $booking->outlet_id,
            'table_id' => $booking->table_id,
            'customer_id' => $booking->customer_id,
            'booking_code' => $booking->booking_code,
            'customer_name' => $booking->customer_name_snapshot,
            'customer_contact' => $booking->customer_contact_snapshot,
            'party_size' => $booking->party_size,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'status' => $booking->status,
            'notes' => $booking->notes,
            'table' => $booking->table ? [
                'id' => $booking->table->id,
                'code' => $booking->table->code,
                'name' => $booking->table->name,
            ] : null,
            'created_at' => $booking->created_at?->toIso8601String(),
            'updated_at' => $booking->updated_at?->toIso8601String(),
        ];
    }
}
