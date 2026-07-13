// Neura Cakrawira Solusi | SeWaroeng
// OrderHandler — HTTP Handlers for Order API.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/order/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type OrderHandler struct {
	svc service.OrderService
}

func NewOrderHandler(svc service.OrderService) *OrderHandler {
	return &OrderHandler{svc: svc}
}

// POST /orders
func (h *OrderHandler) Create(ctx http.Context) http.Response {
	userID := ctx.Value("user_id").(string)
	tenantID := ctx.Value("tenant_id").(string)

	var req service.CreateOrderRequest
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	req.CustomerID = userID
	req.TenantID = tenantID

	order, err := h.svc.CreateOrder(ctx.Context(), &req)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, order)
}

// GET /orders/{id}
func (h *OrderHandler) Get(ctx http.Context) http.Response {
	id := ctx.Request().Route("id")
	tenantID := ctx.Value("tenant_id").(string)
	order, err := h.svc.GetOrder(ctx.Context(), id, tenantID)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, order)
}

// POST /orders/{id}/cancel
func (h *OrderHandler) Cancel(ctx http.Context) http.Response {
	id := ctx.Request().Route("id")
	userID := ctx.Value("user_id").(string)
	tenantID := ctx.Value("tenant_id").(string)

	if err := h.svc.CancelOrder(ctx.Context(), id, tenantID, userID); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, nil)
}

// PUT /orders/{id}/status
func (h *OrderHandler) UpdateStatus(ctx http.Context) http.Response {
	id := ctx.Request().Route("id")
	tenantID := ctx.Value("tenant_id").(string)

	var req struct {
		Status         string `json:"status" validate:"required"`
		TrackingNumber string `json:"tracking_number"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	if err := h.svc.UpdateStatus(ctx.Context(), id, tenantID, req.Status, req.TrackingNumber); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, nil)
}

func (h *OrderHandler) List(ctx http.Context) http.Response { return response.OK(ctx, []any{}) }
