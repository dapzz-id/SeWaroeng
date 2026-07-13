// Neura Cakrawira Solusi | SeWaroeng
// SubscriptionService — Business logic for SaaS subscription & token management.
package service

import (
	"context"
	"fmt"
	"time"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/subscription/repository"
	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

type SubscriptionService interface {
	GetSubscriptionStatus(ctx context.Context, tenantID string) (*repository.Subscription, error)
	CreateSubscription(ctx context.Context, tenantID, paymentRef string) (*repository.Subscription, error)
	RenewSubscription(ctx context.Context, tenantID, paymentRef string) (*repository.Subscription, error)
	GetTokenQuota(ctx context.Context, tenantID string) (*repository.AITokenQuota, error)
	ConsumeTokens(ctx context.Context, tenantID string, tokensUsed int, feature string) error
	CheckTokenAvailability(ctx context.Context, tenantID string, requiredTokens int) (bool, int64, error)
}

type subscriptionService struct {
	repo repository.SubscriptionRepository
}

func NewSubscriptionService(repo repository.SubscriptionRepository) SubscriptionService {
	return &subscriptionService{repo: repo}
}

func (s *subscriptionService) GetSubscriptionStatus(ctx context.Context, tenantID string) (*repository.Subscription, error) {
	return s.repo.GetActiveSubscription(ctx, tenantID)
}

func (s *subscriptionService) CreateSubscription(ctx context.Context, tenantID, paymentRef string) (*repository.Subscription, error) {
	id, _ := pkguuid.NewV7()
	now := time.Now()

	sub := &repository.Subscription{
		ID:         id,
		TenantID:   tenantID,
		Status:     "active",
		PlanName:   "basic",
		Price:      250000, // Rp 250k/bulan
		StartedAt:  now,
		ExpiresAt:  now.AddDate(0, 1, 0), // +1 month
		PaymentRef: paymentRef,
	}

	if err := s.repo.CreateSubscription(ctx, sub); err != nil {
		return nil, err
	}

	// Initialize AI token quota (1M tokens)
	quotaID, _ := pkguuid.NewV7()
	quota := &repository.AITokenQuota{
		ID:          quotaID,
		TenantID:    tenantID,
		TotalTokens: 1_000_000,
		UsedTokens:  0,
		ResetAt:     now.AddDate(0, 1, 0),
	}
	if err := s.repo.CreateTokenQuota(ctx, quota); err != nil {
		// Non-fatal: subscription was created, token quota creation failed
		// Log this in production
		_ = err
	}

	return sub, nil
}

func (s *subscriptionService) RenewSubscription(ctx context.Context, tenantID, paymentRef string) (*repository.Subscription, error) {
	existing, err := s.repo.GetActiveSubscription(ctx, tenantID)
	if err != nil {
		// No active subscription, create a new one
		return s.CreateSubscription(ctx, tenantID, paymentRef)
	}

	// Extend existing subscription by 1 month
	existing.ExpiresAt = existing.ExpiresAt.AddDate(0, 1, 0)
	existing.PaymentRef = paymentRef
	existing.Status = "active"

	if err := s.repo.UpdateSubscription(ctx, existing); err != nil {
		return nil, err
	}

	// Reset token quota on renewal
	quota, err := s.repo.GetTokenQuota(ctx, tenantID)
	if err == nil && quota != nil {
		quota.UsedTokens = 0
		quota.TotalTokens = 1_000_000
		quota.ResetAt = existing.ExpiresAt
	}

	return existing, nil
}

func (s *subscriptionService) GetTokenQuota(ctx context.Context, tenantID string) (*repository.AITokenQuota, error) {
	return s.repo.GetTokenQuota(ctx, tenantID)
}

func (s *subscriptionService) ConsumeTokens(ctx context.Context, tenantID string, tokensUsed int, feature string) error {
	// Verify availability first
	ok, remaining, err := s.CheckTokenAvailability(ctx, tenantID, tokensUsed)
	if err != nil {
		return err
	}
	if !ok {
		return fmt.Errorf("INSUFFICIENT_TOKENS: kuota AI tersisa %d, dibutuhkan %d", remaining, tokensUsed)
	}

	// Deduct tokens
	if err := s.repo.DeductTokens(ctx, tenantID, tokensUsed); err != nil {
		return err
	}

	// Log usage
	logID, _ := pkguuid.NewV7()
	usageLog := &repository.AITokenUsageLog{
		ID:         logID,
		TenantID:   tenantID,
		TokensUsed: tokensUsed,
		Feature:    feature,
		CreatedAt:  time.Now(),
	}
	return s.repo.LogTokenUsage(ctx, usageLog)
}

func (s *subscriptionService) CheckTokenAvailability(ctx context.Context, tenantID string, requiredTokens int) (bool, int64, error) {
	quota, err := s.repo.GetTokenQuota(ctx, tenantID)
	if err != nil {
		return false, 0, err
	}

	remaining := quota.TotalTokens - quota.UsedTokens
	return remaining >= int64(requiredTokens), remaining, nil
}
