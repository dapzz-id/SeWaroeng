// Neura Cakrawira Solusi | SeWaroeng
// Email notification job and auto-complete schedule task.
package jobs

import (
	"context"
	"fmt"
	"time"

	"github.com/goravel/framework/facades"
	gomail "gopkg.in/gomail.v2"
)

// ─── Email Notification Job ────────────────────────────────────────────────────

// SendEmailNotificationJob sends transactional emails via SMTP.
type SendEmailNotificationJob struct {
	To       string `json:"to"`
	Subject  string `json:"subject"`
	Template string `json:"template"` // "order_confirm", "shipping_update", "digital_sn"
	Data     map[string]any `json:"data"`
}

func (j *SendEmailNotificationJob) Signature() string { return "sewaroeng.email.send" }

func (j *SendEmailNotificationJob) Handle() error {
	body := buildEmailBody(j.Template, j.Data)

	m := gomail.NewMessage()
	m.SetHeader("From", "SeWaroeng <noreply@sewaroeng.id>")
	m.SetHeader("To", j.To)
	m.SetHeader("Subject", j.Subject)
	m.SetBody("text/html", body)

	cfg := facades.Config()
	d := gomail.NewDialer(
		cfg.GetString("mail.host"),
		cfg.GetInt("mail.port"),
		cfg.GetString("mail.username"),
		cfg.GetString("mail.password"),
	)

	if err := d.DialAndSend(m); err != nil {
		return fmt.Errorf("email: send failed to %s: %w", j.To, err)
	}

	facades.Log().Infof("[EmailJob] Sent '%s' to %s", j.Subject, j.To)
	return nil
}

func buildEmailBody(template string, data map[string]any) string {
	switch template {
	case "order_confirm":
		return fmt.Sprintf(`<h2>Pesanan Diterima!</h2><p>No. Order: <strong>%v</strong></p><p>Total: <strong>Rp %v</strong></p><p>Segera lakukan pembayaran untuk memproses pesanan Anda.</p><br><small>SeWaroeng &mdash; Neura Cakrawira Solusi</small>`, data["order_number"], data["total"])
	case "shipping_update":
		return fmt.Sprintf(`<h2>Pesanan Anda Sedang Dikirim</h2><p>No. Resi: <strong>%v</strong></p><p>Kurir: %v</p><br><small>SeWaroeng &mdash; Neura Cakrawira Solusi</small>`, data["tracking_number"], data["courier"])
	case "digital_sn":
		return fmt.Sprintf(`<h2>Produk Digital Berhasil!</h2><p>Produk: <strong>%v</strong></p><p>Serial Number: <strong>%v</strong></p><br><small>SeWaroeng &mdash; Neura Cakrawira Solusi</small>`, data["product_name"], data["serial_number"])
	default:
		return "<p>Notifikasi dari SeWaroeng</p>"
	}
}

// ─── Tracking Number Notification Job ─────────────────────────────────────────

// TrackingNumberNotificationJob notifies customer when resi is issued.
type TrackingNumberNotificationJob struct {
	OrderID        string `json:"order_id"`
	CustomerEmail  string `json:"customer_email"`
	TrackingNumber string `json:"tracking_number"`
	CourierName    string `json:"courier_name"`
}

func (j *TrackingNumberNotificationJob) Signature() string { return "sewaroeng.tracking.notify" }

func (j *TrackingNumberNotificationJob) Handle() error {
	emailJob := &SendEmailNotificationJob{
		To:       j.CustomerEmail,
		Subject:  "Pesanan Anda Sedang Dikirim 🚚",
		Template: "shipping_update",
		Data:     map[string]any{"tracking_number": j.TrackingNumber, "courier": j.CourierName},
	}
	return emailJob.Handle()
}

// ─── Auto-Complete Delivered Orders (Schedule Task) ───────────────────────────

// AutoCompleteDeliveredOrders runs every hour and completes orders that have
// been DELIVERED for more than 3 days without customer confirmation.
func AutoCompleteDeliveredOrders() {
	_, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()

	cutoff := time.Now().Add(-72 * time.Hour)

	var orderIDs []string
	facades.Orm().Query().Raw(
		"SELECT id FROM orders WHERE status = 'delivered' AND updated_at < $1",
		cutoff,
	).Scan(&orderIDs)

	if len(orderIDs) == 0 {
		return
	}

	facades.Orm().Query().Exec(
		"UPDATE orders SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE status = 'delivered' AND updated_at < $1",
		cutoff,
	)

	facades.Log().Infof("[AutoComplete] Completed %d delivered orders older than 3 days", len(orderIDs))
}
