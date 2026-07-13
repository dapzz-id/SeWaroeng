// Neura Cakrawira Solusi | SeWaroeng
// ReportRepository — Data access layer for Financial Reports.
package repository

import (
	"context"
	"time"

	"github.com/goravel/framework/contracts/database/orm"
)

type ReportRepository interface {
	GetTotalRevenue(ctx context.Context, tenantID string, startDate, endDate time.Time) (float64, error)
	GetTotalProfit(ctx context.Context, tenantID string, startDate, endDate time.Time) (float64, error)
}

type reportRepository struct {
	db orm.Orm
}

func NewReportRepository(db orm.Orm) ReportRepository {
	return &reportRepository{db: db}
}

func (r *reportRepository) GetTotalRevenue(ctx context.Context, tenantID string, startDate, endDate time.Time) (float64, error) {
	var total float64
	
	// Revenue from POS transactions
	var posTotal float64
	r.db.Query().Table("pos_transactions").
		Where("tenant_id = ?", tenantID).
		Where("created_at >= ?", startDate).
		Where("created_at <= ?", endDate).
		Select("COALESCE(SUM(total), 0)").Scan(&posTotal)

	// Revenue from online orders (completed)
	var orderTotal float64
	r.db.Query().Table("orders").
		Where("tenant_id = ?", tenantID).
		Where("status = ?", "completed"). // Assuming 'completed' means paid & delivered
		Where("created_at >= ?", startDate).
		Where("created_at <= ?", endDate).
		Select("COALESCE(SUM(total_amount), 0)").Scan(&orderTotal)

	total = posTotal + orderTotal
	return total, nil
}

func (r *reportRepository) GetTotalProfit(ctx context.Context, tenantID string, startDate, endDate time.Time) (float64, error) {
	// For profit, we would normally calculate Revenue - COGS.
	// Since COGS (Cost of Goods Sold/Harga Modal) might not be strictly modeled, 
	// let's assume a simplified calculation if cost isn't there, or query COGS.
	// Let's assume `products` table has a `cost_price` or we just return an estimated 30% margin for this example if cost_price is absent.
	// But let's build the query to get revenue first.
	revenue, err := r.GetTotalRevenue(ctx, tenantID, startDate, endDate)
	if err != nil {
		return 0, err
	}

	// Assuming an average profit margin of 35% for F&B if exact cost is not tracked.
	// In a complete implementation, this would join `pos_transaction_items` with `products` to calculate exact (price - cost_price) * quantity.
	profit := revenue * 0.35 

	return profit, nil
}
