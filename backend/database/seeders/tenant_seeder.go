package seeders

import (
	"fmt"
	"github.com/goravel/framework/facades"
)

type TenantSeeder struct{}

func (s *TenantSeeder) Signature() string {
	return "TenantSeeder"
}

func (s *TenantSeeder) Run() error {
	tenantID := "11111111-1111-1111-1111-111111111111"
	
	count, err := facades.Orm().Query().Table("tenants").Where("id = ?", tenantID).Count()
	if err != nil {
		return err
	}
	
	if count == 0 {
		_, err = facades.Orm().Query().Exec(`
			INSERT INTO tenants (id, name, slug, email, phone, status, settings)
			VALUES (?, ?, ?, ?, ?, ?, '{}')`,
			tenantID, "Toko Demo SeWaroeng", "toko-demo", "tenant@sewaroeng.id", "081234567890", "active",
		)
		if err != nil {
			return fmt.Errorf("failed to insert tenant: %w", err)
		}

		storeID := "22222222-2222-2222-2222-222222222222"
		_, err = facades.Orm().Query().Exec(`
			INSERT INTO stores (id, tenant_id, name, address, city, province, postal_code, is_open, store_type, max_capacity, location, operational_hours)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_MakePoint(106.816666, -6.200000), 4326), '{}')`,
			storeID, tenantID, "Toko Demo Pusat", "Jl. Sudirman No. 1", "Jakarta", "DKI Jakarta", "10220", true, "warung", 50,
		)
		if err != nil {
			return fmt.Errorf("failed to insert store: %w", err)
		}
	}
	
	return nil
}
