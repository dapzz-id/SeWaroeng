// Neura Cakrawira Solusi | SeWaroeng
// ShippingRepository — Data access layer for Couriers and PostGIS calculations.
package repository

import (
	"context"
	"github.com/goravel/framework/contracts/database/orm"
)

type StoreCourier struct {
	ID        string  `gorm:"primaryKey;type:uuid"`
	TenantID  string  `gorm:"type:uuid;not null;index"`
	Name      string  `gorm:"not null"`
	RadiusKm  float64 `gorm:"not null"`
	RatePerKm float64 `gorm:"type:numeric(10,2);not null"`
	Location  any     `gorm:"type:geography(POINT,4326)"`
}

type ShippingRepository interface {
	CreateCourier(ctx context.Context, c *StoreCourier) error
	GetCouriersByTenant(ctx context.Context, tenantID string) ([]StoreCourier, error)
}

type shippingRepository struct {
	db orm.Orm
}

func NewShippingRepository(db orm.Orm) ShippingRepository {
	return &shippingRepository{db: db}
}

func (r *shippingRepository) CreateCourier(ctx context.Context, c *StoreCourier) error {
	return r.db.Query().Create(c)
}

func (r *shippingRepository) GetCouriersByTenant(ctx context.Context, tenantID string) ([]StoreCourier, error) {
	var couriers []StoreCourier
	err := r.db.Query().Where("tenant_id = ?", tenantID).Find(&couriers)
	return couriers, err
}
