// Neura Cakrawira Solusi | SeWaroeng
// ReportService — Business logic for Financial Reports & AI Insights.
package service

import (
	"context"
	"fmt"
	"time"

	"github.com/goravel/framework/contracts/database/orm"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/report/repository"
)

// MonthlyReport holds aggregated financial data for a given period.
type MonthlyReport struct {
	Period       string  `json:"period"`
	Revenue      float64 `json:"revenue"`
	GrossProfit  float64 `json:"gross_profit"`
	NetProfit    float64 `json:"net_profit"` // GrossProfit - SubscriptionFee
	PosRevenue   float64 `json:"pos_revenue"`
	OrderRevenue float64 `json:"order_revenue"`
	TxCount      int64   `json:"transaction_count"`
}

type ReportService interface {
	GetMonthlyReport(ctx context.Context, tenantID string, year, month int) (*MonthlyReport, error)
	GetAIInsights(ctx context.Context, tenantID string, year, month int) (string, int, error) // returns insight text, tokens used, error
}

type reportService struct {
	repo repository.ReportRepository
	db   orm.Orm
}

func NewReportService(repo repository.ReportRepository, db orm.Orm) ReportService {
	return &reportService{repo: repo, db: db}
}

func (s *reportService) GetMonthlyReport(ctx context.Context, tenantID string, year, month int) (*MonthlyReport, error) {
	startDate := time.Date(year, time.Month(month), 1, 0, 0, 0, 0, time.Local)
	endDate := startDate.AddDate(0, 1, 0).Add(-time.Second)

	revenue, err := s.repo.GetTotalRevenue(ctx, tenantID, startDate, endDate)
	if err != nil {
		return nil, err
	}

	grossProfit, err := s.repo.GetTotalProfit(ctx, tenantID, startDate, endDate)
	if err != nil {
		return nil, err
	}

	// Subscription fee deducted from net profit
	subscriptionFee := 250000.0 // Rp 250k/month
	netProfit := grossProfit - subscriptionFee
	if netProfit < 0 {
		netProfit = 0
	}

	// POS transaction count
	posCount, _ := s.db.Query().Table("pos_transactions").
		Where("tenant_id = ?", tenantID).
		Where("created_at >= ?", startDate).
		Where("created_at <= ?", endDate).
		Count()

	// Online order count
	orderCount, _ := s.db.Query().Table("orders").
		Where("tenant_id = ?", tenantID).
		Where("status = ?", "completed").
		Where("created_at >= ?", startDate).
		Where("created_at <= ?", endDate).
		Count()

	return &MonthlyReport{
		Period:      fmt.Sprintf("%d-%02d", year, month),
		Revenue:     revenue,
		GrossProfit: grossProfit,
		NetProfit:   netProfit,
		TxCount:     posCount + orderCount,
	}, nil
}

// GetAIInsights generates AI-powered recommendations using Gemini API.
// Returns: insight text, tokens consumed, error.
func (s *reportService) GetAIInsights(ctx context.Context, tenantID string, year, month int) (string, int, error) {
	report, err := s.GetMonthlyReport(ctx, tenantID, year, month)
	if err != nil {
		return "", 0, err
	}

	// Build prompt from report data
	prompt := fmt.Sprintf(
		`Anda adalah konsultan bisnis UMKM Indonesia. Berikan rekomendasi singkat (maks 500 kata) berdasarkan data berikut:
- Periode: %s
- Total Pendapatan: Rp %.0f
- Laba Kotor: Rp %.0f
- Laba Bersih (setelah biaya langganan): Rp %.0f
- Total Transaksi: %d

Berikan:
1. Analisis singkat performa keuangan
2. 3 rekomendasi untuk meningkatkan penjualan
3. Peringatan jika ada hal yang perlu diperbaiki
4. Perbandingan dengan rata-rata industri F&B UMKM`,
		report.Period, report.Revenue, report.GrossProfit, report.NetProfit, report.TxCount,
	)

	// Call Gemini AI API
	insight, tokensUsed, err := callGeminiAPI(ctx, prompt)
	if err != nil {
		return "", 0, fmt.Errorf("gemini API error: %w", err)
	}

	return insight, tokensUsed, nil
}
