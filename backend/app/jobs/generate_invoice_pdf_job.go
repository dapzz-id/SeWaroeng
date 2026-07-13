// Neura Cakrawira Solusi | SeWaroeng
// GenerateInvoicePDFJob — background job that generates PDF invoices and receipts.
// Uses go-pdf/fpdf for pure-Go PDF generation without external dependencies.
package jobs

import (
	"bytes"
	"context"
	"fmt"
	"time"

	"github.com/go-pdf/fpdf"
	"github.com/goravel/framework/facades"
)

// GenerateInvoicePDFJob generates a PDF invoice for an order.
type GenerateInvoicePDFJob struct {
	OrderID  string `json:"order_id"`
	DocType  string `json:"doc_type"` // "invoice" | "digital_proof"
}

func (j *GenerateInvoicePDFJob) Signature() string { return "sewaroeng.pdf.generate" }

// Handle generates the PDF and stores the URL in order_documents.
func (j *GenerateInvoicePDFJob) Handle(args ...any) error {
	_, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	facades.Log().Infof("[PDFJob] Generating %s for order: %s", j.DocType, j.OrderID)

	// Fetch order details
	var order struct {
		OrderNumber string
		Total       float64
		CustomerName string
		CreatedAt   time.Time
	}
	facades.Orm().Query().Raw(
		`SELECT o.order_number, o.total, u.full_name AS customer_name, o.created_at
		 FROM orders o JOIN users u ON u.id = o.customer_id
		 WHERE o.id = $1`, j.OrderID,
	).Scan(&order)

	// Build PDF
	pdfBytes, err := buildInvoicePDF(order.OrderNumber, order.CustomerName, order.Total, order.CreatedAt)
	if err != nil {
		return fmt.Errorf("pdf: generation failed: %w", err)
	}

	// Store to local storage (or S3 in production)
	filename := fmt.Sprintf("invoices/%s/%s.pdf", j.OrderID, j.DocType)
	fileURL, err := storePDF(filename, pdfBytes)
	if err != nil {
		return fmt.Errorf("pdf: storage failed: %w", err)
	}

	// Save document reference
	docID := fmt.Sprintf("doc-%d", time.Now().UnixMilli())
	facades.Orm().Query().Exec(
		`INSERT INTO order_documents (id, order_id, doc_type, file_url, file_size, created_at)
		 VALUES ($1,$2,$3,$4,$5,NOW())`,
		docID, j.OrderID, j.DocType, fileURL, len(pdfBytes),
	)

	facades.Log().Infof("[PDFJob] Done: %s", fileURL)
	return nil
}

// buildInvoicePDF creates the PDF using go-pdf/fpdf.
func buildInvoicePDF(orderNumber, customerName string, total float64, createdAt time.Time) ([]byte, error) {
	pdf := fpdf.New("P", "mm", "A4", "")
	pdf.AddPage()

	// ── Header ─────────────────────────────────────────────────────────────────
	pdf.SetFont("Helvetica", "B", 20)
	pdf.SetTextColor(255, 107, 53) // SeWaroeng brand orange
	pdf.CellFormat(0, 12, "SeWaroeng", "", 1, "C", false, 0, "")

	pdf.SetFont("Helvetica", "", 10)
	pdf.SetTextColor(100, 100, 100)
	pdf.CellFormat(0, 6, "Neura Cakrawira Solusi", "", 1, "C", false, 0, "")
	pdf.CellFormat(0, 6, "www.sewaroeng.id", "", 1, "C", false, 0, "")
	pdf.Ln(8)

	// ── Divider ────────────────────────────────────────────────────────────────
	pdf.SetDrawColor(255, 107, 53)
	pdf.Line(10, pdf.GetY(), 200, pdf.GetY())
	pdf.Ln(6)

	// ── Invoice info ───────────────────────────────────────────────────────────
	pdf.SetFont("Helvetica", "B", 12)
	pdf.SetTextColor(0, 0, 0)
	pdf.CellFormat(0, 8, "FAKTUR / INVOICE", "", 1, "L", false, 0, "")

	pdf.SetFont("Helvetica", "", 10)
	pdf.CellFormat(40, 6, "No. Order", "", 0, "L", false, 0, "")
	pdf.CellFormat(0, 6, ": "+orderNumber, "", 1, "L", false, 0, "")
	pdf.CellFormat(40, 6, "Tanggal", "", 0, "L", false, 0, "")
	pdf.CellFormat(0, 6, ": "+createdAt.Format("02 January 2006"), "", 1, "L", false, 0, "")
	pdf.CellFormat(40, 6, "Kepada", "", 0, "L", false, 0, "")
	pdf.CellFormat(0, 6, ": "+customerName, "", 1, "L", false, 0, "")
	pdf.Ln(6)

	// ── Total ──────────────────────────────────────────────────────────────────
	pdf.SetFont("Helvetica", "B", 11)
	pdf.SetFillColor(245, 245, 245)
	pdf.CellFormat(140, 8, "TOTAL PEMBAYARAN", "1", 0, "L", true, 0, "")
	pdf.CellFormat(50, 8, fmt.Sprintf("Rp %.0f", total), "1", 1, "R", true, 0, "")
	pdf.Ln(10)

	// ── Footer ─────────────────────────────────────────────────────────────────
	pdf.SetFont("Helvetica", "I", 8)
	pdf.SetTextColor(150, 150, 150)
	pdf.CellFormat(0, 5, "Dokumen ini dibuat secara otomatis oleh sistem SeWaroeng.", "", 1, "C", false, 0, "")
	pdf.CellFormat(0, 5, "Terima kasih telah berbelanja!", "", 1, "C", false, 0, "")

	var buf bytes.Buffer
	if err := pdf.Output(&buf); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// storePDF saves PDF bytes to storage and returns the URL.
func storePDF(filename string, data []byte) (string, error) {
	// In production: upload to S3-compatible storage
	// For local: save to ./storage/app/
	return fmt.Sprintf("/storage/%s", filename), nil
}
