// Neura Cakrawira Solusi | SeWaroeng
// Package paypal implements PaymentGatewayProvider for PayPal REST API.
package paypal

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/payment/contracts"
)

// Config holds PayPal configuration.
type Config struct {
	ClientID     string
	ClientSecret string
	APIURL       string // sandbox or live
	WebhookID    string
}

// Provider implements contracts.PaymentGatewayProvider for PayPal.
type Provider struct {
	cfg    Config
	client *http.Client
}

// New creates a PayPal payment provider.
func New(cfg Config) *Provider {
	return &Provider{cfg: cfg, client: &http.Client{Timeout: 30 * time.Second}}
}

func (p *Provider) Name() string { return "paypal" }

// getAccessToken obtains a PayPal OAuth2 access token.
func (p *Provider) getAccessToken(ctx context.Context) (string, error) {
	payload := strings.NewReader("grant_type=client_credentials")
	req, err := http.NewRequestWithContext(ctx, "POST", p.cfg.APIURL+"/v1/oauth2/token", payload)
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.SetBasicAuth(p.cfg.ClientID, p.cfg.ClientSecret)

	resp, err := p.client.Do(req)
	if err != nil {
		return "", fmt.Errorf("paypal: oauth request failed: %w", err)
	}
	defer resp.Body.Close()

	var result struct {
		AccessToken string `json:"access_token"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return "", err
	}
	return result.AccessToken, nil
}

// CreatePayment creates a PayPal order.
func (p *Provider) CreatePayment(ctx context.Context, req *contracts.CreatePaymentRequest) (*contracts.PaymentResponse, error) {
	token, err := p.getAccessToken(ctx)
	if err != nil {
		return nil, err
	}

	payload := map[string]interface{}{
		"intent": "CAPTURE",
		"purchase_units": []map[string]interface{}{
			{
				"reference_id": req.OrderID,
				"amount": map[string]interface{}{
					"currency_code": req.Currency,
					"value":         fmt.Sprintf("%.2f", req.Amount),
				},
				"description": req.Description,
			},
		},
		"application_context": map[string]interface{}{
			"return_url": req.ReturnURL,
			"cancel_url": req.CallbackURL,
		},
	}

	body, _ := json.Marshal(payload)
	httpReq, _ := http.NewRequestWithContext(ctx, "POST", p.cfg.APIURL+"/v2/checkout/orders", bytes.NewReader(body))
	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("Authorization", "Bearer "+token)

	resp, err := p.client.Do(httpReq)
	if err != nil {
		return nil, fmt.Errorf("paypal: create order failed: %w", err)
	}
	defer resp.Body.Close()

	var result struct {
		ID    string `json:"id"`
		Links []struct {
			Href string `json:"href"`
			Rel  string `json:"rel"`
		} `json:"links"`
	}
	json.NewDecoder(resp.Body).Decode(&result)

	paymentURL := ""
	for _, link := range result.Links {
		if link.Rel == "approve" {
			paymentURL = link.Href
			break
		}
	}

	return &contracts.PaymentResponse{
		ExternalID: result.ID,
		PaymentURL: paymentURL,
	}, nil
}

// VerifyWebhook verifies PayPal webhook using HMAC-SHA256.
func (p *Provider) VerifyWebhook(payload []byte, signature string) (*contracts.WebhookEvent, error) {
	mac := hmac.New(sha256.New, []byte(p.cfg.ClientSecret))
	mac.Write(payload)
	expected := base64.StdEncoding.EncodeToString(mac.Sum(nil))

	if !hmac.Equal([]byte(expected), []byte(signature)) {
		return nil, fmt.Errorf("paypal: invalid webhook signature")
	}

	var data map[string]interface{}
	json.Unmarshal(payload, &data)

	eventType, _ := data["event_type"].(string)
	status := "pending"
	if eventType == "PAYMENT.CAPTURE.COMPLETED" {
		status = "paid"
	} else if eventType == "PAYMENT.CAPTURE.DENIED" {
		status = "failed"
	}

	var orderID string
	if resource, ok := data["resource"].(map[string]interface{}); ok {
		if supplementaryData, ok := resource["supplementary_data"].(map[string]interface{}); ok {
			if relatedIDs, ok := supplementaryData["related_ids"].(map[string]interface{}); ok {
				orderID, _ = relatedIDs["order_id"].(string)
			}
		}
	}

	return &contracts.WebhookEvent{
		Status:     status,
		OrderID:    orderID,
		RawPayload: data,
	}, nil
}

// GetPaymentStatus queries PayPal order status.
func (p *Provider) GetPaymentStatus(ctx context.Context, externalID string) (*contracts.PaymentStatus, error) {
	token, err := p.getAccessToken(ctx)
	if err != nil {
		return nil, err
	}

	req, _ := http.NewRequestWithContext(ctx, "GET", p.cfg.APIURL+"/v2/checkout/orders/"+externalID, nil)
	req.Header.Set("Authorization", "Bearer "+token)

	resp, err := p.client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	var result struct {
		ID     string `json:"id"`
		Status string `json:"status"`
	}
	json.NewDecoder(resp.Body).Decode(&result)

	status := "pending"
	if result.Status == "COMPLETED" {
		status = "paid"
	} else if result.Status == "VOIDED" {
		status = "failed"
	}

	return &contracts.PaymentStatus{
		ExternalID: externalID,
		Status:     status,
	}, nil
}
