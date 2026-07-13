// Neura Cakrawira Solusi | SeWaroeng
// Package midtrans implements the PaymentGatewayProvider interface for Midtrans Snap.
package midtrans

import (
	"bytes"
	"context"
	"crypto/sha512"
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/payment/contracts"
)

// Config holds Midtrans configuration.
type Config struct {
	ServerKey    string
	ClientKey    string
	SnapURL      string
	IsProduction bool
}

// Provider implements contracts.PaymentGatewayProvider for Midtrans Snap.
type Provider struct {
	cfg    Config
	client *http.Client
}

// New creates a Midtrans Snap payment provider.
func New(cfg Config) *Provider {
	return &Provider{
		cfg:    cfg,
		client: &http.Client{Timeout: 30 * time.Second},
	}
}

// Name returns the gateway identifier.
func (p *Provider) Name() string { return "midtrans" }

// CreatePayment creates a Midtrans Snap transaction.
func (p *Provider) CreatePayment(ctx context.Context, req *contracts.CreatePaymentRequest) (*contracts.PaymentResponse, error) {
	items := make([]map[string]interface{}, 0, len(req.Items))
	for _, item := range req.Items {
		items = append(items, map[string]interface{}{
			"id":       item.ID,
			"price":    item.Price,
			"quantity": item.Quantity,
			"name":     item.Name,
		})
	}

	payload := map[string]interface{}{
		"transaction_details": map[string]interface{}{
			"order_id":     req.ExternalRef,
			"gross_amount": req.Amount,
		},
		"customer_details": map[string]interface{}{
			"first_name": req.CustomerName,
			"email":      req.CustomerEmail,
			"phone":      req.CustomerPhone,
		},
		"item_details": items,
		"callbacks": map[string]interface{}{
			"finish": req.ReturnURL,
		},
	}

	body, err := json.Marshal(payload)
	if err != nil {
		return nil, fmt.Errorf("midtrans: marshal payload: %w", err)
	}

	httpReq, err := http.NewRequestWithContext(ctx, "POST", p.cfg.SnapURL+"/transactions", bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("midtrans: create request: %w", err)
	}
	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("Accept", "application/json")
	httpReq.SetBasicAuth(p.cfg.ServerKey, "")

	resp, err := p.client.Do(httpReq)
	if err != nil {
		return nil, fmt.Errorf("midtrans: http request failed: %w", err)
	}
	defer resp.Body.Close()

	var result struct {
		Token         string   `json:"token"`
		RedirectURL   string   `json:"redirect_url"`
		StatusCode    string   `json:"status_code"`
		ErrorMessages []string `json:"error_messages"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return nil, fmt.Errorf("midtrans: decode response: %w", err)
	}
	if resp.StatusCode != 201 {
		return nil, fmt.Errorf("midtrans: API error %s: %v", result.StatusCode, result.ErrorMessages)
	}

	return &contracts.PaymentResponse{
		ExternalID: req.ExternalRef,
		Token:      result.Token,
		PaymentURL: result.RedirectURL,
	}, nil
}

// VerifyWebhook validates the Midtrans webhook signature.
// Signature = SHA512(order_id + status_code + gross_amount + server_key)
func (p *Provider) VerifyWebhook(payload []byte, signature string) (*contracts.WebhookEvent, error) {
	var data map[string]interface{}
	if err := json.Unmarshal(payload, &data); err != nil {
		return nil, fmt.Errorf("midtrans: parse webhook payload: %w", err)
	}

	orderID, _ := data["order_id"].(string)
	statusCode, _ := data["status_code"].(string)
	grossAmount, _ := data["gross_amount"].(string)
	transactionStatus, _ := data["transaction_status"].(string)

	raw := orderID + statusCode + grossAmount + p.cfg.ServerKey
	h := sha512.Sum512([]byte(raw))
	expected := fmt.Sprintf("%x", h)

	if expected != signature {
		return nil, fmt.Errorf("midtrans: invalid webhook signature")
	}

	status := mapMidtransStatus(transactionStatus)

	return &contracts.WebhookEvent{
		ExternalID: orderID,
		OrderID:    orderID,
		Status:     status,
		RawPayload: data,
	}, nil
}

// GetPaymentStatus queries the Midtrans transaction status.
func (p *Provider) GetPaymentStatus(ctx context.Context, externalID string) (*contracts.PaymentStatus, error) {
	apiBase := "https://api.sandbox.midtrans.com/v2"
	if p.cfg.IsProduction {
		apiBase = "https://api.midtrans.com/v2"
	}

	url := fmt.Sprintf("%s/%s/status", apiBase, externalID)
	req, err := http.NewRequestWithContext(ctx, "GET", url, nil)
	if err != nil {
		return nil, err
	}
	req.SetBasicAuth(p.cfg.ServerKey, "")

	resp, err := p.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("midtrans: status request failed: %w", err)
	}
	defer resp.Body.Close()

	var result map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return nil, fmt.Errorf("midtrans: decode status: %w", err)
	}

	txStatus, _ := result["transaction_status"].(string)
	grossAmount := 0.0
	if ga, ok := result["gross_amount"].(string); ok {
		fmt.Sscanf(ga, "%f", &grossAmount)
	}

	return &contracts.PaymentStatus{
		ExternalID: externalID,
		OrderID:    externalID,
		Status:     mapMidtransStatus(txStatus),
		Amount:     grossAmount,
	}, nil
}

func mapMidtransStatus(s string) string {
	switch s {
	case "capture", "settlement":
		return "paid"
	case "pending":
		return "pending"
	case "deny", "cancel", "expire":
		return "failed"
	case "refund":
		return "refunded"
	default:
		return "unknown"
	}
}
