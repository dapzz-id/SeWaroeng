package seeders

import (
	"fmt"
	"github.com/goravel/framework/facades"
)

type UserSeeder struct{}

func (s *UserSeeder) Signature() string {
	return "UserSeeder"
}

func (s *UserSeeder) Run() error {
	tenantID := "11111111-1111-1111-1111-111111111111"
	passwordHash, _ := facades.Hash().Make("password123")

	users := []struct {
		ID       string
		TenantID *string
		Email    string
		Name     string
		Phone    string
		Role     string
	}{
		{
			ID:       "33333333-3333-3333-3333-333333333331",
			TenantID: &tenantID,
			Email:    "owner@sewaroeng.id",
			Name:     "Owner Demo",
			Phone:    "081234567891",
			Role:     "owner",
		},
		{
			ID:       "33333333-3333-3333-3333-333333333332",
			TenantID: &tenantID,
			Email:    "staff@sewaroeng.id",
			Name:     "Staff Demo",
			Phone:    "081234567892",
			Role:     "staff",
		},
		{
			ID:       "33333333-3333-3333-3333-333333333333",
			TenantID: nil,
			Email:    "customer@sewaroeng.id",
			Name:     "Customer Demo",
			Phone:    "081234567893",
			Role:     "customer",
		},
	}

	for _, user := range users {
		count, err := facades.Orm().Query().Table("users").Where("email = ?", user.Email).Count()
		if err != nil {
			return err
		}
		
		if count == 0 {
			_, err = facades.Orm().Query().Exec(`
				INSERT INTO users (id, tenant_id, email, password_hash, full_name, phone, role)
				VALUES (?, ?, ?, ?, ?, ?, ?)`,
				user.ID, user.TenantID, user.Email, passwordHash, user.Name, user.Phone, user.Role,
			)
			if err != nil {
				return fmt.Errorf("failed to insert user %s: %w", user.Email, err)
			}
		}
	}
	
	return nil
}
