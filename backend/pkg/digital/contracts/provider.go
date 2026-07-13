// Neura Cakrawira Solusi | SeWaroeng
// Package contracts defines the DigitalProductProvider interface.
// All PPOB/digital product providers MUST implement this interface.
package contracts

import "context"

// InquiryRequest holds parameters for product inquiry.
type InquiryRequest struct {
	ProductCode  string `json:"product_code"`
	TargetNumber string `json:"target_number"` // Phone number, customer ID, etc.
}

// InquiryResponse holds the provider's inquiry result.
type InquiryResponse struct {
	Valid        bool    `json:"valid"`
	CustomerName string  `json:"customer_name,omitempty"`
	OperatorName string  `json:"operator_name,omitempty"`
	ProductName  string  `json:"product_name"`
	Price        float64 `json:"price"`
}

// FulfillRequest holds parameters for product fulfillment.
type FulfillRequest struct {
	TransactionID string `json:"transaction_id"` // Our internal reference
	ProductCode   string `json:"product_code"`
	TargetNumber  string `json:"target_number"`
}

// FulfillResponse holds the result of a fulfillment operation.
type FulfillResponse struct {
	Success      bool   `json:"success"`
	SerialNumber string `json:"serial_number,omitempty"` // SN / voucher code
	ProviderRef  string `json:"provider_ref"`            // Provider's transaction ID
	Message      string `json:"message,omitempty"`
}

// DigitalProductProvider defines the contract for PPOB providers.
// Add new providers (Digiflazz, iReap, etc.) by implementing this interface.
type DigitalProductProvider interface {
	// Name returns the provider identifier (e.g., "digiflazz", "stub").
	Name() string

	// Inquiry checks product validity and gets product details before purchase.
	Inquiry(ctx context.Context, req *InquiryRequest) (*InquiryResponse, error)

	// Fulfill processes the digital product purchase and returns serial number.
	Fulfill(ctx context.Context, req *FulfillRequest) (*FulfillResponse, error)
}

// Registry holds registered digital product providers.
type Registry struct {
	providers map[string]DigitalProductProvider
}

func NewRegistry() *Registry {
	return &Registry{providers: make(map[string]DigitalProductProvider)}
}

func (r *Registry) Register(p DigitalProductProvider) {
	r.providers[p.Name()] = p
}

func (r *Registry) Get(name string) (DigitalProductProvider, bool) {
	p, ok := r.providers[name]
	return p, ok
}
