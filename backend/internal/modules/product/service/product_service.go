// Neura Cakrawira Solusi | SeWaroeng
// ProductService — business logic for product catalog with Redis caching.
package service

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/goravel/framework/facades"

	redisinf "github.com/neuracakrawirasolusi/sewaroeng-backend/internal/infrastructure/redis"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/internal/modules/product/repository"
	pkguuid "github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/uuid"
)

const catalogCacheTTL = 5 * time.Minute

// ProductService defines product business logic.
type ProductService interface {
	Create(ctx context.Context, tenantID string, req *CreateProductRequest) (*repository.Product, error)
	GetByID(ctx context.Context, id, tenantID string) (*repository.Product, error)
	Update(ctx context.Context, id, tenantID string, req *UpdateProductRequest) (*repository.Product, error)
	Delete(ctx context.Context, id, tenantID string) error
	List(ctx context.Context, f repository.ProductFilter) ([]repository.Product, int64, error)
}

// ─── Request types ────────────────────────────────────────────────────────────

type CreateProductRequest struct {
	CategoryID   *string        `json:"category_id"`
	Name         string         `json:"name"         validate:"required,min=2,max=255"`
	Description  string         `json:"description"`
	Price        float64        `json:"price"        validate:"required,gte=0"`
	ComparePrice *float64       `json:"compare_price"`
	Stock        int            `json:"stock"        validate:"gte=0"`
	WeightGram   int            `json:"weight_gram"`
	Type         string         `json:"type"         validate:"oneof=physical digital"`
	Images       []string       `json:"images"`
	Meta         map[string]any `json:"meta"`
}

type UpdateProductRequest struct {
	Name        *string  `json:"name"`
	Description *string  `json:"description"`
	Price       *float64 `json:"price"`
	Stock       *int     `json:"stock"`
	Status      *string  `json:"status"`
	Images      []string `json:"images"`
}

// ─── Implementation ───────────────────────────────────────────────────────────

type productService struct {
	repo  repository.ProductRepository
	redis *redisinf.Client
}

func NewProductService(repo repository.ProductRepository, redis *redisinf.Client) ProductService {
	return &productService{repo: repo, redis: redis}
}

func (s *productService) Create(ctx context.Context, tenantID string, req *CreateProductRequest) (*repository.Product, error) {
	id, _ := pkguuid.NewV7()
	slug := generateSlug(req.Name)

	p := &repository.Product{
		ID:           id,
		TenantID:     tenantID,
		CategoryID:   req.CategoryID,
		Name:         req.Name,
		Slug:         slug,
		Description:  req.Description,
		Price:        req.Price,
		ComparePrice: req.ComparePrice,
		Stock:        req.Stock,
		WeightGram:   req.WeightGram,
		ProductType:  req.Type,
		Status:       "active",
		Images:       req.Images,
		Meta:         req.Meta,
	}
	if err := s.repo.Create(ctx, p); err != nil {
		return nil, fmt.Errorf("failed to create product: %w", err)
	}
	// Invalidate catalog cache for this tenant
	s.invalidateCache(ctx, tenantID)
	return p, nil
}

func (s *productService) GetByID(ctx context.Context, id, tenantID string) (*repository.Product, error) {
	return s.repo.FindByID(ctx, id, tenantID)
}

func (s *productService) Update(ctx context.Context, id, tenantID string, req *UpdateProductRequest) (*repository.Product, error) {
	p, err := s.repo.FindByID(ctx, id, tenantID)
	if err != nil {
		return nil, fmt.Errorf("PRODUCT_NOT_FOUND")
	}

	if req.Name != nil {
		p.Name = *req.Name
	}
	if req.Description != nil {
		p.Description = *req.Description
	}
	if req.Price != nil {
		p.Price = *req.Price
	}
	if req.Stock != nil {
		p.Stock = *req.Stock
	}
	if req.Status != nil {
		p.Status = *req.Status
	}
	if req.Images != nil {
		p.Images = req.Images
	}

	if err := s.repo.Update(ctx, p); err != nil {
		return nil, fmt.Errorf("failed to update product: %w", err)
	}
	s.invalidateCache(ctx, tenantID)
	return p, nil
}

func (s *productService) Delete(ctx context.Context, id, tenantID string) error {
	if err := s.repo.SoftDelete(ctx, id, tenantID); err != nil {
		return err
	}
	s.invalidateCache(ctx, tenantID)
	return nil
}

// List checks Redis cache first; on miss, queries DB and caches.
func (s *productService) List(ctx context.Context, f repository.ProductFilter) ([]repository.Product, int64, error) {
	cacheKey := redisinf.CatalogKey(f.TenantID, f.Page, f.Limit)

	// Cache hit
	if cached, err := s.redis.Get(ctx, cacheKey); err == nil {
		var result struct {
			Products []repository.Product `json:"products"`
			Total    int64                `json:"total"`
		}
		if err := json.Unmarshal([]byte(cached), &result); err == nil {
			return result.Products, result.Total, nil
		}
	}

	// Cache miss → query DB
	products, total, err := s.repo.List(ctx, f)
	if err != nil {
		return nil, 0, err
	}

	// Cache the result
	if data, err := json.Marshal(map[string]any{"products": products, "total": total}); err == nil {
		if err := s.redis.Set(ctx, cacheKey, string(data), catalogCacheTTL); err != nil {
			facades.Log().Warningf("ProductService: cache write failed: %v", err)
		}
	}

	return products, total, nil
}

func (s *productService) invalidateCache(ctx context.Context, tenantID string) {
	if err := s.redis.InvalidateStoreCatalog(ctx, tenantID); err != nil {
		facades.Log().Warningf("ProductService: cache invalidation failed: %v", err)
	}
}

func generateSlug(name string) string {
	// Simple ASCII slug; production would use proper unicode normalization
	slug := ""
	for _, c := range name {
		if (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') {
			slug += string(c)
		} else if c >= 'A' && c <= 'Z' {
			slug += string(c + 32)
		} else if c == ' ' || c == '-' {
			slug += "-"
		}
	}
	return slug + "-" + time.Now().Format("060102")
}
