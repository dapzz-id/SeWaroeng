// Neura Cakrawira Solusi | SeWaroeng
// ShippingService — Kurir Toko shipping cost calculation using PostGIS.
// Uses ST_DistanceSphere to compute real-world distance between two GPS points.
package service

import (
	"context"
	"fmt"

	"github.com/goravel/framework/contracts/database/orm"
)

// ShippingCalculation holds the result of a shipping cost calculation.
type ShippingCalculation struct {
	CourierID    string  `json:"courier_id"`
	CourierName  string  `json:"courier_name"`
	DistanceKM   float64 `json:"distance_km"`
	ShippingCost float64 `json:"shipping_cost"`
	Eligible     bool    `json:"eligible"`
}

// Coordinates holds a GPS location.
type Coordinates struct {
	Latitude  float64 `json:"latitude"`
	Longitude float64 `json:"longitude"`
}

// ShippingService defines shipping business logic.
type ShippingService interface {
	CalculateShipping(ctx context.Context, tenantID string, destination Coordinates) ([]ShippingCalculation, error)
}

type shippingService struct {
	db orm.Orm
}

func NewShippingService(db orm.Orm) ShippingService {
	return &shippingService{db: db}
}

// CalculateShipping queries all active Kurir Toko for a tenant and calculates
// shipping cost using PostGIS ST_DistanceSphere between courier origin and customer address.
//
// PostGIS query: ST_DistanceSphere(courier.location, ST_MakePoint(lon, lat)::geography) / 1000
// Returns only couriers within delivery radius.
func (s *shippingService) CalculateShipping(ctx context.Context, tenantID string, dest Coordinates) ([]ShippingCalculation, error) {
	query := `
		SELECT
			sc.id                                               AS courier_id,
			sc.name                                             AS courier_name,
			sc.radius_km,
			sc.rate_per_km,
			sc.min_fee,
			ROUND(
				ST_DistanceSphere(
					sc.location::geometry,
					ST_MakePoint($2, $3)::geometry
				)::numeric / 1000,
				2
			)                                                   AS distance_km
		FROM store_couriers sc
		WHERE
			sc.tenant_id = $1
			AND sc.is_active = TRUE
			AND ST_DWithin(
				sc.location,
				ST_MakePoint($2, $3)::geography,
				sc.radius_km * 1000   -- radius_km → metres
			)
		ORDER BY distance_km ASC
	`

	var rows []struct {
		CourierID   string  `db:"courier_id"`
		CourierName string  `db:"courier_name"`
		RadiusKM    float64 `db:"radius_km"`
		RatePerKM   float64 `db:"rate_per_km"`
		MinFee      float64 `db:"min_fee"`
		DistanceKM  float64 `db:"distance_km"`
	}

	if err := s.db.Query().Raw(query, tenantID, dest.Longitude, dest.Latitude).Scan(&rows); err != nil {
		return nil, fmt.Errorf("shipping: PostGIS query failed: %w", err)
	}

	results := make([]ShippingCalculation, 0, len(rows))
	for _, row := range rows {
		cost := row.DistanceKM * row.RatePerKM
		if cost < row.MinFee {
			cost = row.MinFee
		}

		results = append(results, ShippingCalculation{
			CourierID:    row.CourierID,
			CourierName:  row.CourierName,
			DistanceKM:   row.DistanceKM,
			ShippingCost: cost,
			Eligible:     row.DistanceKM <= row.RadiusKM,
		})
	}

	if len(results) == 0 {
		return nil, fmt.Errorf("OUTSIDE_DELIVERY_RADIUS")
	}
	return results, nil
}
