// Neura Cakrawira Solusi | SeWaroeng
// SubscriptionRepository — Data access for SaaS subscriptions & AI token quotas.
package repository

import (
	"context"
	"time"

	"github.com/goravel/framework/contracts/database/orm"
)

type Subscription struct {
	ID         string    `gorm:"primaryKey;type:uuid"`
	TenantID   string    `gorm:"type:uuid;not null"`
	Status     string    `gorm:"default:'trial'"`
	PlanName   string    `gorm:"default:'basic'"`
	Price      float64   `gorm:"default:250000"`
	StartedAt  time.Time `gorm:"not null"`
	ExpiresAt  time.Time `gorm:"not null"`
	PaymentRef string
}

type AITokenQuota struct {
	ID          string    `gorm:"primaryKey;type:uuid"`
	TenantID    string    `gorm:"type:uuid;not null"`
	TotalTokens int64     `gorm:"default:1000000"`
	UsedTokens  int64     `gorm:"default:0"`
	ResetAt     time.Time `gorm:"not null"`
}

type AITokenUsageLog struct {
	ID         string    `gorm:"primaryKey;type:uuid"`
	TenantID   string    `gorm:"type:uuid;not null"`
	TokensUsed int       `gorm:"not null"`
	Feature    string    `gorm:"default:'ai_insights'"`
	CreatedAt  time.Time `gorm:"not null"`
}

type SubscriptionRepository interface {
	GetActiveSubscription(ctx context.Context, tenantID string) (*Subscription, error)
	CreateSubscription(ctx context.Context, sub *Subscription) error
	UpdateSubscription(ctx context.Context, sub *Subscription) error

	GetTokenQuota(ctx context.Context, tenantID string) (*AITokenQuota, error)
	CreateTokenQuota(ctx context.Context, quota *AITokenQuota) error
	DeductTokens(ctx context.Context, tenantID string, tokensUsed int) error
	LogTokenUsage(ctx context.Context, log *AITokenUsageLog) error
}

type subscriptionRepository struct {
	db orm.Orm
}

func NewSubscriptionRepository(db orm.Orm) SubscriptionRepository {
	return &subscriptionRepository{db: db}
}

func (r *subscriptionRepository) GetActiveSubscription(ctx context.Context, tenantID string) (*Subscription, error) {
	var sub Subscription
	if err := r.db.Query().
		Where("tenant_id = ?", tenantID).
		Where("status IN ?", []string{"active", "trial"}).
		Where("expires_at > ?", time.Now()).
		Order("expires_at DESC").
		First(&sub); err != nil {
		return nil, err
	}
	return &sub, nil
}

func (r *subscriptionRepository) CreateSubscription(ctx context.Context, sub *Subscription) error {
	return r.db.Query().Create(sub)
}

func (r *subscriptionRepository) UpdateSubscription(ctx context.Context, sub *Subscription) error {
	return r.db.Query().Save(sub)
}

func (r *subscriptionRepository) GetTokenQuota(ctx context.Context, tenantID string) (*AITokenQuota, error) {
	var quota AITokenQuota
	if err := r.db.Query().Where("tenant_id = ?", tenantID).First(&quota); err != nil {
		return nil, err
	}
	return &quota, nil
}

func (r *subscriptionRepository) CreateTokenQuota(ctx context.Context, quota *AITokenQuota) error {
	return r.db.Query().Create(quota)
}

func (r *subscriptionRepository) DeductTokens(ctx context.Context, tenantID string, tokensUsed int) error {
	var quota AITokenQuota
	if err := r.db.Query().Where("tenant_id = ?", tenantID).First(&quota); err != nil {
		return err
	}
	quota.UsedTokens += int64(tokensUsed)
	return r.db.Query().Save(&quota)
}

func (r *subscriptionRepository) LogTokenUsage(ctx context.Context, log *AITokenUsageLog) error {
	return r.db.Query().Create(log)
}
