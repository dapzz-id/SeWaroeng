// Neura Cakrawira Solusi | SeWaroeng
// Package contracts defines the PaymentGatewayProvider interface.
// All payment gateway implementations MUST satisfy this interface.
package contracts

import "context"

// ─── Request / Response Types ─────────────────────────────────────────────────

// CreatePaymentRequest holds the data needed to initiate a payment.
type CreatePaymentRequest struct {
	OrderID     string  `json:"order_id"`
	ExternalRef string  `json:"external_ref"` // Unique ref per gateway
	Amount      float64 `json:"amount"`
	Currency    string  `json:"currency"` // IDR, USD, GBP
	CustomerName  string `json:"customer_name"`
	CustomerEmail string `json:"customer_email"`
	CustomerPhone string `json:"customer_phone,omitempty"`
	Description string  `json:"description"`
	CallbackURL string  `json:"callback_url"`
	ReturnURL   string  `json:"return_url,omitempty"`
	Items       []PaymentItem `json:"items,omitempty"`
}

// PaymentItem represents a line item in a payment.
type PaymentItem struct {
	ID       string  `json:"id"`
	Name     string  `json:"name"`
	Price    float64 `json:"price"`
	Quantity int     `json:"quantity"`
}

// PaymentResponse holds the gateway's response after initiating a payment.
type PaymentResponse struct {
	ExternalID  string `json:"external_id"`
	PaymentURL  string `json:"payment_url,omitempty"`
	Token       string `json:"token,omitempty"`   // For Midtrans Snap
	QRCode      string `json:"qr_code,omitempty"` // For QRIS
	VirtualAccount string `json:"virtual_account,omitempty"`
	BankCode    string `json:"bank_code,omitempty"`
	ExpiresAt   string `json:"expires_at,omitempty"`
}

// WebhookEvent represents a parsed and verified webhook notification.
type WebhookEvent struct {
	ExternalID    string `json:"external_id"`
	OrderID       string `json:"order_id"`
	Status        string `json:"status"` // paid, pending, failed, refunded
	Amount        float64 `json:"amount"`
	Currency      string `json:"currency"`
	PaymentMethod string `json:"payment_method"`
	PaidAt        string `json:"paid_at,omitempty"`
	RawPayload    map[string]interface{} `json:"raw_payload,omitempty"`
}

// PaymentStatus represents the current status of a payment.
type PaymentStatus struct {
	ExternalID string `json:"external_id"`
	OrderID    string `json:"order_id"`
	Status     string `json:"status"`
	Amount     float64 `json:"amount"`
}

// ─── Gateway Interface ────────────────────────────────────────────────────────

// PaymentGatewayProvider defines the contract all payment gateways must implement.
// This follows SOLID Open/Closed Principle: new gateways implement this interface
// without modifying existing code.
type PaymentGatewayProvider interface {
	// Name returns the gateway identifier (e.g., "midtrans", "paypal", "wise").
	Name() string

	// CreatePayment initiates a payment and returns gateway-specific instructions.
	CreatePayment(ctx context.Context, req *CreatePaymentRequest) (*PaymentResponse, error)

	// VerifyWebhook parses and cryptographically verifies a webhook payload.
	// Returns parsed event or error if signature invalid.
	VerifyWebhook(payload []byte, signature string) (*WebhookEvent, error)

	// GetPaymentStatus queries the current status of a payment from the gateway.
	GetPaymentStatus(ctx context.Context, externalID string) (*PaymentStatus, error)
}

// ─── Registry ─────────────────────────────────────────────────────────────────

// Registry holds all registered payment gateway implementations.
type Registry struct {
	providers map[string]PaymentGatewayProvider
}

// NewRegistry creates an empty gateway registry.
func NewRegistry() *Registry {
	return &Registry{providers: make(map[string]PaymentGatewayProvider)}
}

// Register adds a gateway implementation to the registry.
func (r *Registry) Register(p PaymentGatewayProvider) {
	r.providers[p.Name()] = p
}

// Get retrieves a gateway by name. Returns nil if not found.
func (r *Registry) Get(name string) (PaymentGatewayProvider, bool) {
	p, ok := r.providers[name]
	return p, ok
}
