// Neura Cakrawira Solusi | SeWaroeng
// POSService — Point of Sale cashier module with offline sync support.
// Handles cash/QRIS transactions, stock deduction, change calculation.
package service

import (
	"context"
	"fmt"

	"github.com/goravel/framework/contracts/database/orm"

	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

// POSItem represents a single item in a POS transaction.
type POSItem struct {
	ProductID string `json:"product_id" validate:"required"`
	Quantity  int    `json:"quantity"   validate:"required,gte=1"`
}

// CreatePOSTransactionRequest holds data for a POS sale.
type CreatePOSTransactionRequest struct {
	TenantID   string    `json:"-"`
	StaffID    string    `json:"-"`
	Items      []POSItem `json:"items"       validate:"required,min=1"`
	AmountPaid float64   `json:"amount_paid" validate:"required,gte=0"`
	Method     string    `json:"method"      validate:"oneof=cash qris debit wallet"`
	Notes      string    `json:"notes"`
}

// POSTransactionResult holds the completed POS transaction summary.
type POSTransactionResult struct {
	TransactionID string           `json:"transaction_id"`
	Total         float64          `json:"total"`
	AmountPaid    float64          `json:"amount_paid"`
	ChangeDue     float64          `json:"change_due"`
	Method        string           `json:"method"`
	Items         []POSReceiptItem `json:"items"`
}

// POSReceiptItem holds receipt line-item data.
type POSReceiptItem struct {
	ProductName string  `json:"product_name"`
	Price       float64 `json:"price"`
	Quantity    int     `json:"quantity"`
	Subtotal    float64 `json:"subtotal"`
}

// POSService defines POS business logic.
type POSService interface {
	CreateTransaction(ctx context.Context, req *CreatePOSTransactionRequest) (*POSTransactionResult, error)
	SyncOfflineTransactions(ctx context.Context, tenantID string, txs []CreatePOSTransactionRequest) error
	ListTransactions(ctx context.Context, tenantID string, page, limit int) ([]map[string]any, int64, error)
}

type posService struct {
	db orm.Orm
}

func NewPOSService(db orm.Orm) POSService {
	return &posService{db: db}
}

// CreateTransaction runs a POS sale inside a single DB transaction:
// lock+decrement stock → insert POS transaction → insert items → COMMIT
func (s *posService) CreateTransaction(ctx context.Context, req *CreatePOSTransactionRequest) (*POSTransactionResult, error) {
	txID, _ := pkguuid.NewV7()
	var total float64
	var receiptItems []POSReceiptItem

	err := s.db.Transaction(func(tx orm.Query) error {
		for _, item := range req.Items {
			// Fetch product (SELECT FOR UPDATE to prevent overselling)
			var product struct {
				ID    string
				Name  string
				Price float64
				Stock int
			}
			if err := tx.Raw(
				"SELECT id, name, price, stock FROM products WHERE id = $1 AND tenant_id = $2 AND status = 'active' FOR UPDATE",
				item.ProductID, req.TenantID,
			).Scan(&product); err != nil || product.ID == "" {
				return fmt.Errorf("PRODUCT_NOT_FOUND:%s", item.ProductID)
			}
			if product.Stock < item.Quantity {
				return fmt.Errorf("INSUFFICIENT_STOCK:%s", item.ProductID)
			}

			lineTotal := product.Price * float64(item.Quantity)
			total += lineTotal
			receiptItems = append(receiptItems, POSReceiptItem{
				ProductName: product.Name,
				Price:       product.Price,
				Quantity:    item.Quantity,
				Subtotal:    lineTotal,
			})

			// Decrement stock
			if _, err := tx.Exec(
				"UPDATE products SET stock = stock - $1, updated_at = NOW() WHERE id = $2",
				item.Quantity, item.ProductID,
			); err != nil {
				return fmt.Errorf("failed to update stock: %w", err)
			}
		}

		changeDue := req.AmountPaid - total
		if changeDue < 0 {
			return fmt.Errorf("INSUFFICIENT_PAYMENT")
		}

		// Insert POS transaction
		if _, err := tx.Exec(
			`INSERT INTO pos_transactions (id, tenant_id, staff_id, total, amount_paid, change_due, method, notes, synced_at, created_at)
			 VALUES ($1,$2,$3,$4,$5,$6,$7,$8,NOW(),NOW())`,
			txID, req.TenantID, req.StaffID, total, req.AmountPaid, changeDue, req.Method, req.Notes,
		); err != nil {
			return fmt.Errorf("failed to insert POS transaction: %w", err)
		}

		// Insert POS items
		for i, ri := range receiptItems {
			itemID, _ := pkguuid.NewV7()
			if _, err := tx.Exec(
				`INSERT INTO pos_transaction_items (id, pos_tx_id, product_id, product_name, price, quantity, subtotal)
				 VALUES ($1,$2,$3,$4,$5,$6,$7)`,
				itemID, txID, req.Items[i].ProductID, ri.ProductName, ri.Price, ri.Quantity, ri.Subtotal,
			); err != nil {
				return fmt.Errorf("failed to insert POS item: %w", err)
			}
		}

		return nil // COMMIT
	})

	if err != nil {
		return nil, err
	}

	return &POSTransactionResult{
		TransactionID: txID,
		Total:         total,
		AmountPaid:    req.AmountPaid,
		ChangeDue:     req.AmountPaid - total,
		Method:        req.Method,
		Items:         receiptItems,
	}, nil
}

// SyncOfflineTransactions processes a batch of offline POS transactions from Flutter.
func (s *posService) SyncOfflineTransactions(ctx context.Context, tenantID string, txs []CreatePOSTransactionRequest) error {
	for _, tx := range txs {
		tx.TenantID = tenantID
		if _, err := s.CreateTransaction(ctx, &tx); err != nil {
			return fmt.Errorf("sync failed for transaction: %w", err)
		}
	}
	return nil
}

func (s *posService) ListTransactions(ctx context.Context, tenantID string, page, limit int) ([]map[string]any, int64, error) {
	if page < 1 {
		page = 1
	}
	if limit < 1 || limit > 100 {
		limit = 20
	}
	offset := (page - 1) * limit

	var total int64
	s.db.Query().Raw("SELECT COUNT(*) FROM pos_transactions WHERE tenant_id = $1", tenantID).Scan(&total)

	var txs []map[string]any
	s.db.Query().Raw(
		"SELECT * FROM pos_transactions WHERE tenant_id = $1 ORDER BY created_at DESC LIMIT $2 OFFSET $3",
		tenantID, limit, offset,
	).Scan(&txs)
	return txs, total, nil
}
