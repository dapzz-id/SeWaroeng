// Neura Cakrawira Solusi | SeWaroeng
// TenantRepository — Data access layer for Tenants and Stores.
package repository

import (
	"context"
	"github.com/goravel/framework/contracts/database/orm"
)

type Tenant struct {
	ID        string `gorm:"primaryKey;type:uuid"`
	Name      string `gorm:"not null"`
	Slug      string `gorm:"uniqueIndex;not null"`
	Status    string `gorm:"default:'active'"`
	Settings  any    `gorm:"type:jsonb;default:'{}'"`
}

type Store struct {
	ID              string `gorm:"primaryKey;type:uuid"`
	TenantID        string `gorm:"type:uuid;not null"`
	Name            string `gorm:"not null"`
	Description     string
	LogoURL         string
	JamOperasional  any    `gorm:"type:jsonb"`
	StoreType       string `gorm:"default:'warung'"`
	MaxCapacity     int    `gorm:"default:0"`
}

type TenantRepository interface {
	CreateTenant(ctx context.Context, t *Tenant) error
	CreateStore(ctx context.Context, s *Store) error
	GetTenantByID(ctx context.Context, id string) (*Tenant, error)
	GetStoreByTenantID(ctx context.Context, tenantID string) (*Store, error)
	UpdateStore(ctx context.Context, s *Store) error
}

type tenantRepository struct {
	db orm.Orm
}

func NewTenantRepository(db orm.Orm) TenantRepository {
	return &tenantRepository{db: db}
}

func (r *tenantRepository) CreateTenant(ctx context.Context, t *Tenant) error {
	return r.db.Query().Create(t)
}

func (r *tenantRepository) CreateStore(ctx context.Context, s *Store) error {
	return r.db.Query().Create(s)
}

func (r *tenantRepository) GetTenantByID(ctx context.Context, id string) (*Tenant, error) {
	var t Tenant
	if err := r.db.Query().Where("id = ?", id).First(&t); err != nil {
		return nil, err
	}
	return &t, nil
}

func (r *tenantRepository) GetStoreByTenantID(ctx context.Context, tenantID string) (*Store, error) {
	var s Store
	if err := r.db.Query().Where("tenant_id = ?", tenantID).First(&s); err != nil {
		return nil, err
	}
	return &s, nil
}

func (r *tenantRepository) UpdateStore(ctx context.Context, s *Store) error {
	return r.db.Query().Save(s)
}
