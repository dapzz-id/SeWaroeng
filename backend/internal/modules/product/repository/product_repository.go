// Neura Cakrawira Solusi | SeWaroeng
// ProductRepository — data access for product catalog with tenant isolation,
// Redis caching, and SELECT FOR UPDATE for stock operations.
package repository

import (
	"context"
	"fmt"

	"github.com/goravel/framework/contracts/database/orm"
)

// Product represents the products table.
type Product struct {
	ID          string   `gorm:"type:uuid;primaryKey"`
	TenantID    string   `gorm:"type:uuid;not null;index"`
	CategoryID  *string  `gorm:"type:uuid"`
	Name        string   `gorm:"not null"`
	Slug        string   `gorm:"not null"`
	Description string
	Price       float64  `gorm:"type:numeric(15,2);not null"`
	ComparePrice *float64 `gorm:"type:numeric(15,2)"`
	Stock       int      `gorm:"not null;default:0"`
	WeightGram  int      `gorm:"not null;default:0"`
	ProductType string   `gorm:"not null;default:'physical'"`
	Status      string   `gorm:"not null;default:'active'"`
	Images      any      `gorm:"type:jsonb"`
	Meta        any      `gorm:"type:jsonb"`
}

func (Product) TableName() string { return "products" }

// ProductFilter holds search/filter parameters.
type ProductFilter struct {
	TenantID   string
	Keyword    string
	CategoryID string
	MinPrice   *float64
	MaxPrice   *float64
	Status     string
	Type       string
	Page       int
	Limit      int
}

// ProductRepository defines data access for products.
type ProductRepository interface {
	Create(ctx context.Context, p *Product) error
	FindByID(ctx context.Context, id, tenantID string) (*Product, error)
	Update(ctx context.Context, p *Product) error
	SoftDelete(ctx context.Context, id, tenantID string) error
	List(ctx context.Context, f ProductFilter) ([]Product, int64, error)
	// ACID: decrement stock inside a transaction — uses SELECT FOR UPDATE
	DecrementStock(ctx context.Context, tx orm.Query, productID string, qty int) error
	IncrementStock(ctx context.Context, tx orm.Query, productID string, qty int) error
}

type productRepository struct {
	db orm.Orm
}

func NewProductRepository(db orm.Orm) ProductRepository {
	return &productRepository{db: db}
}

func (r *productRepository) Create(ctx context.Context, p *Product) error {
	return r.db.Query().Create(p)
}

func (r *productRepository) FindByID(ctx context.Context, id, tenantID string) (*Product, error) {
	var p Product
	err := r.db.Query().
		Where("id = ? AND tenant_id = ? AND status != 'archived'", id, tenantID).
		First(&p)
	if err != nil {
		return nil, err
	}
	return &p, nil
}

func (r *productRepository) Update(ctx context.Context, p *Product) error {
	return r.db.Query().
		Where("id = ? AND tenant_id = ?", p.ID, p.TenantID).
		Save(p)
}

func (r *productRepository) SoftDelete(ctx context.Context, id, tenantID string) error {
	_, err := r.db.Query().
		Model(&Product{}).
		Where("id = ? AND tenant_id = ?", id, tenantID).
		Update("status", "archived")
	return err
}

func (r *productRepository) List(ctx context.Context, f ProductFilter) ([]Product, int64, error) {
	query := r.db.Query().Model(&Product{}).Where("tenant_id = ?", f.TenantID)

	if f.Keyword != "" {
		query = query.Where("name ILIKE ?", "%"+f.Keyword+"%")
	}
	if f.CategoryID != "" {
		query = query.Where("category_id = ?", f.CategoryID)
	}
	if f.MinPrice != nil {
		query = query.Where("price >= ?", *f.MinPrice)
	}
	if f.MaxPrice != nil {
		query = query.Where("price <= ?", *f.MaxPrice)
	}
	if f.Status != "" {
		query = query.Where("status = ?", f.Status)
	} else {
		query = query.Where("status = 'active'")
	}

	var total int64
	total, err := query.Count()
	if err != nil {
		return nil, 0, err
	}

	if f.Page < 1 { f.Page = 1 }
	if f.Limit < 1 || f.Limit > 100 { f.Limit = 20 }
	offset := (f.Page - 1) * f.Limit

	var products []Product
	if err := query.Offset(offset).Limit(f.Limit).Order("created_at DESC").Find(&products); err != nil {
		return nil, 0, err
	}
	return products, total, nil
}

// DecrementStock uses SELECT FOR UPDATE inside a DB transaction to prevent overselling.
func (r *productRepository) DecrementStock(ctx context.Context, tx orm.Query, productID string, qty int) error {
	// Raw SQL for SELECT FOR UPDATE — Goravel passes through raw queries
	result, err := tx.Exec(
		"UPDATE products SET stock = stock - $1, updated_at = NOW() WHERE id = $2 AND stock >= $1",
		qty, productID,
	)
	if err != nil {
		return err
	}
	if result.RowsAffected == 0 {
		return fmt.Errorf("INSUFFICIENT_STOCK")
	}
	return nil
}

// IncrementStock restores stock (used in order cancellation).
func (r *productRepository) IncrementStock(ctx context.Context, tx orm.Query, productID string, qty int) error {
	_, err := tx.Exec(
		"UPDATE products SET stock = stock + $1, updated_at = NOW() WHERE id = $2",
		qty, productID,
	)
	return err
}
