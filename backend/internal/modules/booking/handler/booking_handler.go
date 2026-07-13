// Neura Cakrawira Solusi | SeWaroeng
// BookingHandler — HTTP Handlers for Bookings and Crowd Level.
package handler

import (
	"time"

	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/booking/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type BookingHandler struct {
	svc service.BookingService
}

func NewBookingHandler(svc service.BookingService) *BookingHandler {
	return &BookingHandler{svc: svc}
}

// POST /bookings
func (h *BookingHandler) CreateBooking(ctx http.Context) http.Response {
	var req struct {
		StoreID     string `json:"store_id" validate:"required"`
		Pax         int    `json:"pax" validate:"required,min=1"`
		BookingTime string `json:"booking_time" validate:"required"` // ISO8601
		Notes       string `json:"notes"`
	}

	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	userID := ctx.Value("user_id").(string)

	t, err := time.Parse(time.RFC3339, req.BookingTime)
	if err != nil {
		return response.BadRequest(ctx, "INVALID_DATE", "Format waktu tidak valid (gunakan ISO8601/RFC3339).")
	}

	b, err := h.svc.CreateBooking(ctx.Context(), userID, req.StoreID, req.Pax, t, req.Notes)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.Created(ctx, b)
}

// GET /store/bookings
func (h *BookingHandler) ListBookings(ctx http.Context) http.Response {
	// Usually for seller
	_ = ctx.Value("tenant_id").(string)
	// Actually we need storeID from tenantID, in real app, we get it via TenantService.
	// For simplicity, let's assume client passes store_id or we get it.
	// But let's say it's passed as query parameter or we assume 1 store per tenant.
	storeID := ctx.Request().Query("store_id", "")
	if storeID == "" {
		// If store_id not provided, this needs DB lookup. For now, require it.
		return response.BadRequest(ctx, "INVALID_REQUEST", "store_id is required")
	}

	// Just for PoC - in real prod we must verify tenant owns this store.

	bookings, err := h.svc.GetBookings(ctx.Context(), storeID)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, bookings)
}

// PUT /bookings/{id}/approve
func (h *BookingHandler) ApproveBooking(ctx http.Context) http.Response {
	id := ctx.Request().Route("id")
	if err := h.svc.ApproveBooking(ctx.Context(), id); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, nil)
}

// PUT /bookings/{id}/reject
func (h *BookingHandler) RejectBooking(ctx http.Context) http.Response {
	id := ctx.Request().Route("id")
	if err := h.svc.RejectBooking(ctx.Context(), id); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, nil)
}

// GET /stores/{id}/crowd-level
func (h *BookingHandler) GetCrowdLevel(ctx http.Context) http.Response {
	storeID := ctx.Request().Route("id")
	if storeID == "" {
		return response.BadRequest(ctx, "INVALID_REQUEST", "Store ID is required")
	}

	result, err := h.svc.GetCrowdLevel(ctx.Context(), storeID)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, result)
}
