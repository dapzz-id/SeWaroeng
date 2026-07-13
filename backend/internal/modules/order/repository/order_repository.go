// Neura Cakrawira Solusi | SeWaroeng
// OrderRepository — Data access layer for Orders.
package repository

import (
	"context"
	"github.com/goravel/framework/contracts/database/orm"
)

type Order struct {
	ID            string  `gorm:"primaryKey;type:uuid"`
	TenantID      string  `gorm:"type:uuid;not null;index"`
	CustomerID    string  `gorm:"type:uuid;not null;index"`
	Status        string  `gorm:"not null;index"`
	Total         float64 `gorm:"type:numeric(15,2);not null"`
	PaymentMethod string
	PaymentStatus string  `gorm:"not null"`
}

type OrderItem struct {
	ID          string  `gorm:"primaryKey;type:uuid"`
	OrderID     string  `gorm:"type:uuid;not null;index"`
	ProductID   string  `gorm:"type:uuid;not null"`
	Qty         int     `gorm:"not null"`
	HargaSatuan float64 `gorm:"type:numeric(15,2);not null"`
	Subtotal    float64 `gorm:"type:numeric(15,2);not null"`
}

type OrderRepository interface {
	CreateWithTx(ctx context.Context, tx orm.Query, o *Order, items []OrderItem) error
	GetByID(ctx context.Context, id string) (*Order, error)
	UpdateStatus(ctx context.Context, id, status string) error
}

type orderRepository struct {
	db orm.Orm
}

func NewOrderRepository(db orm.Orm) OrderRepository {
	return &orderRepository{db: db}
}

func (r *orderRepository) CreateWithTx(ctx context.Context, tx orm.Query, o *Order, items []OrderItem) error {
	if err := tx.Create(o); err != nil {
		return err
	}
	for i := range items {
		items[i].OrderID = o.ID
		if err := tx.Create(&items[i]); err != nil {
			return err
		}
	}
	return nil
}

func (r *orderRepository) GetByID(ctx context.Context, id string) (*Order, error) {
	var o Order
	if err := r.db.Query().Where("id = ?", id).First(&o); err != nil {
		return nil, err
	}
	return &o, nil
}

func (r *orderRepository) UpdateStatus(ctx context.Context, id, status string) error {
	_, err := r.db.Query().Model(&Order{}).Where("id = ?", id).Update("status", status)
	return err
}
