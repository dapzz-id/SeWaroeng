// Neura Cakrawira Solusi | SeWaroeng
// UserRepository — Data access for Users and Addresses.
package repository

import (
	"context"
	"github.com/goravel/framework/contracts/database/orm"
)

type User struct {
	ID           string  `gorm:"primaryKey;type:uuid"`
	TenantID     *string `gorm:"type:uuid;index"`
	FullName     string  `gorm:"not null"`
	Email        string  `gorm:"uniqueIndex;not null"`
	PasswordHash string  `gorm:"not null"`
	Role         string  `gorm:"not null"`
}

type UserAddress struct {
	ID             string `gorm:"primaryKey;type:uuid"`
	UserID         string `gorm:"type:uuid;not null;index"`
	AlamatLengkap  string `gorm:"not null"`
	Location       any    `gorm:"type:geography(POINT,4326)"`
	IsDefault      bool   `gorm:"default:false"`
}

type UserRepository interface {
	CreateUser(ctx context.Context, u *User) error
	FindByEmail(ctx context.Context, email string) (*User, error)
	GetStaffByTenant(ctx context.Context, tenantID string) ([]User, error)
	DeleteUser(ctx context.Context, id string) error
	
	// Address
	CreateAddress(ctx context.Context, a *UserAddress) error
	GetAddresses(ctx context.Context, userID string) ([]UserAddress, error)
}

type userRepository struct {
	db orm.Orm
}

func NewUserRepository(db orm.Orm) UserRepository {
	return &userRepository{db: db}
}

func (r *userRepository) CreateUser(ctx context.Context, u *User) error {
	return r.db.Query().Create(u)
}

func (r *userRepository) FindByEmail(ctx context.Context, email string) (*User, error) {
	var u User
	if err := r.db.Query().Where("email = ?", email).First(&u); err != nil {
		return nil, err
	}
	return &u, nil
}

func (r *userRepository) GetStaffByTenant(ctx context.Context, tenantID string) ([]User, error) {
	var users []User
	err := r.db.Query().Where("tenant_id = ? AND role = 'staff'", tenantID).Find(&users)
	return users, err
}

func (r *userRepository) DeleteUser(ctx context.Context, id string) error {
	_, err := r.db.Query().Delete(&User{ID: id})
	return err
}

func (r *userRepository) CreateAddress(ctx context.Context, a *UserAddress) error {
	return r.db.Query().Create(a)
}

func (r *userRepository) GetAddresses(ctx context.Context, userID string) ([]UserAddress, error) {
	var addrs []UserAddress
	err := r.db.Query().Where("user_id = ?", userID).Order("is_default DESC").Find(&addrs)
	return addrs, err
}
