// Neura Cakrawira Solusi | SeWaroeng
// AuthRepository — database + Redis operations for authentication.
package repository

import (
	"context"
	"time"

	"github.com/goravel/framework/contracts/database/orm"
)

// Session represents the DB session record.
type Session struct {
	ID               string    `gorm:"type:uuid;primaryKey"`
	UserID           string    `gorm:"type:uuid;not null;index"`
	RefreshTokenHash string    `gorm:"not null;uniqueIndex"`
	DeviceInfo       any       `gorm:"type:jsonb"`
	IPAddress        string    `gorm:"type:inet"`
	UserAgent        string
	ExpiresAt        time.Time `gorm:"not null;index"`
	CreatedAt        time.Time
}

func (Session) TableName() string { return "sessions" }

// User is the minimal user shape needed by auth.
type User struct {
	ID           string     `gorm:"type:uuid;primaryKey"`
	TenantID     *string    `gorm:"type:uuid"`
	Email        string     `gorm:"uniqueIndex;not null"`
	PasswordHash string     `gorm:"not null"`
	FullName     string     `gorm:"not null"`
	Role         string     `gorm:"not null"`
	DeletedAt    *time.Time `gorm:"index"`
	CreatedAt    time.Time
	UpdatedAt    time.Time
}

func (User) TableName() string { return "users" }

// AuthRepository defines the data access contract for auth operations.
type AuthRepository interface {
	FindUserByEmail(ctx context.Context, email string) (*User, error)
	FindUserByID(ctx context.Context, id string) (*User, error)
	CreateUser(ctx context.Context, user *User) error
	CreateSession(ctx context.Context, session *Session) error
	FindSessionByRefreshTokenHash(ctx context.Context, hash string) (*Session, error)
	DeleteSession(ctx context.Context, sessionID string) error
	DeleteAllUserSessions(ctx context.Context, userID string) error
	ListUserSessions(ctx context.Context, userID string) ([]Session, error)
}

// authRepository is the Goravel ORM implementation.
type authRepository struct {
	db orm.Orm
}

// NewAuthRepository creates a new AuthRepository.
func NewAuthRepository(db orm.Orm) AuthRepository {
	return &authRepository{db: db}
}

func (r *authRepository) FindUserByEmail(ctx context.Context, email string) (*User, error) {
	var user User
	if err := r.db.Query().Where("email = ? AND deleted_at IS NULL", email).First(&user); err != nil {
		return nil, err
	}
	return &user, nil
}

func (r *authRepository) FindUserByID(ctx context.Context, id string) (*User, error) {
	var user User
	if err := r.db.Query().Where("id = ? AND deleted_at IS NULL", id).First(&user); err != nil {
		return nil, err
	}
	return &user, nil
}

func (r *authRepository) CreateUser(ctx context.Context, user *User) error {
	return r.db.Query().Create(user)
}

func (r *authRepository) CreateSession(ctx context.Context, session *Session) error {
	return r.db.Query().Create(session)
}

func (r *authRepository) FindSessionByRefreshTokenHash(ctx context.Context, hash string) (*Session, error) {
	var s Session
	if err := r.db.Query().Where("refresh_token_hash = ? AND expires_at > NOW()", hash).First(&s); err != nil {
		return nil, err
	}
	return &s, nil
}

func (r *authRepository) DeleteSession(ctx context.Context, sessionID string) error {
	_, err := r.db.Query().Where("id = ?", sessionID).Delete(&Session{})
	return err
}

func (r *authRepository) DeleteAllUserSessions(ctx context.Context, userID string) error {
	_, err := r.db.Query().Where("user_id = ?", userID).Delete(&Session{})
	return err
}

func (r *authRepository) ListUserSessions(ctx context.Context, userID string) ([]Session, error) {
	var sessions []Session
	if err := r.db.Query().Where("user_id = ? AND expires_at > NOW()", userID).
		Order("created_at DESC").Find(&sessions); err != nil {
		return nil, err
	}
	return sessions, nil
}
