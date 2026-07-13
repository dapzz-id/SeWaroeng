// Neura Cakrawira Solusi | SeWaroeng
// BookingService — Business logic for Bookings and Crowd Level.
package service

import (
	"context"
	"time"

	"github.com/goravel/framework/contracts/database/orm"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/booking/repository"
	tenrepo "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/tenant/repository"
	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

type BookingService interface {
	CreateBooking(ctx context.Context, userID, storeID string, pax int, bookingTime time.Time, notes string) (*repository.Booking, error)
	GetBookings(ctx context.Context, storeID string) ([]repository.Booking, error)
	ApproveBooking(ctx context.Context, bookingID string) error
	RejectBooking(ctx context.Context, bookingID string) error
	GetCrowdLevel(ctx context.Context, storeID string) (map[string]any, error)
}

type bookingService struct {
	repo    repository.BookingRepository
	tenRepo tenrepo.TenantRepository
	db      orm.Orm
}

func NewBookingService(repo repository.BookingRepository, tenRepo tenrepo.TenantRepository, db orm.Orm) BookingService {
	return &bookingService{repo: repo, tenRepo: tenRepo, db: db}
}

func (s *bookingService) CreateBooking(ctx context.Context, userID, storeID string, pax int, bookingTime time.Time, notes string) (*repository.Booking, error) {
	id, _ := pkguuid.NewV7()
	b := &repository.Booking{
		ID:          id,
		StoreID:     storeID,
		UserID:      userID,
		BookingTime: bookingTime,
		Pax:         pax,
		Status:      "pending",
		Notes:       notes,
	}

	if err := s.repo.CreateBooking(ctx, b); err != nil {
		return nil, err
	}
	return b, nil
}

func (s *bookingService) GetBookings(ctx context.Context, storeID string) ([]repository.Booking, error) {
	return s.repo.ListBookingsByStore(ctx, storeID)
}

func (s *bookingService) ApproveBooking(ctx context.Context, bookingID string) error {
	return s.repo.UpdateBookingStatus(ctx, bookingID, "confirmed")
}

func (s *bookingService) RejectBooking(ctx context.Context, bookingID string) error {
	return s.repo.UpdateBookingStatus(ctx, bookingID, "cancelled")
}

func (s *bookingService) GetCrowdLevel(ctx context.Context, storeID string) (map[string]any, error) {
	// Formula: ((Active Bookings + Active POS Transactions) / Max Capacity) * 100

	// Get store to find max capacity
	var store tenrepo.Store
	if err := s.db.Query().Where("id = ?", storeID).First(&store); err != nil {
		return nil, err
	}

	if store.MaxCapacity <= 0 {
		return map[string]any{"crowd_level": 0, "indicator": "Hijau", "message": "Kapasitas tidak diketahui"}, nil
	}

	// 1. Get active bookings
	activeBookingsCount, err := s.repo.GetActiveBookingsCount(ctx, storeID)
	if err != nil {
		activeBookingsCount = 0
	}

	// 2. Get active POS transactions (assuming within last 2 hours for simple estimation)
	var activePosCount int64
	now := time.Now()
	startTime := now.Add(-2 * time.Hour)
	activePosCount, _ = s.db.Query().Table("pos_transactions").
		Where("store_id = ?", storeID).
		Where("created_at >= ?", startTime).
		Count()

	totalActive := activeBookingsCount + activePosCount
	percentage := float64(totalActive) / float64(store.MaxCapacity) * 100

	indicator := "Hijau"
	if percentage >= 80 {
		indicator = "Merah"
	} else if percentage >= 50 {
		indicator = "Kuning"
	}

	return map[string]any{
		"crowd_level": percentage,
		"indicator":   indicator,
		"active_pax":  totalActive,
		"capacity":    store.MaxCapacity,
	}, nil
}
