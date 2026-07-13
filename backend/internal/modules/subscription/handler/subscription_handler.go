// Neura Cakrawira Solusi | SeWaroeng
// SubscriptionHandler — HTTP Handlers for SaaS subscriptions & token quota.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/subscription/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type SubscriptionHandler struct {
	svc service.SubscriptionService
}

func NewSubscriptionHandler(svc service.SubscriptionService) *SubscriptionHandler {
	return &SubscriptionHandler{svc: svc}
}

// GET /subscription
func (h *SubscriptionHandler) GetStatus(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)

	sub, err := h.svc.GetSubscriptionStatus(ctx.Context(), tenantID)
	if err != nil {
		return response.NotFound(ctx, "Belum ada langganan aktif.")
	}

	// Also get token quota
	quota, _ := h.svc.GetTokenQuota(ctx.Context(), tenantID)

	return response.OK(ctx, map[string]any{
		"subscription": sub,
		"token_quota":  quota,
	})
}

// POST /subscription/subscribe
func (h *SubscriptionHandler) Subscribe(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	var req struct {
		PaymentRef string `json:"payment_ref" validate:"required"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	sub, err := h.svc.CreateSubscription(ctx.Context(), tenantID, req.PaymentRef)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.Created(ctx, sub)
}

// POST /subscription/renew
func (h *SubscriptionHandler) Renew(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	var req struct {
		PaymentRef string `json:"payment_ref" validate:"required"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	sub, err := h.svc.RenewSubscription(ctx.Context(), tenantID, req.PaymentRef)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, sub)
}

// GET /subscription/token-quota
func (h *SubscriptionHandler) GetTokenQuota(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)

	quota, err := h.svc.GetTokenQuota(ctx.Context(), tenantID)
	if err != nil {
		return response.NotFound(ctx, "Kuota token AI belum diinisialisasi.")
	}

	remaining := quota.TotalTokens - quota.UsedTokens
	return response.OK(ctx, map[string]any{
		"total_tokens":     quota.TotalTokens,
		"used_tokens":      quota.UsedTokens,
		"remaining_tokens": remaining,
		"reset_at":         quota.ResetAt,
	})
}
