// Neura Cakrawira Solusi | SeWaroeng
// POSRepository — Data access layer for Offline-first Point of Sales.
package repository

import (
	"context"
	"github.com/goravel/framework/contracts/database/orm"
)

type POSTransaction struct {
	ID        string  `gorm:"primaryKey;type:uuid"`
	TenantID  string  `gorm:"type:uuid;not null;index"`
	StaffID   string  `gorm:"type:uuid;not null"`
	Total     float64 `gorm:"type:numeric(15,2);not null"`
	Bayar     float64 `gorm:"type:numeric(15,2);not null"`
	Kembalian float64 `gorm:"type:numeric(15,2);not null"`
	Metode    string  `gorm:"not null"`
}

type POSTransactionItem struct {
	ID               string  `gorm:"primaryKey;type:uuid"`
	POSTransactionID string  `gorm:"type:uuid;not null;index"`
	ProductID        string  `gorm:"type:uuid;not null"`
	Qty              int     `gorm:"not null"`
	Harga            float64 `gorm:"type:numeric(15,2);not null"`
}

type POSRepository interface {
	CreateTransaction(ctx context.Context, tx orm.Query, t *POSTransaction, items []POSTransactionItem) error
}

type posRepository struct {
	db orm.Orm
}

func NewPOSRepository(db orm.Orm) POSRepository {
	return &posRepository{db: db}
}

func (r *posRepository) CreateTransaction(ctx context.Context, tx orm.Query, t *POSTransaction, items []POSTransactionItem) error {
	if err := tx.Create(t); err != nil {
		return err
	}
	for i := range items {
		items[i].POSTransactionID = t.ID
		if err := tx.Create(&items[i]); err != nil {
			return err
		}
	}
	return nil
}
