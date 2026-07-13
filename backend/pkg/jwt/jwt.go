// Neura Cakrawira Solusi | SeWaroeng
// Package jwt provides JWT Access Token generation and validation.
package jwt

import (
	"fmt"
	"time"

	"github.com/golang-jwt/jwt/v5"
)

// Claims represents SeWaroeng JWT payload.
type Claims struct {
	UserID    string `json:"user_id"`
	TenantID  string `json:"tenant_id,omitempty"`
	Role      string `json:"role"`
	SessionID string `json:"session_id"`
	jwt.RegisteredClaims
}

// Manager handles JWT generation and parsing.
type Manager struct {
	secret    []byte
	ttlMinutes int
	issuer    string
}

// NewManager creates a JWT Manager.
func NewManager(secret string, ttlMinutes int) *Manager {
	return &Manager{
		secret:     []byte(secret),
		ttlMinutes: ttlMinutes,
		issuer:     "sewaroeng",
	}
}

// Generate creates a signed JWT Access Token.
func (m *Manager) Generate(userID, tenantID, role, sessionID string) (string, error) {
	now := time.Now()
	claims := &Claims{
		UserID:    userID,
		TenantID:  tenantID,
		Role:      role,
		SessionID: sessionID,
		RegisteredClaims: jwt.RegisteredClaims{
			Issuer:    m.issuer,
			Subject:   userID,
			IssuedAt:  jwt.NewNumericDate(now),
			ExpiresAt: jwt.NewNumericDate(now.Add(time.Duration(m.ttlMinutes) * time.Minute)),
		},
	}

	token := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)
	signed, err := token.SignedString(m.secret)
	if err != nil {
		return "", fmt.Errorf("jwt: failed to sign token: %w", err)
	}
	return signed, nil
}

// Parse validates a JWT and returns its claims.
func (m *Manager) Parse(tokenStr string) (*Claims, error) {
	token, err := jwt.ParseWithClaims(tokenStr, &Claims{}, func(t *jwt.Token) (interface{}, error) {
		if _, ok := t.Method.(*jwt.SigningMethodHMAC); !ok {
			return nil, fmt.Errorf("jwt: unexpected signing method: %v", t.Header["alg"])
		}
		return m.secret, nil
	}, jwt.WithIssuer(m.issuer), jwt.WithExpirationRequired())

	if err != nil {
		return nil, fmt.Errorf("jwt: invalid token: %w", err)
	}

	claims, ok := token.Claims.(*Claims)
	if !ok || !token.Valid {
		return nil, fmt.Errorf("jwt: token claims invalid")
	}
	return claims, nil
}
