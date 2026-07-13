// Neura Cakrawira Solusi | SeWaroeng
// Stub PPOB provider — simulates responses for development and testing.
// Replace with real provider (Digiflazz, iReap) in production.
package stub

import (
	"context"
	"fmt"
	"time"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/digital/contracts"
)

// StubProvider simulates a PPOB provider for development.
type StubProvider struct{}

func New() *StubProvider { return &StubProvider{} }

func (p *StubProvider) Name() string { return "stub" }

// Inquiry simulates product inquiry — always returns valid.
func (p *StubProvider) Inquiry(_ context.Context, req *contracts.InquiryRequest) (*contracts.InquiryResponse, error) {
	return &contracts.InquiryResponse{
		Valid:        true,
		CustomerName: "STUB CUSTOMER",
		OperatorName: "STUB OPERATOR",
		ProductName:  fmt.Sprintf("STUB-%s", req.ProductCode),
		Price:        10000,
	}, nil
}

// Fulfill simulates fulfillment — returns a fake serial number.
func (p *StubProvider) Fulfill(_ context.Context, req *contracts.FulfillRequest) (*contracts.FulfillResponse, error) {
	sn := fmt.Sprintf("STUB-SN-%s-%d", req.TransactionID[:8], time.Now().UnixMilli())
	return &contracts.FulfillResponse{
		Success:      true,
		SerialNumber: sn,
		ProviderRef:  fmt.Sprintf("STUB-REF-%d", time.Now().UnixMilli()),
		Message:      "Stub fulfillment successful",
	}, nil
}
