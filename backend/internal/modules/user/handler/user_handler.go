// Neura Cakrawira Solusi | SeWaroeng
// UserHandler — HTTP Handlers for User API.
package handler

import (
	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/user/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type UserHandler struct {
	svc service.UserService
}

func NewUserHandler(svc service.UserService) *UserHandler {
	return &UserHandler{svc: svc}
}

// POST /users/staff
func (h *UserHandler) CreateStaff(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)

	var req struct {
		FullName string `json:"full_name" validate:"required"`
		Email    string `json:"email" validate:"required,email"`
		Password string `json:"password" validate:"required,min=6"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	u, err := h.svc.CreateStaff(ctx.Context(), tenantID, req.FullName, req.Email, req.Password)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	// Hide password hash
	u.PasswordHash = ""
	return response.OK(ctx, u)
}

// GET /users
func (h *UserHandler) List(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	users, err := h.svc.ListStaff(ctx.Context(), tenantID)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	// Mask passwords
	for i := range users {
		users[i].PasswordHash = ""
	}

	return response.OK(ctx, users)
}

// DELETE /users/{id}
func (h *UserHandler) Delete(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)
	id := ctx.Request().Input("id")

	if err := h.svc.DeleteStaff(ctx.Context(), tenantID, id); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, nil)
}

// GET /customers/addresses
func (h *UserHandler) ListAddresses(ctx http.Context) http.Response {
	userID := ctx.Value("user_id").(string)
	addrs, err := h.svc.ListAddresses(ctx.Context(), userID)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, addrs)
}

// POST /customers/addresses
func (h *UserHandler) CreateAddress(ctx http.Context) http.Response {
	userID := ctx.Value("user_id").(string)

	var req struct {
		AlamatLengkap string  `json:"alamat_lengkap" validate:"required"`
		Latitude      float64 `json:"latitude" validate:"required"`
		Longitude     float64 `json:"longitude" validate:"required"`
		IsDefault     bool    `json:"is_default"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	a, err := h.svc.AddAddress(ctx.Context(), userID, req.AlamatLengkap, req.Latitude, req.Longitude, req.IsDefault)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}
	return response.OK(ctx, a)
}

// PUT /profile
func (h *UserHandler) UpdateProfile(ctx http.Context) http.Response {
	// Not fully implemented for MVP
	return response.OK(ctx, nil)
}

// GET /profile
func (h *UserHandler) GetProfile(ctx http.Context) http.Response {
	// Not fully implemented for MVP
	return response.OK(ctx, nil)
}

// Additional stubs for router compatibility
func (h *UserHandler) UpdateAddress(ctx http.Context) http.Response { return response.OK(ctx, nil) }
func (h *UserHandler) DeleteAddress(ctx http.Context) http.Response { return response.OK(ctx, nil) }
