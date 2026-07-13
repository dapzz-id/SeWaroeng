// Neura Cakrawira Solusi | SeWaroeng
// Package response provides standardized API response helpers.
package response

import (
	"fmt"
	"github.com/goravel/framework/contracts/http"
)

// ─── Response Envelope ────────────────────────────────────────────────────────

// Success represents a successful API response.
type Success struct {
	Success bool        `json:"success"`
	Data    interface{} `json:"data"`
	Meta    *Meta       `json:"meta,omitempty"`
}

// Error represents a failed API response.
type ErrorEnvelope struct {
	Success bool       `json:"success"`
	Error   *ErrorBody `json:"error"`
}

// ErrorBody holds error detail.
type ErrorBody struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

// Meta holds pagination metadata.
type Meta struct {
	Page       int   `json:"page"`
	Limit      int   `json:"limit"`
	Total      int64 `json:"total"`
	TotalPages int   `json:"total_pages"`
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

// OK returns 200 with data.
func OK(ctx http.Context, data interface{}) http.Response {
	return ctx.Response().Json(200, &Success{
		Success: true,
		Data:    data,
	})
}

// OKWithMeta returns 200 with data and pagination meta.
func OKWithMeta(ctx http.Context, data interface{}, meta *Meta) http.Response {
	return ctx.Response().Json(200, &Success{
		Success: true,
		Data:    data,
		Meta:    meta,
	})
}

// Created returns 201 with data.
func Created(ctx http.Context, data interface{}) http.Response {
	return ctx.Response().Json(201, &Success{
		Success: true,
		Data:    data,
	})
}

// NoContent returns 204.
func NoContent(ctx http.Context) http.Response {
	return ctx.Response().NoContent(204)
}

// BadRequest returns 400 with error code and message.
func BadRequest(ctx http.Context, code, message string) http.Response {
	return ctx.Response().Json(400, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: code, Message: message},
	})
}

// Unauthorized returns 401.
func Unauthorized(ctx http.Context, code, message string) http.Response {
	return ctx.Response().Json(401, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: code, Message: message},
	})
}

// Forbidden returns 403.
func Forbidden(ctx http.Context, message string) http.Response {
	return ctx.Response().Json(403, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: "FORBIDDEN", Message: message},
	})
}

// NotFound returns 404.
func NotFound(ctx http.Context, message string) http.Response {
	return ctx.Response().Json(404, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: "NOT_FOUND", Message: message},
	})
}

// Conflict returns 409.
func Conflict(ctx http.Context, code, message string) http.Response {
	return ctx.Response().Json(409, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: code, Message: message},
	})
}

// UnprocessableEntity returns 422.
func UnprocessableEntity(ctx http.Context, code, message string) http.Response {
	return ctx.Response().Json(422, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: code, Message: message},
	})
}

// TooManyRequests returns 429.
func TooManyRequests(ctx http.Context, retryAfter int) http.Response {
	ctx.Response().Header("Retry-After", fmt.Sprintf("%d", retryAfter))
	return ctx.Response().Json(429, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: "RATE_LIMIT_EXCEEDED", Message: "Terlalu banyak permintaan. Coba lagi nanti."},
	})
}

// InternalServerError returns 500.
func InternalServerError(ctx http.Context, message string) http.Response {
	return ctx.Response().Json(500, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: "INTERNAL_SERVER_ERROR", Message: message},
	})
}

// PaymentRequired returns 402.
func PaymentRequired(ctx http.Context, code, message string) http.Response {
	return ctx.Response().Json(402, &ErrorEnvelope{
		Success: false,
		Error:   &ErrorBody{Code: code, Message: message},
	})
}
