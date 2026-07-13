// Neura Cakrawira Solusi | SeWaroeng
// WalletHandler — HTTP Handlers for Wallet API.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/wallet/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type WalletHandler struct {
	svc service.WalletService
}

func NewWalletHandler(svc service.WalletService) *WalletHandler {
	return &WalletHandler{svc: svc}
}

// GET /wallet
func (h *WalletHandler) GetBalance(ctx http.Context) http.Response {
	userID := ctx.Value("user_id").(string)

	balance, err := h.svc.GetBalance(ctx.Context(), userID)
	if err != nil {
		return response.NotFound(ctx, "Wallet not found")
	}

	return response.OK(ctx, map[string]any{"balance": balance})
}

// POST /wallet/topup
func (h *WalletHandler) TopUp(ctx http.Context) http.Response {
	userID := ctx.Value("user_id").(string)
	var req struct {
		Amount float64 `json:"amount" validate:"required,min=10000"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	if err := h.svc.TopUp(ctx.Context(), userID, req.Amount, "TOPUP_GATEWAY"); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, nil)
}

// GET /wallet/transactions
func (h *WalletHandler) ListTransactions(ctx http.Context) http.Response {
	userID := ctx.Value("user_id").(string)

	txs, err := h.svc.GetHistory(ctx.Context(), userID)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, txs)
}
