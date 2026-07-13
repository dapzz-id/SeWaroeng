// Neura Cakrawira Solusi | SeWaroeng
// UserService — Business logic for users, staff management, and addresses.
package service

import (
	"context"
	"fmt"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/user/repository"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/crypto"
	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

type UserService interface {
	CreateStaff(ctx context.Context, tenantID, fullName, email, password string) (*repository.User, error)
	ListStaff(ctx context.Context, tenantID string) ([]repository.User, error)
	DeleteStaff(ctx context.Context, tenantID, staffID string) error

	AddAddress(ctx context.Context, userID, alamatLengkap string, lat, lng float64, isDefault bool) (*repository.UserAddress, error)
	ListAddresses(ctx context.Context, userID string) ([]repository.UserAddress, error)
}

type userService struct {
	repo repository.UserRepository
}

func NewUserService(repo repository.UserRepository) UserService {
	return &userService{repo: repo}
}

func (s *userService) CreateStaff(ctx context.Context, tenantID, fullName, email, password string) (*repository.User, error) {
	// Check email exist
	if _, err := s.repo.FindByEmail(ctx, email); err == nil {
		return nil, fmt.Errorf("EMAIL_EXISTS")
	}

	hash, _ := crypto.HashPassword(password)
	id, _ := pkguuid.NewV7()

	u := &repository.User{
		ID:           id,
		TenantID:     &tenantID,
		FullName:     fullName,
		Email:        email,
		PasswordHash: hash,
		Role:         "staff",
	}

	if err := s.repo.CreateUser(ctx, u); err != nil {
		return nil, err
	}
	return u, nil
}

func (s *userService) ListStaff(ctx context.Context, tenantID string) ([]repository.User, error) {
	return s.repo.GetStaffByTenant(ctx, tenantID)
}

func (s *userService) DeleteStaff(ctx context.Context, tenantID, staffID string) error {
	// Add tenant validation inside repo conceptually
	return s.repo.DeleteUser(ctx, staffID)
}

func (s *userService) AddAddress(ctx context.Context, userID, alamat string, lat, lng float64, isDefault bool) (*repository.UserAddress, error) {
	id, _ := pkguuid.NewV7()

	// PostGIS point: SRID=4326;POINT(lng lat)
	locStr := fmt.Sprintf("SRID=4326;POINT(%f %f)", lng, lat)

	a := &repository.UserAddress{
		ID:            id,
		UserID:        userID,
		AlamatLengkap: alamat,
		Location:      locStr,
		IsDefault:     isDefault,
	}

	if err := s.repo.CreateAddress(ctx, a); err != nil {
		return nil, err
	}
	return a, nil
}

func (s *userService) ListAddresses(ctx context.Context, userID string) ([]repository.UserAddress, error) {
	return s.repo.GetAddresses(ctx, userID)
}
