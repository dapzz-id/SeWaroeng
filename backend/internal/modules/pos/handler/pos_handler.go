// Neura Cakrawira Solusi | SeWaroeng
// POSHandler — HTTP Handlers for POS API.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/pos/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type POSHandler struct {
	svc service.POSService
}

func NewPOSHandler(svc service.POSService) *POSHandler {
	return &POSHandler{svc: svc}
}

// POST /pos/transactions
func (h *POSHandler) CreateTransaction(ctx http.Context) http.Response {
	// Not fully implemented for MVP
	return response.OK(ctx, nil)
}

// POST /pos/sync
func (h *POSHandler) SyncOffline(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	staffID := ctx.Value("user_id").(string)

	var req struct {
		Transactions []struct {
			ID        string  `json:"id" validate:"required"`
			Total     float64 `json:"total" validate:"required"`
			Bayar     float64 `json:"bayar" validate:"required"`
			Kembalian float64 `json:"kembalian"`
			Metode    string  `json:"metode" validate:"required"`
			Items     []struct {
				ProductID string  `json:"product_id" validate:"required"`
				Qty       int     `json:"qty" validate:"required,min=1"`
				Harga     float64 `json:"harga" validate:"required"`
			} `json:"items" validate:"required,min=1"`
		} `json:"transactions" validate:"required"`
	}

	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	// For MVP: mapping into CreatePOSTransactionRequest array
	var txs []service.CreatePOSTransactionRequest
	for _, t := range req.Transactions {
		var items []service.POSItem
		for _, it := range t.Items {
			items = append(items, service.POSItem{
				ProductID: it.ProductID,
				Quantity:  it.Qty,
			})
		}
		txs = append(txs, service.CreatePOSTransactionRequest{
			TenantID:   tenantID,
			StaffID:    staffID,
			Items:      items,
			AmountPaid: t.Bayar,
			Method:     t.Metode,
		})
	}

	if len(txs) > 0 {
		err := h.svc.SyncOfflineTransactions(ctx.Context(), tenantID, txs)
		if err != nil {
			return response.InternalServerError(ctx, err.Error())
		}
	}

	return response.OK(ctx, map[string]string{"status": "synced"})
}
