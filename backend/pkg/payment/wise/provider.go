// Neura Cakrawira Solusi | SeWaroeng
// Package wise implements PaymentGatewayProvider for Wise (formerly TransferWise).
// Wise webhooks use RSA-SHA256 signature verification.
package wise

import (
	"context"
	"crypto"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"os"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/payment/contracts"
)

// Config holds Wise configuration.
type Config struct {
	APIToken      string
	PublicKeyPath string // Path to Wise RSA public key PEM file
	APIURL        string
}

// Provider implements contracts.PaymentGatewayProvider for Wise.
type Provider struct {
	cfg       Config
	publicKey *rsa.PublicKey
}

// New creates a Wise payment provider. Loads the public key from disk.
func New(cfg Config) (*Provider, error) {
	p := &Provider{cfg: cfg}
	if err := p.loadPublicKey(); err != nil {
		return nil, fmt.Errorf("wise: failed to load public key: %w", err)
	}
	return p, nil
}

func (p *Provider) loadPublicKey() error {
	pemData, err := os.ReadFile(p.cfg.PublicKeyPath)
	if err != nil {
		return fmt.Errorf("read public key file: %w", err)
	}
	block, _ := pem.Decode(pemData)
	if block == nil {
		return fmt.Errorf("invalid PEM data")
	}
	pub, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		return fmt.Errorf("parse public key: %w", err)
	}
	rsaKey, ok := pub.(*rsa.PublicKey)
	if !ok {
		return fmt.Errorf("not an RSA public key")
	}
	p.publicKey = rsaKey
	return nil
}

func (p *Provider) Name() string { return "wise" }

// CreatePayment — Wise is primarily for receiving international transfers;
// "creation" means generating a payment reference for the payer.
func (p *Provider) CreatePayment(_ context.Context, req *contracts.CreatePaymentRequest) (*contracts.PaymentResponse, error) {
	return &contracts.PaymentResponse{
		ExternalID: req.ExternalRef,
		// Wise does not have a redirect URL; customer initiates transfer manually
		// or via Wise link sent by email.
		PaymentURL: fmt.Sprintf("https://wise.com/send#paylink/%s", req.ExternalRef),
	}, nil
}

// VerifyWebhook verifies the Wise webhook using RSA-SHA256 signature.
// The signature is passed in the X-Signature-SHA256 header.
func (p *Provider) VerifyWebhook(payload []byte, signature string) (*contracts.WebhookEvent, error) {
	sigBytes, err := base64.StdEncoding.DecodeString(signature)
	if err != nil {
		return nil, fmt.Errorf("wise: decode signature: %w", err)
	}

	hash := sha256.Sum256(payload)
	if err := rsa.VerifyPKCS1v15(p.publicKey, crypto.SHA256, hash[:], sigBytes); err != nil {
		return nil, fmt.Errorf("wise: invalid RSA-SHA256 signature: %w", err)
	}

	var data map[string]interface{}
	if err := json.Unmarshal(payload, &data); err != nil {
		return nil, fmt.Errorf("wise: parse payload: %w", err)
	}

	eventType, _ := data["event_type"].(string)
	status := "pending"
	if eventType == "balances#credit" {
		status = "paid"
	}

	return &contracts.WebhookEvent{
		Status:     status,
		RawPayload: data,
	}, nil
}

// GetPaymentStatus is a no-op for Wise; status is driven by webhooks.
func (p *Provider) GetPaymentStatus(_ context.Context, externalID string) (*contracts.PaymentStatus, error) {
	return &contracts.PaymentStatus{
		ExternalID: externalID,
		Status:     "unknown",
	}, nil
}
