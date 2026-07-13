// Neura Cakrawira Solusi | SeWaroeng
// OrderService — core order lifecycle with strict ACID transactions.
// Implements: create order (BEGIN→reserve stock→insert order→COMMIT/ROLLBACK),
//
//	cancel order (restore stock), state machine transitions.
package service

import (
	"context"
	"fmt"
	"time"

	"github.com/goravel/framework/contracts/database/orm"
	"github.com/goravel/framework/facades"

	productrep "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/product/repository"
	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

// ─── State Machine ────────────────────────────────────────────────────────────

// validTransitions defines the allowed order status transitions.
var validTransitions = map[string][]string{
	"pending_payment":   {"payment_confirmed", "cancelled"},
	"payment_confirmed": {"processing", "cancelled"},
	"processing":        {"shipped"},
	"shipped":           {"delivered"},
	"delivered":         {"completed"},
	"completed":         {},
	"cancelled":         {},
	"refunded":          {},
}

// canTransition returns true if the status change is allowed.
func canTransition(from, to string) bool {
	allowed, ok := validTransitions[from]
	if !ok {
		return false
	}
	for _, s := range allowed {
		if s == to {
			return true
		}
	}
	return false
}

// ─── Order Types ─────────────────────────────────────────────────────────────

type OrderItem struct {
	ProductID string `json:"product_id" validate:"required"`
	Quantity  int    `json:"quantity"   validate:"required,gte=1"`
}

type CreateOrderRequest struct {
	TenantID          string      `json:"-"`
	CustomerID        string      `json:"-"`
	ShippingAddressID *string     `json:"shipping_address_id"`
	CourierID         *string     `json:"courier_id"`
	PaymentMethod     string      `json:"payment_method" validate:"required"`
	Items             []OrderItem `json:"items"          validate:"required,min=1"`
	Notes             string      `json:"notes"`
}

type OrderResult struct {
	OrderID       string  `json:"order_id"`
	OrderNumber   string  `json:"order_number"`
	Total         float64 `json:"total"`
	PaymentMethod string  `json:"payment_method"`
	Status        string  `json:"status"`
}

// ─── Service ─────────────────────────────────────────────────────────────────

type OrderService interface {
	CreateOrder(ctx context.Context, req *CreateOrderRequest) (*OrderResult, error)
	CancelOrder(ctx context.Context, orderID, tenantID, customerID string) error
	UpdateStatus(ctx context.Context, orderID, tenantID, newStatus, trackingNumber string) error
	GetOrder(ctx context.Context, orderID, tenantID string) (map[string]any, error)
	ListOrders(ctx context.Context, tenantID, customerID, status string, page, limit int) ([]map[string]any, int64, error)
}

type orderService struct {
	db          orm.Orm
	productRepo productrep.ProductRepository
}

func NewOrderService(db orm.Orm, productRepo productrep.ProductRepository) OrderService {
	return &orderService{db: db, productRepo: productRepo}
}

// CreateOrder executes a full ACID transaction:
// BEGIN → validate items → SELECT FOR UPDATE stock → insert order → insert items → COMMIT
// Any failure triggers full ROLLBACK.
func (s *orderService) CreateOrder(ctx context.Context, req *CreateOrderRequest) (*OrderResult, error) {
	orderID, _ := pkguuid.NewV7()
	orderNumber := generateOrderNumber()

	var subtotal float64
	type resolvedItem struct {
		productID   string
		productName string
		price       float64
		quantity    int
		subtotal    float64
	}
	var items []resolvedItem

	err := s.db.Transaction(func(tx orm.Query) error {
		// ── 1. Validate each item and lock stock (SELECT FOR UPDATE) ───────────
		for _, item := range req.Items {
			var product struct {
				ID       string
				Name     string
				Price    float64
				Stock    int
				TenantID string
			}
			// SELECT FOR UPDATE prevents concurrent overselling
			if err := tx.Raw(
				"SELECT id, name, price, stock, tenant_id FROM products WHERE id = $1 AND tenant_id = $2 AND status = 'active' FOR UPDATE",
				item.ProductID, req.TenantID,
			).Scan(&product); err != nil {
				return fmt.Errorf("PRODUCT_NOT_FOUND:%s", item.ProductID)
			}
			if product.Stock < item.Quantity {
				return fmt.Errorf("INSUFFICIENT_STOCK:%s", item.ProductID)
			}

			lineTotal := product.Price * float64(item.Quantity)
			subtotal += lineTotal
			items = append(items, resolvedItem{
				productID:   item.ProductID,
				productName: product.Name,
				price:       product.Price,
				quantity:    item.Quantity,
				subtotal:    lineTotal,
			})

			// ── 2. Decrement stock ─────────────────────────────────────────────
			if _, err := tx.Exec(
				"UPDATE products SET stock = stock - $1, updated_at = NOW() WHERE id = $2",
				item.Quantity, item.ProductID,
			); err != nil {
				return fmt.Errorf("failed to decrement stock: %w", err)
			}
		}

		// ── 3. Insert order ────────────────────────────────────────────────────
		if _, err := tx.Exec(
			`INSERT INTO orders (id, tenant_id, customer_id, shipping_address_id, order_number,
			 status, payment_method, payment_status, subtotal, total, notes, created_at, updated_at)
			 VALUES ($1,$2,$3,$4,$5,'pending_payment',$6,'unpaid',$7,$8,$9,NOW(),NOW())`,
			orderID, req.TenantID, req.CustomerID, req.ShippingAddressID,
			orderNumber, req.PaymentMethod, subtotal, subtotal, req.Notes,
		); err != nil {
			return fmt.Errorf("failed to insert order: %w", err)
		}

		// ── 4. Insert order items ──────────────────────────────────────────────
		for _, item := range items {
			itemID, _ := pkguuid.NewV7()
			if _, err := tx.Exec(
				`INSERT INTO order_items (id, order_id, product_id, product_name, price, quantity, subtotal, created_at)
				 VALUES ($1,$2,$3,$4,$5,$6,$7,NOW())`,
				itemID, orderID, item.productID, item.productName, item.price, item.quantity, item.subtotal,
			); err != nil {
				return fmt.Errorf("failed to insert order item: %w", err)
			}
		}

		return nil // COMMIT
	})

	if err != nil {
		facades.Log().Errorf("CreateOrder ROLLBACK: %v", err)
		return nil, err
	}

	return &OrderResult{
		OrderID:       orderID,
		OrderNumber:   orderNumber,
		Total:         subtotal,
		PaymentMethod: req.PaymentMethod,
		Status:        "pending_payment",
	}, nil
}

// CancelOrder restores stock and marks order cancelled — all in one transaction.
func (s *orderService) CancelOrder(ctx context.Context, orderID, tenantID, customerID string) error {
	return s.db.Transaction(func(tx orm.Query) error {
		// Get current order status
		var order struct {
			Status string
		}
		if err := tx.Raw(
			"SELECT status FROM orders WHERE id = $1 AND tenant_id = $2 AND customer_id = $3 FOR UPDATE",
			orderID, tenantID, customerID,
		).Scan(&order); err != nil {
			return fmt.Errorf("ORDER_NOT_FOUND")
		}
		if order.Status != "pending_payment" {
			return fmt.Errorf("INVALID_STATE_TRANSITION:cannot cancel order in status %s", order.Status)
		}

		// Get order items to restore stock
		var items []struct {
			ProductID string
			Quantity  int
		}
		tx.Raw("SELECT product_id, quantity FROM order_items WHERE order_id = $1", orderID).Scan(&items)

		// Restore stock for each item
		for _, item := range items {
			tx.Exec("UPDATE products SET stock = stock + $1, updated_at = NOW() WHERE id = $2",
				item.Quantity, item.ProductID)
		}

		// Update order status
		_, err := tx.Exec(
			"UPDATE orders SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW() WHERE id = $1",
			orderID,
		)
		return err
	})
}

// UpdateStatus validates state machine transition and updates status.
func (s *orderService) UpdateStatus(ctx context.Context, orderID, tenantID, newStatus, trackingNumber string) error {
	var current struct{ Status string }
	if err := s.db.Query().Raw(
		"SELECT status FROM orders WHERE id = $1 AND tenant_id = $2", orderID, tenantID,
	).Scan(&current); err != nil {
		return fmt.Errorf("ORDER_NOT_FOUND")
	}

	if !canTransition(current.Status, newStatus) {
		return fmt.Errorf("INVALID_STATE_TRANSITION")
	}

	updates := "status = $1, updated_at = NOW()"
	args := []any{newStatus}
	if newStatus == "shipped" && trackingNumber != "" {
		updates += ", tracking_number = $3, shipped_at = NOW()"
		args = append(args, orderID, trackingNumber)
	} else {
		args = append(args, orderID)
	}

	_, err := s.db.Query().Exec(
		fmt.Sprintf("UPDATE orders SET %s WHERE id = $2", updates),
		args...,
	)
	return err
}

func (s *orderService) GetOrder(ctx context.Context, orderID, tenantID string) (map[string]any, error) {
	var order map[string]any
	s.db.Query().Raw(
		`SELECT o.*, json_agg(oi.*) as items FROM orders o
		 LEFT JOIN order_items oi ON oi.order_id = o.id
		 WHERE o.id = $1 AND o.tenant_id = $2 GROUP BY o.id`,
		orderID, tenantID,
	).Scan(&order)
	if order == nil {
		return nil, fmt.Errorf("ORDER_NOT_FOUND")
	}
	return order, nil
}

func (s *orderService) ListOrders(ctx context.Context, tenantID, customerID, status string, page, limit int) ([]map[string]any, int64, error) {
	if page < 1 {
		page = 1
	}
	if limit < 1 || limit > 100 {
		limit = 20
	}
	offset := (page - 1) * limit

	q := "SELECT * FROM orders WHERE tenant_id = $1"
	args := []any{tenantID}
	if customerID != "" {
		args = append(args, customerID)
		q += fmt.Sprintf(" AND customer_id = $%d", len(args))
	}
	if status != "" {
		args = append(args, status)
		q += fmt.Sprintf(" AND status = $%d", len(args))
	}

	var total int64
	s.db.Query().Raw("SELECT COUNT(*) FROM ("+q+") t", args...).Scan(&total)

	args = append(args, limit, offset)
	q += fmt.Sprintf(" ORDER BY created_at DESC LIMIT $%d OFFSET $%d", len(args)-1, len(args))

	var orders []map[string]any
	s.db.Query().Raw(q, args...).Scan(&orders)
	return orders, total, nil
}

func generateOrderNumber() string {
	id, _ := pkguuid.NewV7()
	return fmt.Sprintf("SW-%s-%s", time.Now().Format("20060102"), id[:8])
}
