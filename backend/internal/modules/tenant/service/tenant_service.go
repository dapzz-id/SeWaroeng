// Neura Cakrawira Solusi | SeWaroeng
// TenantService — Business logic for Tenants and Stores.
package service

import (
	"context"
	"fmt"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/tenant/repository"
	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

type TenantService interface {
	RegisterTenant(ctx context.Context, name, storeName, storeType string, maxCapacity int, ktpUrl, nibUrl string) (*repository.Tenant, *repository.Store, error)
	GetStoreProfile(ctx context.Context, tenantID string) (*repository.Store, error)
	UpdateStoreProfile(ctx context.Context, tenantID, name, description string) error
}

type tenantService struct {
	repo repository.TenantRepository
}

func NewTenantService(repo repository.TenantRepository) TenantService {
	return &tenantService{repo: repo}
}

func (s *tenantService) RegisterTenant(ctx context.Context, name, storeName, storeType string, maxCapacity int, ktpUrl, nibUrl string) (*repository.Tenant, *repository.Store, error) {
	tenantID, _ := pkguuid.NewV7()
	storeID, _ := pkguuid.NewV7()

	settings := map[string]string{
		"ktp_url": ktpUrl,
	}
	if nibUrl != "" {
		settings["nib_url"] = nibUrl
	}

	t := &repository.Tenant{
		ID:       tenantID,
		Name:     name,
		Slug:     fmt.Sprintf("%s-%s", name, tenantID[:8]), // Simple slug
		Status:   "active",
		Settings: settings,
	}

	if err := s.repo.CreateTenant(ctx, t); err != nil {
		return nil, nil, err
	}

	st := &repository.Store{
		ID:          storeID,
		TenantID:    tenantID,
		Name:        storeName,
		StoreType:   storeType,
		MaxCapacity: maxCapacity,
	}

	if err := s.repo.CreateStore(ctx, st); err != nil {
		return nil, nil, err
	}

	return t, st, nil
}

func (s *tenantService) GetStoreProfile(ctx context.Context, tenantID string) (*repository.Store, error) {
	return s.repo.GetStoreByTenantID(ctx, tenantID)
}

func (s *tenantService) UpdateStoreProfile(ctx context.Context, tenantID, name, description string) error {
	st, err := s.repo.GetStoreByTenantID(ctx, tenantID)
	if err != nil {
		return err
	}

	st.Name = name
	st.Description = description
	return s.repo.UpdateStore(ctx, st)
}
