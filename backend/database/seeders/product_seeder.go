package seeders

import (
	"fmt"
	"github.com/goravel/framework/facades"
)

type ProductSeeder struct{}

func (s *ProductSeeder) Signature() string {
	return "ProductSeeder"
}

func (s *ProductSeeder) Run() error {
	tenantID := "11111111-1111-1111-1111-111111111111"
	categoryID := "44444444-4444-4444-4444-444444444444"

	count, err := facades.Orm().Query().Table("product_categories").Where("id = ?", categoryID).Count()
	if err != nil {
		return err
	}
	
	if count == 0 {
		_, err = facades.Orm().Query().Exec(`
			INSERT INTO product_categories (id, tenant_id, name, slug, sort_order)
			VALUES (?, ?, ?, ?, ?)`,
			categoryID, tenantID, "Minuman", "minuman", 1,
		)
		if err != nil {
			return fmt.Errorf("failed to insert category: %w", err)
		}

		products := []struct {
			ID           string
			Name         string
			Slug         string
			Description  string
			Price        float64
			ComparePrice *float64
			Stock        int
			Weight       int
		}{
			{
				ID:          "55555555-5555-5555-5555-555555555551",
				Name:        "Kopi Susu",
				Slug:        "kopi-susu",
				Description: "Kopi susu gula aren",
				Price:       15000,
				ComparePrice: func() *float64 { v := 18000.0; return &v }(),
				Stock:       50,
				Weight:      200,
			},
			{
				ID:          "55555555-5555-5555-5555-555555555552",
				Name:        "Teh Manis",
				Slug:        "teh-manis",
				Description: "Teh manis segar",
				Price:       5000,
				ComparePrice: nil,
				Stock:       100,
				Weight:      200,
			},
			{
				ID:          "55555555-5555-5555-5555-555555555553",
				Name:        "Es Jeruk",
				Slug:        "es-jeruk",
				Description: "Es jeruk nipis",
				Price:       8000,
				ComparePrice: nil,
				Stock:       30,
				Weight:      200,
			},
		}

		for _, prod := range products {
			_, err = facades.Orm().Query().Exec(`
				INSERT INTO products (id, tenant_id, category_id, name, slug, description, price, compare_price, stock, weight_gram, product_type, status, images, meta)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'physical', 'active', '[]', '{}')`,
				prod.ID, tenantID, categoryID, prod.Name, prod.Slug, prod.Description, prod.Price, prod.ComparePrice, prod.Stock, prod.Weight,
			)
			if err != nil {
				return fmt.Errorf("failed to insert product %s: %w", prod.Name, err)
			}
		}
	}
	
	return nil
}
