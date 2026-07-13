// Neura Cakrawira Solusi | SeWaroeng
// AuthService — business logic for 3-token authentication system.
// Implements: login, register, refresh token, logout, force-logout session.
package service

import (
	"context"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"time"

	"github.com/goravel/framework/contracts/database/orm"
	"github.com/goravel/framework/facades"

	redisinf "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/infrastructure/redis"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/auth/dto"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/auth/repository"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/crypto"
	pkgjwt "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/jwt"
	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

const (
	refreshTokenTTL = 7 * 24 * time.Hour // 7 days
	sessionTTL      = 7 * 24 * time.Hour // 7 days
	accessTokenTTL  = 15 * 60            // 15 minutes in seconds
)

// AuthService defines auth business logic contract.
type AuthService interface {
	Register(ctx context.Context, req *dto.RegisterRequest) (*dto.AuthResponse, string, error)
	Login(ctx context.Context, req *dto.LoginRequest, deviceInfo, ipAddress, userAgent string) (*dto.AuthResponse, string, error)
	RefreshToken(ctx context.Context, refreshTokenCookie, currentSessionID string) (*dto.AuthResponse, string, error)
	Logout(ctx context.Context, userID, sessionID, refreshTokenHash string) error
	ListSessions(ctx context.Context, userID, currentSessionID string) ([]dto.SessionInfo, error)
	RevokeSession(ctx context.Context, userID, sessionID string) error
}

type authService struct {
	repo       repository.AuthRepository
	redis      *redisinf.Client
	jwtManager *pkgjwt.Manager
	db         orm.Orm
}

// NewAuthService creates an AuthService with all dependencies.
func NewAuthService(repo repository.AuthRepository, redis *redisinf.Client, jwtManager *pkgjwt.Manager, db orm.Orm) AuthService {
	return &authService{repo: repo, redis: redis, jwtManager: jwtManager, db: db}
}

// Register creates a new customer account and returns auth tokens.
func (s *authService) Register(ctx context.Context, req *dto.RegisterRequest) (*dto.AuthResponse, string, error) {
	// Check email uniqueness
	existing, _ := s.repo.FindUserByEmail(ctx, req.Email)
	if existing != nil {
		return nil, "", fmt.Errorf("EMAIL_ALREADY_EXISTS")
	}

	hash, err := crypto.HashPassword(req.Password)
	if err != nil {
		return nil, "", fmt.Errorf("failed to hash password: %w", err)
	}

	userID, _ := pkguuid.NewV7()
	user := &repository.User{
		ID:           userID,
		Email:        req.Email,
		PasswordHash: hash,
		FullName:     req.FullName,
		Role:         "customer",
	}

	if err := s.repo.CreateUser(ctx, user); err != nil {
		return nil, "", fmt.Errorf("failed to create user: %w", err)
	}

	// Auto-create wallet in a transaction
	_ = s.db.Transaction(func(tx orm.Query) error {
		walletID, _ := pkguuid.NewV7()
		_, err := tx.Exec(
			"INSERT INTO wallets (id, user_id, balance) VALUES (?, ?, 0)", walletID, userID)
		return err
	})

	return s.issueTokens(ctx, user, "", "", "")
}

// Login authenticates a user and issues 3 tokens.
func (s *authService) Login(ctx context.Context, req *dto.LoginRequest, deviceInfo, ipAddress, userAgent string) (*dto.AuthResponse, string, error) {
	user, err := s.repo.FindUserByEmail(ctx, req.Email)
	if err != nil || user == nil {
		return nil, "", fmt.Errorf("INVALID_CREDENTIALS")
	}
	if !crypto.CheckPassword(req.Password, user.PasswordHash) {
		return nil, "", fmt.Errorf("INVALID_CREDENTIALS")
	}

	return s.issueTokens(ctx, user, deviceInfo, ipAddress, userAgent)
}

// issueTokens generates the 3-token set and stores session in DB + Redis.
func (s *authService) issueTokens(ctx context.Context, user *repository.User, deviceInfo, ipAddress, userAgent string) (*dto.AuthResponse, string, error) {
	// 1. Session Token (UUID → Redis)
	sessionID, _ := pkguuid.NewV7()

	tenantID := ""
	if user.TenantID != nil {
		tenantID = *user.TenantID
	}

	// 2. Access Token (JWT, 15 min)
	accessToken, err := s.jwtManager.Generate(user.ID, tenantID, user.Role, sessionID)
	if err != nil {
		return nil, "", fmt.Errorf("failed to generate access token: %w", err)
	}

	// 3. Refresh Token (opaque UUID, 7 days → DB hash + HTTP-only cookie)
	rawRefresh, _ := crypto.GenerateToken(32)
	refreshHash := fmt.Sprintf("%x", sha256.Sum256([]byte(rawRefresh)))

	// Store session metadata in Redis
	sessionMeta, _ := json.Marshal(map[string]any{
		"user_id":    user.ID,
		"device":     deviceInfo,
		"ip":         ipAddress,
		"user_agent": userAgent,
		"created_at": time.Now().UTC().Format(time.RFC3339),
	})
	if err := s.redis.SetSession(ctx, user.ID, sessionID, string(sessionMeta), sessionTTL); err != nil {
		facades.Log().Warningf("AuthService: failed to store session in Redis: %v", err)
	}

	// Store refresh token hash in DB
	sessionID_db, _ := pkguuid.NewV7()
	dbSession := &repository.Session{
		ID:               sessionID_db,
		UserID:           user.ID,
		RefreshTokenHash: refreshHash,
		IPAddress:        ipAddress,
		UserAgent:        userAgent,
		ExpiresAt:        time.Now().Add(refreshTokenTTL),
	}
	if err := s.repo.CreateSession(ctx, dbSession); err != nil {
		facades.Log().Warningf("AuthService: failed to store session in DB: %v", err)
	}

	return &dto.AuthResponse{
		AccessToken: accessToken,
		TokenType:   "Bearer",
		ExpiresIn:   accessTokenTTL,
		User: dto.UserSummary{
			ID:       user.ID,
			FullName: user.FullName,
			Email:    user.Email,
			Role:     user.Role,
			TenantID: tenantID,
		},
	}, rawRefresh, nil // rawRefresh sent as HTTP-only cookie
}

// RefreshToken validates refresh cookie, rotates tokens.
func (s *authService) RefreshToken(ctx context.Context, rawRefreshToken, currentSessionID string) (*dto.AuthResponse, string, error) {
	hash := fmt.Sprintf("%x", sha256.Sum256([]byte(rawRefreshToken)))
	dbSession, err := s.repo.FindSessionByRefreshTokenHash(ctx, hash)
	if err != nil || dbSession == nil {
		return nil, "", fmt.Errorf("INVALID_REFRESH_TOKEN")
	}

	// Verify session still active in Redis
	exists, _ := s.redis.Exists(ctx, redisinf.SessionKey(dbSession.UserID, currentSessionID))
	if !exists {
		return nil, "", fmt.Errorf("SESSION_REVOKED")
	}

	user, err := s.repo.FindUserByID(ctx, dbSession.UserID)
	if err != nil {
		return nil, "", fmt.Errorf("USER_NOT_FOUND")
	}

	// Delete old session (rotation)
	_ = s.repo.DeleteSession(ctx, dbSession.ID)
	_ = s.redis.DeleteSession(ctx, dbSession.UserID, currentSessionID)

	return s.issueTokens(ctx, user, "", "", "")
}

// Logout removes the session from Redis and DB.
func (s *authService) Logout(ctx context.Context, userID, sessionID, refreshTokenHash string) error {
	_ = s.redis.DeleteSession(ctx, userID, sessionID)
	if refreshTokenHash != "" {
		_ = s.repo.DeleteSession(ctx, refreshTokenHash)
	}
	return nil
}

// ListSessions returns all active sessions for a user.
func (s *authService) ListSessions(ctx context.Context, userID, currentSessionID string) ([]dto.SessionInfo, error) {
	dbSessions, err := s.repo.ListUserSessions(ctx, userID)
	if err != nil {
		return nil, err
	}

	result := make([]dto.SessionInfo, 0, len(dbSessions))
	for _, s := range dbSessions {
		result = append(result, dto.SessionInfo{
			SessionID: s.ID,
			IPAddress: s.IPAddress,
			UserAgent: s.UserAgent,
			CreatedAt: s.CreatedAt.Format(time.RFC3339),
			IsCurrent: s.ID == currentSessionID,
		})
	}
	return result, nil
}

// RevokeSession force-logouts a specific session.
func (s *authService) RevokeSession(ctx context.Context, userID, sessionID string) error {
	_ = s.redis.DeleteSession(ctx, userID, sessionID)
	return s.repo.DeleteSession(ctx, sessionID)
}
