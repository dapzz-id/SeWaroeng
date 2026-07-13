// Neura Cakrawira Solusi | SeWaroeng
// NotificationHandler — HTTP Handlers for Push Notifications (Superadmin).
// Used for global announcements, maintenance alerts, and promo events.
package handler

import (
	"time"

	"github.com/goravel/framework/contracts/http"
	"github.com/goravel/framework/facades"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/response"
	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

// NotificationRecord is the in-app notification model.
type NotificationRecord struct {
	ID        string    `gorm:"primaryKey;type:uuid" json:"id"`
	Title     string    `gorm:"not null" json:"title"`
	Body      string    `gorm:"not null" json:"body"`
	Type      string    `gorm:"default:'general'" json:"type"` // general, maintenance, promo
	Scope     string    `gorm:"default:'all'" json:"scope"`    // all, sellers, buyers
	CreatedBy string    `gorm:"type:uuid" json:"created_by"`
	CreatedAt time.Time `gorm:"not null" json:"created_at"`
}

type NotificationHandler struct{}

func NewNotificationHandler() *NotificationHandler {
	return &NotificationHandler{}
}

// POST /admin/notifications (Superadmin only)
func (h *NotificationHandler) BroadcastNotification(ctx http.Context) http.Response {
	var req struct {
		Title string `json:"title" validate:"required"`
		Body  string `json:"body" validate:"required"`
		Type  string `json:"type"`  // general, maintenance, promo
		Scope string `json:"scope"` // all, sellers, buyers
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	if req.Type == "" {
		req.Type = "general"
	}
	if req.Scope == "" {
		req.Scope = "all"
	}

	userID := ctx.Value("user_id").(string)
	id, _ := pkguuid.NewV7()

	notification := &NotificationRecord{
		ID:        id,
		Title:     req.Title,
		Body:      req.Body,
		Type:      req.Type,
		Scope:     req.Scope,
		CreatedBy: userID,
		CreatedAt: time.Now(),
	}

	// Save to DB
	if err := facades.Orm().Query().Create(notification); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	facades.Log().Infof("[Notification] Broadcast '%s' (type=%s, scope=%s) by user %s", req.Title, req.Type, req.Scope, userID)

	return response.Created(ctx, notification)
}

// GET /notifications — List notifications for authenticated user
func (h *NotificationHandler) ListNotifications(ctx http.Context) http.Response {
	role := ctx.Value("role").(string)

	var notifications []NotificationRecord
	query := facades.Orm().Query().Model(&NotificationRecord{}).Order("created_at DESC").Limit(50)

	// Filter by scope based on user role
	switch role {
	case "seller":
		query.Where("scope IN ?", []string{"all", "sellers"})
	case "buyer":
		query.Where("scope IN ?", []string{"all", "buyers"})
	default:
		// superadmin sees all
	}

	if err := query.Get(&notifications); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.OK(ctx, notifications)
}

// POST /admin/notifications/maintenance — Quick maintenance alert
func (h *NotificationHandler) MaintenanceAlert(ctx http.Context) http.Response {
	var req struct {
		Message   string `json:"message" validate:"required"`
		StartTime string `json:"start_time"` // ISO8601
		Duration  string `json:"duration"`   // e.g. "2 jam"
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	userID := ctx.Value("user_id").(string)
	id, _ := pkguuid.NewV7()

	title := "⚠️ Pemberitahuan Maintenance"
	body := req.Message
	if req.StartTime != "" {
		body += " | Mulai: " + req.StartTime
	}
	if req.Duration != "" {
		body += " | Durasi: " + req.Duration
	}

	notification := &NotificationRecord{
		ID:        id,
		Title:     title,
		Body:      body,
		Type:      "maintenance",
		Scope:     "all",
		CreatedBy: userID,
		CreatedAt: time.Now(),
	}

	if err := facades.Orm().Query().Create(notification); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.Created(ctx, notification)
}

// POST /admin/promo — Create promo/event notification
func (h *NotificationHandler) CreatePromo(ctx http.Context) http.Response {
	var req struct {
		Title     string `json:"title" validate:"required"`
		Body      string `json:"body" validate:"required"`
		PromoCode string `json:"promo_code"`
	}
	if err := ctx.Request().Bind(&req); err != nil {
		return response.BadRequest(ctx, "INVALID_REQUEST", err.Error())
	}

	userID := ctx.Value("user_id").(string)
	id, _ := pkguuid.NewV7()

	body := req.Body
	if req.PromoCode != "" {
		body += " | Kode Promo: " + req.PromoCode
	}

	notification := &NotificationRecord{
		ID:        id,
		Title:     "🎉 " + req.Title,
		Body:      body,
		Type:      "promo",
		Scope:     "all",
		CreatedBy: userID,
		CreatedAt: time.Now(),
	}

	if err := facades.Orm().Query().Create(notification); err != nil {
		return response.InternalServerError(ctx, err.Error())
	}

	return response.Created(ctx, notification)
}
