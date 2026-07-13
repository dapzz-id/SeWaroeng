// Neura Cakrawira Solusi | SeWaroeng
// ReportHandler — HTTP Handlers for Financial Reports & AI Insights.
package handler

import (
	"strconv"
	"time"

	"github.com/goravel/framework/contracts/http"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/report/service"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
)

type ReportHandler struct {
	svc service.ReportService
}

func NewReportHandler(svc service.ReportService) *ReportHandler {
	return &ReportHandler{svc: svc}
}

// GET /reports/monthly?year=2026&month=7
func (h *ReportHandler) GetMonthlyReport(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)

	yearStr := ctx.Request().Query("year", "")
	monthStr := ctx.Request().Query("month", "")

	now := time.Now()
	year := now.Year()
	month := int(now.Month())

	if yearStr != "" {
		if v, err := strconv.Atoi(yearStr); err == nil {
			year = v
		}
	}
	if monthStr != "" {
		if v, err := strconv.Atoi(monthStr); err == nil {
			month = v
		}
	}

	report, err := h.svc.GetMonthlyReport(ctx.Context(), tenantID, year, month)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, report)
}

// GET /reports/ai-insights?year=2026&month=7
func (h *ReportHandler) GetAIInsights(ctx http.Context) http.Response {
	tenantID := ctx.Value("tenant_id").(string)

	yearStr := ctx.Request().Query("year", "")
	monthStr := ctx.Request().Query("month", "")

	now := time.Now()
	year := now.Year()
	month := int(now.Month())

	if yearStr != "" {
		if v, err := strconv.Atoi(yearStr); err == nil {
			year = v
		}
	}
	if monthStr != "" {
		if v, err := strconv.Atoi(monthStr); err == nil {
			month = v
		}
	}

	insight, tokensUsed, err := h.svc.GetAIInsights(ctx.Context(), tenantID, year, month)
	if err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, map[string]any{
		"insight":     insight,
		"tokens_used": tokensUsed,
		"period":      strconv.Itoa(year) + "-" + strconv.Itoa(month),
	})
}
