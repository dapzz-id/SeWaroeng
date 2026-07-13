// Neura Cakrawira Solusi | SeWaroeng
package service

import (
	"context"
	"errors"

	"github.com/goravel/framework/contracts/database/orm"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/wallet/repository"
)

type WalletService interface {
	GetBalance(ctx context.Context, userID string) (float64, error)
	TopUp(ctx context.Context, userID string, amount float64, gateway string) error
	Debit(ctx context.Context, userID string, amount float64, ref string) error
	Credit(ctx context.Context, userID string, amount float64, ref string) error
	GetHistory(ctx context.Context, userID string) ([]repository.WalletTransaction, error)
}

type walletService struct {
	db   orm.Orm
	repo repository.WalletRepository
}

func NewWalletService(db orm.Orm, repo repository.WalletRepository) WalletService {
	return &walletService{db: db, repo: repo}
}

func (s *walletService) GetBalance(ctx context.Context, userID string) (float64, error) {
	w, err := s.repo.GetByUserID(ctx, userID)
	if err != nil {
		return 0, err
	}
	return w.Saldo, nil
}

func (s *walletService) TopUp(ctx context.Context, userID string, amount float64, gateway string) error {
	return nil
}

func (s *walletService) Debit(ctx context.Context, userID string, amount float64, ref string) error {
	return nil
}

func (s *walletService) Credit(ctx context.Context, userID string, amount float64, ref string) error {
	return nil
}

func (s *walletService) GetHistory(ctx context.Context, userID string) ([]repository.WalletTransaction, error) {
	w, err := s.repo.GetByUserID(ctx, userID)
	if err != nil {
		return nil, errors.New("wallet_not_found")
	}
	return s.repo.GetTransactions(ctx, w.ID)
}
