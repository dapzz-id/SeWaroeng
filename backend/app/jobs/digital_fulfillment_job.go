// Neura Cakrawira Solusi | SeWaroeng
// DigitalFulfillmentJob — background job that processes digital product fulfillment.
// Runs after payment webhook confirms payment. Retries max 3x with exponential backoff.
package jobs

import (
	"context"
	"fmt"
	"time"

	"github.com/goravel/framework/contracts/queue"
	"github.com/goravel/framework/facades"

	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/crypto"
	"github.com/neuracakrawirasolusi/sewaroeng-backend/pkg/digital/contracts"
)

// DigitalFulfillmentJob is a Goravel Queue job for PPOB fulfillment.
type DigitalFulfillmentJob struct {
	TransactionID string `json:"transaction_id"`
	OrderID       string `json:"order_id"`
	ProductCode   string `json:"product_code"`
	TargetNumber  string `json:"target_number"`
	ProviderName  string `json:"provider_name"`
	EncryptionKey []byte `json:"-"` // Injected by dispatcher, not serialized
}

// Signature returns the unique job signature for Goravel Queue.
func (j *DigitalFulfillmentJob) Signature() string {
	return "sewaroeng.digital.fulfill"
}

// Handle processes the digital fulfillment.
// On failure, Goravel Queue retries with backoff. After 3 failures, moves to dead-letter.
func (j *DigitalFulfillmentJob) Handle() error {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	facades.Log().Infof("[DigitalFulfillment] Processing: txID=%s product=%s target=%s",
		j.TransactionID, j.ProductCode, j.TargetNumber)

	// Update status to processing
	facades.Orm().Query().Exec(
		"UPDATE digital_transactions SET status = 'processing', updated_at = NOW() WHERE id = $1",
		j.TransactionID,
	)

	// Get provider from registry (injected via service container in production)
	// For now, use stub provider
	provider := getProvider(j.ProviderName)
	if provider == nil {
		return fmt.Errorf("UNKNOWN_PROVIDER:%s", j.ProviderName)
	}

	result, err := provider.Fulfill(ctx, &contracts.FulfillRequest{
		TransactionID: j.TransactionID,
		ProductCode:   j.ProductCode,
		TargetNumber:  j.TargetNumber,
	})

	if err != nil || !result.Success {
		errMsg := "fulfillment failed"
		if err != nil {
			errMsg = err.Error()
		}
		facades.Orm().Query().Exec(
			"UPDATE digital_transactions SET status = 'failed', error_message = $1, retry_count = retry_count + 1, updated_at = NOW() WHERE id = $2",
			errMsg, j.TransactionID,
		)
		return fmt.Errorf("fulfillment error: %s", errMsg)
	}

	// Encrypt and store serial number
	var snEncrypted string
	if result.SerialNumber != "" && j.EncryptionKey != nil {
		snEncrypted, _ = crypto.Encrypt(j.EncryptionKey, result.SerialNumber)
	}

	// Update to completed with encrypted SN
	facades.Orm().Query().Exec(
		`UPDATE digital_transactions
		 SET status = 'completed', sn_encrypted = $1, provider_ref = $2, fulfilled_at = NOW(), updated_at = NOW()
		 WHERE id = $3`,
		snEncrypted, result.ProviderRef, j.TransactionID,
	)

	// Update order status to completed
	facades.Orm().Query().Exec(
		"UPDATE orders SET status = 'completed', payment_status = 'paid', updated_at = NOW() WHERE id = $1",
		j.OrderID,
	)

	// Dispatch PDF generation and email notification jobs
	facades.Queue().Job(&GenerateInvoicePDFJob{OrderID: j.OrderID}, []queue.Arg{}).Dispatch()

	facades.Log().Infof("[DigitalFulfillment] Completed: txID=%s sn=%s", j.TransactionID, result.SerialNumber)
	return nil
}

// getProvider is a simplified lookup — in production this uses DI container.
func getProvider(name string) contracts.DigitalProductProvider { return nil }
