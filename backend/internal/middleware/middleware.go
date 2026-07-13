// Neura Cakrawira Solusi | SeWaroeng
// Middleware implementations for API Gateway (Auth, Tenant, Rate Limit).
package middleware

import (
	"strings"

	"github.com/goravel/framework/contracts/http"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/infrastructure/redis"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/jwt"
)

type ContextKey string

const (
	CtxSessionID ContextKey = "session_id"
	CtxUserID    ContextKey = "user_id"
	CtxTenantID  ContextKey = "tenant_id"
	CtxRole      ContextKey = "role"
)

// ─── Auth Middleware ─────────────────────────────────────────────────────────

type authMiddleware struct {
	jwtManager *jwt.Manager
	redis      *redis.Client
}

func NewAuthMiddleware(jwtManager *jwt.Manager, redis *redis.Client) http.Middleware {
	return &authMiddleware{jwtManager: jwtManager, redis: redis}
}

func (m *authMiddleware) Signature() string {
	return "auth"
}

func (m *authMiddleware) Handle(ctx http.Context) {
	authHeader := ctx.Request().Header("Authorization", "")
	if authHeader == "" || !strings.HasPrefix(authHeader, "Bearer ") {
		ctx.Request().AbortWithStatusJson(401, map[string]any{"error": "UNAUTHORIZED", "message": "Missing or invalid token"})
		return
	}

	tokenStr := strings.TrimPrefix(authHeader, "Bearer ")
	claims, err := m.jwtManager.Parse(tokenStr)
	if err != nil {
		ctx.Request().AbortWithStatusJson(401, map[string]any{"error": "UNAUTHORIZED", "message": "Invalid or expired token"})
		return
	}

	// Verify session still exists in Redis (not revoked or logged out)
	_, err = m.redis.GetSession(ctx.Context(), claims.UserID, claims.SessionID)
	if err != nil {
		ctx.Request().AbortWithStatusJson(401, map[string]any{"error": "SESSION_EXPIRED", "message": "Session has expired or been revoked"})
		return
	}

	// Inject claims into context
	ctx.WithValue(string(CtxUserID), claims.UserID)
	ctx.WithValue(string(CtxTenantID), claims.TenantID)
	ctx.WithValue(string(CtxRole), claims.Role)
	ctx.WithValue(string(CtxSessionID), claims.SessionID)

	// Keep for backward compatibility with existing handlers that expect string keys
	ctx.WithValue("user_id", claims.UserID)
	ctx.WithValue("tenant_id", claims.TenantID)
	ctx.WithValue("role", claims.Role)

	ctx.Request().Next()
}

// ─── Tenant Middleware ───────────────────────────────────────────────────────

type tenantMiddleware struct{}

func NewTenantMiddleware() http.Middleware {
	return &tenantMiddleware{}
}

func (m *tenantMiddleware) Signature() string {
	return "tenant"
}

func (m *tenantMiddleware) Handle(ctx http.Context) {
	tenantID := ctx.Value("tenant_id")
	if tenantID == nil || tenantID.(string) == "" {
		ctx.Request().AbortWithStatusJson(403, map[string]any{"error": "FORBIDDEN", "message": "Tenant context required"})
		return
	}

	// For a complete implementation, you could query the DB here to check if the tenant is active/suspended.
	// We'll skip DB query here for performance, relying on token validity.

	ctx.Request().Next()
}

// ─── Rate Limit Middleware ───────────────────────────────────────────────────

type rateLimitAuthMiddleware struct {
	redis *redis.Client
}

func NewRateLimitAuthMiddleware(redis *redis.Client) http.Middleware {
	return &rateLimitAuthMiddleware{redis: redis}
}

func (m *rateLimitAuthMiddleware) Signature() string {
	return "rate_limit_auth"
}

func (m *rateLimitAuthMiddleware) Handle(ctx http.Context) {
	ip := ctx.Request().Ip()
	if ip == "" {
		ip = "unknown"
	}

	// Limit: 5 requests per 60 seconds per IP for auth endpoints
	res, err := m.redis.CheckRateLimit(ctx.Context(), "auth:"+ip, 5, 60)
	if err != nil {
		// Proceed on Redis error to not block legitimate traffic during cache issues
		ctx.Request().Next()
		return
	}

	if !res.Allowed {
		ctx.Request().AbortWithStatusJson(429, map[string]any{
			"error":       "TOO_MANY_REQUESTS",
			"message":     "Please try again later",
			"retry_after": res.RetryAfter,
		})
		return
	}

	ctx.Request().Next()
}

// ─── Role Middleware ─────────────────────────────────────────────────────────

type roleMiddleware struct {
	allowedRoles []string
}

func NewRoleMiddleware(roles ...string) http.Middleware {
	return &roleMiddleware{allowedRoles: roles}
}

func (m *roleMiddleware) Signature() string {
	// A generic signature, in Goravel you typically register it globally or via strings like "role:superadmin"
	// But as a struct middleware we'll just name it role. If parameterized, Goravel supports args in routes.
	return "role"
}

func (m *roleMiddleware) Handle(ctx http.Context) {
	userRole := ctx.Value(string(CtxRole))
	if userRole == nil {
		ctx.Request().AbortWithStatusJson(403, map[string]any{"error": "FORBIDDEN", "message": "Role context required"})
		return
	}

	roleStr, ok := userRole.(string)
	if !ok {
		ctx.Request().AbortWithStatusJson(403, map[string]any{"error": "FORBIDDEN", "message": "Invalid role format"})
		return
	}

	allowed := false
	for _, r := range m.allowedRoles {
		if r == roleStr {
			allowed = true
			break
		}
	}

	if !allowed {
		ctx.Request().AbortWithStatusJson(403, map[string]any{"error": "FORBIDDEN", "message": "Insufficient permissions"})
		return
	}

	ctx.Request().Next()
}
