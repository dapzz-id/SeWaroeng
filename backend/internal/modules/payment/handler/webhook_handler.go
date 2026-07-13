// Neura Cakrawira Solusi | SeWaroeng
// WebhookHandler — HTTP Handlers for Payment Webhooks.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type WebhookHandler struct {
}

func NewWebhookHandler() *WebhookHandler {
	return &WebhookHandler{}
}

// POST /webhooks/midtrans
func (h *WebhookHandler) Midtrans(ctx http.Context) http.Response {
	// Webhook signature validation and order status update logic goes here
	return response.OK(ctx, map[string]string{"status": "ok"})
}

// POST /webhooks/paypal
func (h *WebhookHandler) PayPal(ctx http.Context) http.Response {
	return response.OK(ctx, map[string]string{"status": "ok"})
}

// POST /webhooks/wise
func (h *WebhookHandler) Wise(ctx http.Context) http.Response {
	return response.OK(ctx, map[string]string{"status": "ok"})
}
