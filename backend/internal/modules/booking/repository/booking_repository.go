// Neura Cakrawira Solusi | SeWaroeng
// BookingRepository — Data access layer for Bookings.
package repository

import (
	"context"
	"time"

	"github.com/goravel/framework/contracts/database/orm"
)

type Booking struct {
	ID          string    `gorm:"primaryKey;type:uuid"`
	StoreID     string    `gorm:"type:uuid;not null"`
	UserID      string    `gorm:"type:uuid;not null"`
	BookingTime time.Time `gorm:"not null"`
	Pax         int       `gorm:"default:1"`
	Status      string    `gorm:"default:'pending'"`
	Notes       string
}

type BookingRepository interface {
	CreateBooking(ctx context.Context, b *Booking) error
	GetBookingByID(ctx context.Context, id string) (*Booking, error)
	ListBookingsByStore(ctx context.Context, storeID string) ([]Booking, error)
	UpdateBookingStatus(ctx context.Context, id, status string) error
	GetActiveBookingsCount(ctx context.Context, storeID string) (int64, error)
}

type bookingRepository struct {
	db orm.Orm
}

func NewBookingRepository(db orm.Orm) BookingRepository {
	return &bookingRepository{db: db}
}

func (r *bookingRepository) CreateBooking(ctx context.Context, b *Booking) error {
	return r.db.Query().Create(b)
}

func (r *bookingRepository) GetBookingByID(ctx context.Context, id string) (*Booking, error) {
	var b Booking
	if err := r.db.Query().Where("id = ?", id).First(&b); err != nil {
		return nil, err
	}
	return &b, nil
}

func (r *bookingRepository) ListBookingsByStore(ctx context.Context, storeID string) ([]Booking, error) {
	var bookings []Booking
	if err := r.db.Query().Where("store_id = ?", storeID).Order("booking_time DESC").Get(&bookings); err != nil {
		return nil, err
	}
	return bookings, nil
}

func (r *bookingRepository) UpdateBookingStatus(ctx context.Context, id, status string) error {
	_, err := r.db.Query().Model(&Booking{}).Where("id = ?", id).Update("status", status)
	return err
}

func (r *bookingRepository) GetActiveBookingsCount(ctx context.Context, storeID string) (int64, error) {
	// Active bookings are confirmed bookings that are happening around now
	now := time.Now()
	startTime := now.Add(-2 * time.Hour)
	endTime := now.Add(2 * time.Hour)
	
	count, err := r.db.Query().Model(&Booking{}).
		Where("store_id = ?", storeID).
		Where("status = ?", "confirmed").
		Where("booking_time >= ?", startTime).
		Where("booking_time <= ?", endTime).
		Count()
	return count, err
}
