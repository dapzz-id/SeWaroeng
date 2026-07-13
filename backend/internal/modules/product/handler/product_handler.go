// Neura Cakrawira Solusi | SeWaroeng
// ProductHandler — HTTP Handlers for Product API.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/product/repository"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/product/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type ProductHandler struct {
	svc service.ProductService
}

func NewProductHandler(svc service.ProductService) *ProductHandler {
	return &ProductHandler{svc: svc}
}

// GET /stores/{id}/products
func (h *ProductHandler) PublicList(ctx http.Context) http.Response {
	storeID := ctx.Request().Route("id")
	// For MVP, passing empty category
	prods, _, err := h.svc.List(ctx.Context(), repository.ProductFilter{TenantID: storeID})
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, prods)
}

// GET /products
func (h *ProductHandler) List(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)

	prods, _, err := h.svc.List(ctx.Context(), repository.ProductFilter{TenantID: tenantID})
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, prods)
}

// POST /products
func (h *ProductHandler) Create(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	var req service.CreateProductRequest
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	prod, err := h.svc.Create(ctx.Context(), tenantID, &req)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, prod)
}

// PUT /products/{id}
func (h *ProductHandler) Update(ctx http.Context) http.Response {
	// Not fully implemented for MVP
	return response.OK(ctx, nil)
}

// DELETE /products/{id}
func (h *ProductHandler) Delete(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	id := ctx.Request().Route("id")
	if err := h.svc.Delete(ctx.Context(), id, tenantID); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, nil)
}

func (h *ProductHandler) Get(ctx http.Context) http.Response { return response.OK(ctx, nil) }
