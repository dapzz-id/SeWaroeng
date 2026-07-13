// Neura Cakrawira Solusi | SeWaroeng
// TenantHandler — HTTP Handlers for Tenant API.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/tenant/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type TenantHandler struct {
	svc service.TenantService
}

func NewTenantHandler(svc service.TenantService) *TenantHandler {
	return &TenantHandler{svc: svc}
}

// POST /tenants
func (h *TenantHandler) Register(ctx http.Context) http.Response {
	var req struct {
		Name        string `json:"name" validate:"required"`
		StoreName   string `json:"store_name" validate:"required"`
		StoreType   string `json:"store_type" validate:"required"` // warung, coffeeshop, grosir, minimarket
		MaxCapacity int    `json:"max_capacity"`
		KTPUrl      string `json:"ktp_url" validate:"required"`
		NIBUrl      string `json:"nib_url"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	// Dynamic KYC Validation
	if req.StoreType == "coffeeshop" || req.StoreType == "minimarket" || req.StoreType == "grosir" {
		if req.NIBUrl == "" {
			return response.BadRequest(ctx, "MISSING_KYC", "NIB (Nomor Induk Berusaha) wajib diunggah untuk tipe toko ini.")
		}
	}

	t, st, err := h.svc.RegisterTenant(ctx.Context(), req.Name, req.StoreName, req.StoreType, req.MaxCapacity, req.KTPUrl, req.NIBUrl)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, map[string]any{
		"tenant": t,
		"store":  st,
	})
}

// GET /store/profile
func (h *TenantHandler) GetProfile(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	st, err := h.svc.GetStoreProfile(ctx.Context(), tenantID)
	if err != nil {
		return response.NotFound(ctx, "Store not found")
	}
	return response.OK(ctx, st)
}

// PUT /store/profile
func (h *TenantHandler) UpdateProfile(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	var req struct {
		Name        string `json:"name" validate:"required"`
		Description string `json:"description"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	if err := h.svc.UpdateStoreProfile(ctx.Context(), tenantID, req.Name, req.Description); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, nil)
}
