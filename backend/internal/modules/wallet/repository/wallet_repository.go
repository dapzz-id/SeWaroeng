// Neura Cakrawira Solusi | SeWaroeng
// WalletRepository — Data access layer for e-Wallets and transactions.
package repository

import (
	"context"
	"github.com/goravel/framework/contracts/database/orm"
)

type Wallet struct {
	ID     string  `gorm:"primaryKey;type:uuid"`
	UserID string  `gorm:"type:uuid;uniqueIndex;not null"`
	Saldo  float64 `gorm:"type:numeric(15,2);default:0"`
}

type WalletTransaction struct {
	ID           string  `gorm:"primaryKey;type:uuid"`
	WalletID     string  `gorm:"type:uuid;not null;index"`
	Type         string  `gorm:"not null"` // credit or debit
	Jumlah       float64 `gorm:"type:numeric(15,2);not null"`
	SaldoSetelah float64 `gorm:"type:numeric(15,2);not null"`
	Referensi    string
}

type WalletRepository interface {
	GetByUserID(ctx context.Context, userID string) (*Wallet, error)
	GetByUserIDWithLock(ctx context.Context, tx orm.Query, userID string) (*Wallet, error)
	UpdateBalanceWithTx(ctx context.Context, tx orm.Query, w *Wallet, wt *WalletTransaction) error
	GetTransactions(ctx context.Context, walletID string) ([]WalletTransaction, error)
}

type walletRepository struct {
	db orm.Orm
}

func NewWalletRepository(db orm.Orm) WalletRepository {
	return &walletRepository{db: db}
}

func (r *walletRepository) GetByUserID(ctx context.Context, userID string) (*Wallet, error) {
	var w Wallet
	if err := r.db.Query().Where("user_id = ?", userID).First(&w); err != nil {
		return nil, err
	}
	return &w, nil
}

func (r *walletRepository) GetByUserIDWithLock(ctx context.Context, tx orm.Query, userID string) (*Wallet, error) {
	var w Wallet
	// Implement SELECT FOR UPDATE using raw query or Gorm Clauses if available
	// For Goravel/GORM wrapper:
	if err := tx.Where("user_id = ?", userID).LockForUpdate().First(&w); err != nil {
		return nil, err
	}
	return &w, nil
}

func (r *walletRepository) UpdateBalanceWithTx(ctx context.Context, tx orm.Query, w *Wallet, wt *WalletTransaction) error {
	if err := tx.Save(w); err != nil {
		return err
	}
	return tx.Create(wt)
}

func (r *walletRepository) GetTransactions(ctx context.Context, walletID string) ([]WalletTransaction, error) {
	var txs []WalletTransaction
	err := r.db.Query().Where("wallet_id = ?", walletID).Order("created_at DESC").Limit(50).Find(&txs)
	return txs, err
}
