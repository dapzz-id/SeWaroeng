// Neura Cakrawira Solusi | SeWaroeng
// ShippingHandler — HTTP Handlers for Shipping API.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/shipping/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type ShippingHandler struct {
	svc service.ShippingService
}

func NewShippingHandler(svc service.ShippingService) *ShippingHandler {
	return &ShippingHandler{svc: svc}
}

// POST /shipping/calculate
func (h *ShippingHandler) Calculate(ctx http.Context) http.Response {
	var req struct {
		TenantID  string  `json:"tenant_id" validate:"required"`
		Latitude  float64 `json:"latitude" validate:"required"`
		Longitude float64 `json:"longitude" validate:"required"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	couriers, err := h.svc.CalculateShipping(ctx.Context(), req.TenantID, service.Coordinates{
		Latitude:  req.Latitude,
		Longitude: req.Longitude,
	})
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, couriers)
}

// POST /store/couriers
func (h *ShippingHandler) CreateCourier(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)

	// Create courier implementation stub
	_ = tenantID
	return response.OK(ctx, nil)
}
