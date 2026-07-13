// Neura Cakrawira Solusi | SeWaroeng
// Auth module DTOs — request and response shapes for authentication endpoints.
package dto

// ─── Requests ─────────────────────────────────────────────────────────────────

type RegisterRequest struct {
	FullName string `json:"full_name" validate:"required,min=2,max=255"`
	Email    string `json:"email"     validate:"required,email"`
	Password string `json:"password"  validate:"required,min=8"`
	Phone    string `json:"phone"     validate:"omitempty,e164"`
}

type LoginRequest struct {
	Email    string `json:"email"    validate:"required,email"`
	Password string `json:"password" validate:"required"`
}

type RefreshRequest struct {
	// Refresh token is read from HTTP-only cookie, not request body
}

// ─── Responses ────────────────────────────────────────────────────────────────

type AuthResponse struct {
	AccessToken string      `json:"access_token"`
	TokenType   string      `json:"token_type"`
	ExpiresIn   int         `json:"expires_in"` // seconds
	User        UserSummary `json:"user"`
}

type UserSummary struct {
	ID       string `json:"id"`
	FullName string `json:"full_name"`
	Email    string `json:"email"`
	Role     string `json:"role"`
	TenantID string `json:"tenant_id,omitempty"`
}

type SessionInfo struct {
	SessionID  string `json:"session_id"`
	DeviceInfo any    `json:"device_info"`
	IPAddress  string `json:"ip_address"`
	UserAgent  string `json:"user_agent"`
	CreatedAt  string `json:"created_at"`
	IsCurrent  bool   `json:"is_current"`
}
