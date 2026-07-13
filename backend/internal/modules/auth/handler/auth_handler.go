// Neura Cakrawira Solusi | SeWaroeng
// AuthHandler — HTTP handlers for authentication endpoints.
// Routes: POST /auth/login, /auth/register, /auth/refresh, DELETE /auth/logout
//
//	GET /auth/sessions, DELETE /auth/sessions/{id}
package handler

import (
	"github.com/goravel/framework/contracts/http"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/middleware"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/auth/dto"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/auth/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

const (
	refreshCookieName = "sw_refresh_token"
	refreshCookiePath = "/api/v1/auth"
	cookieMaxAge      = 7 * 24 * 60 * 60 // 7 days in seconds
)

// AuthHandler handles HTTP requests for authentication.
type AuthHandler struct {
	svc service.AuthService
}

// NewAuthHandler creates a new AuthHandler.
func NewAuthHandler(svc service.AuthService) *AuthHandler {
	return &AuthHandler{svc: svc}
}

// Register handles POST /api/v1/auth/register
func (h *AuthHandler) Register(ctx http.Context) http.Response {
	var req dto.RegisterRequest
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "VALIDATION_ERROR", err.Error())
	}

	authResp, refreshToken, err := h.svc.Register(ctx.Context(), &req)
	if err != nil {
		switch err.Error() {
		case "EMAIL_ALREADY_EXISTS":
			return response.Conflict(ctx, "EMAIL_ALREADY_EXISTS", "Email sudah terdaftar.")
		default:
			return response.InternalServerError(ctx, "Gagal membuat akun.")
		}
	}

	h.setRefreshCookie(ctx, refreshToken)
	return response.Created(ctx, authResp)
}

// Login handles POST /api/v1/auth/login
func (h *AuthHandler) Login(ctx http.Context) http.Response {
	var req dto.LoginRequest
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "VALIDATION_ERROR", err.Error())
	}

	deviceInfo := ctx.Request().Header("User-Agent")
	ipAddress := ctx.Request().Ip()
	userAgent := ctx.Request().Header("User-Agent")

	authResp, refreshToken, err := h.svc.Login(ctx.Context(), &req, deviceInfo, ipAddress, userAgent)
	if err != nil {
		return response.Unauthorized(ctx, "INVALID_CREDENTIALS", "Email atau password salah.")
	}

	h.setRefreshCookie(ctx, refreshToken)
	return response.OK(ctx, authResp)
}

// Refresh handles POST /api/v1/auth/refresh
func (h *AuthHandler) Refresh(ctx http.Context) http.Response {
	refreshToken := ctx.Request().Cookie(refreshCookieName)
	if refreshToken == "" {
		return response.Unauthorized(ctx, "REFRESH_TOKEN_MISSING", "Refresh token tidak ditemukan.")
	}

	sessionID, _ := ctx.Value(string(middleware.CtxSessionID)).(string)

	authResp, newRefreshToken, err := h.svc.RefreshToken(ctx.Context(), refreshToken, sessionID)
	if err != nil {
		h.clearRefreshCookie(ctx)
		return response.Unauthorized(ctx, err.Error(), "Sesi tidak valid. Silakan login kembali.")
	}

	h.setRefreshCookie(ctx, newRefreshToken)
	return response.OK(ctx, authResp)
}

// Logout handles DELETE /api/v1/auth/logout
func (h *AuthHandler) Logout(ctx http.Context) http.Response {
	userID, _ := ctx.Value(string(middleware.CtxUserID)).(string)
	sessionID, _ := ctx.Value(string(middleware.CtxSessionID)).(string)

	_ = h.svc.Logout(ctx.Context(), userID, sessionID, "")
	h.clearRefreshCookie(ctx)
	return response.NoContent(ctx)
}

// ListSessions handles GET /api/v1/auth/sessions
func (h *AuthHandler) ListSessions(ctx http.Context) http.Response {
	userID, _ := ctx.Value(string(middleware.CtxUserID)).(string)
	sessionID, _ := ctx.Value(string(middleware.CtxSessionID)).(string)

	sessions, err := h.svc.ListSessions(ctx.Context(), userID, sessionID)
	if err != nil {
		return response.InternalServerError(ctx, "Gagal mengambil daftar sesi.")
	}
	return response.OK(ctx, sessions)
}

// RevokeSession handles DELETE /api/v1/auth/sessions/:id (force logout specific device)
func (h *AuthHandler) RevokeSession(ctx http.Context) http.Response {
	userID, _ := ctx.Value(string(middleware.CtxUserID)).(string)
	targetSessionID := ctx.Request().Route("id")

	if err := h.svc.RevokeSession(ctx.Context(), userID, targetSessionID); err != nil {
		return response.InternalServerError(ctx, "Gagal mencabut sesi.")
	}
	return response.NoContent(ctx)
}

// ─── Cookie Helpers ────────────────────────────────────────────────────────────

func (h *AuthHandler) setRefreshCookie(ctx http.Context, token string) {
	ctx.Response().Cookie(http.Cookie{
		Name:     refreshCookieName,
		Value:    token,
		Path:     refreshCookiePath,
		MaxAge:   cookieMaxAge,
		HttpOnly: true,
		Secure:   true,
		SameSite: "Strict",
	})
}

func (h *AuthHandler) clearRefreshCookie(ctx http.Context) {
	ctx.Response().Cookie(http.Cookie{
		Name:     refreshCookieName,
		Value:    "",
		Path:     refreshCookiePath,
		MaxAge:   -1,
		HttpOnly: true,
		Secure:   true,
		SameSite: "Strict",
	})
}
