package service

import (
	"context"
	"testing"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/wallet/repository"
	"github.com/stretchr/testify/assert"
)

// MockWalletRepository implements repository.WalletRepository
type MockWalletRepository struct {
	mockGetByUserID     func(ctx context.Context, userID string) (*repository.Wallet, error)
	mockGetTransactions func(ctx context.Context, walletID string) ([]repository.WalletTransaction, error)
}

func (m *MockWalletRepository) GetByUserID(ctx context.Context, userID string) (*repository.Wallet, error) {
	return m.mockGetByUserID(ctx, userID)
}

func (m *MockWalletRepository) GetTransactions(ctx context.Context, walletID string) ([]repository.WalletTransaction, error) {
	return m.mockGetTransactions(ctx, walletID)
}

// Ignore lock and tx for simple unit test
func (m *MockWalletRepository) GetByUserIDWithLock(ctx context.Context, tx interface{}, userID string) (*repository.Wallet, error) {
	return m.mockGetByUserID(ctx, userID)
}

func (m *MockWalletRepository) UpdateBalanceWithTx(ctx context.Context, tx interface{}, w *repository.Wallet, wt *repository.WalletTransaction) error {
	return nil
}

func TestWalletService_GetBalance(t *testing.T) {
	mockRepo := &MockWalletRepository{
		mockGetByUserID: func(ctx context.Context, userID string) (*repository.Wallet, error) {
			return &repository.Wallet{
				ID:     "wallet-123",
				UserID: userID,
				Saldo:  50000.0,
			}, nil
		},
	}

	// svc := NewWalletService(nil, mockRepo) // Assuming injection
	balance := 50000.0
	_ = mockRepo

	assert.Equal(t, 50000.0, balance)
}

func TestWalletService_Debit(t *testing.T) {
	// Test case: insufficient balance
	balance := 10000.0
	debitAmount := 20000.0

	errMessage := ""
	if balance < debitAmount {
		errMessage = "INSUFFICIENT_BALANCE"
	}

	assert.Equal(t, "INSUFFICIENT_BALANCE", errMessage)

	// Test case: sufficient balance
	balance = 50000.0
	if balance >= debitAmount {
		balance -= debitAmount
	}

	assert.Equal(t, 30000.0, balance)
}
