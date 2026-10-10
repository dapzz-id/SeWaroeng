# 10. Customer / CRM Foundation and Receipt Architecture

Date: 2026-10-06

## Status

Accepted

## Context

Phase 1F closes the MVP loop connecting:
```text
Business → Outlet → Product → Open Shift → Optional Customer → POS Cart → Sale → Payment → Receipt → Inventory / Cash Movement
```
Prior to Phase 1F, sales were anonymized, customers could not be selected or tracked, and completed transactions did not have a tamper-evident, reproducible receipt document representation.

The PRD requires:
1. Tenant-scoped Customer foundation with deterministic duplicate prevention (normalized Indonesian phone `+628...` and trimmed lowercase email unique per tenant).
2. Customer order history and deterministic rule-based segmentation (`prospect`, `new`, `returning`, `inactive`, `high_value`).
3. Minimal consent model (`marketing_opt_in`), distinct from receipt delivery.
4. Presentation-tier Receipt model derived strictly from authoritative `Sale`, `Payment`, `Outlet`, and `Business` data, tracking immutable reprint counts without duplicating financial transactions or exposing gateway secrets.
5. End-to-end integration proving both walk-in and identified customer flows, cash drawer impact, and non-cash exemption.

## Decision

### 1. Customer Domain & Tenancy Boundary
- `customers` table is scoped strictly by `tenant_id` UUIDv7 with PostgreSQL Row-Level Security (RLS) forced.
- Unique partial indexes enforce tenant-scoped uniqueness:
  - `uq_customers_tenant_phone` WHERE `phone IS NOT NULL`
  - `uq_customers_tenant_email` WHERE `email IS NOT NULL`
- Normalization:
  - Phone: `PhoneNormalizer` canonicalizes Indonesian phone numbers into `+628...`.
  - Email: Lowercase and whitespace-trimmed.
  - Name-only walk-in customer creation is supported (both `phone` and `email` nullable).
- Auditing: Customer creation, update, and deactivation are logged in `audit_logs` without storing sensitive PII or credentials.

### 2. Sale ↔ Customer Relationship
- `sales.customer_id` is a nullable foreign key referencing `customers.id` (`ON DELETE RESTRICT`).
- Customer deletion/deactivation never deletes or cascades historical sales; transactions remain historically readable.
- POS allows:
  - **Walk-in mode**: `customer_id = null`.
  - **Identified customer mode**: `customer_id` validated against active tenant ownership. Any attempt to attach a cross-tenant customer is rejected with `422 Unprocessable Entity`.

### 3. Customer History Semantics & Aggregated Summary
- History is read-only and queries completed/finalized/cancelled `Sale` records for the authenticated tenant.
- Payment status maps authoritatively: `paid`, `pending`, `failed`, `cancelled`, `refunded`, `partially_refunded`.
- Summary metrics (`total_transactions`, `successful_transactions`, `total_spend_minor`, `average_order_value_minor`, `first_purchase_at`, `last_purchase_at`) are computed server-side via SQL aggregate queries.

### 4. Deterministic Rule-Based Segmentation
Customer segments are evaluated dynamically without hardcoded machine-learning black boxes:
- `prospect`: `successful_transactions == 0` (0 purchases).
- `new`: `successful_transactions == 1` (first purchase).
- `high_value`: `total_spend_minor >= 50,000,000` (Rp 500.000,00 cumulative spend threshold).
- `inactive`: `last_purchase_at < now() - 90 days` (no activity in 90 days).
- `returning`: `successful_transactions >= 2` (default active repeat customer).

### 5. Receipt as a Presentation Artifact
- `receipts` table stores document metadata (`receipt_number`, `sale_id`, `issued_at`, `reprint_count`, `last_reprinted_at`, `last_reprinted_by`).
- Format: `REC-{YYYYMM}-{SEQ}` (or derived from Sale reference).
- Uniqueness enforced via `uq_pos_receipts_tenant_sale` and `uq_pos_receipts_tenant_receipt_number`.
- Tamper-proof reprint:
  - Calling reprint atomically increments `reprint_count` and records reprint timestamp and operator in audit logs.
  - Struk presentation displays `SALINAN STRUK (Cetak Ulang #N)` if `reprint_count > 0`.
  - Never mutates financial state or creates new transactions.
- Zero Secret Leaks: Formatted presentation removes all payment gateway credentials, server keys, or internal provider tokens.

### 6. First Sale End-to-End Invariants
- Cash sale creates atomic `CashMovement` (`type = sale`, cash drawer balance updated).
- Non-cash sale (QRIS, VA) does not create cash drawer movement.
- Stock consumption, recipe explosion, and Moving Weighted Average (MWA) COGS deduction are preserved atomically.

## Consequences

### Positive
- Fully closed transaction lifecycle from registration to physical receipt and inventory deduction.
- Cashier can seamlessly search and quick-create customers without leaving the POS cart.
- Transparent reprint audit trails satisfy tax and retail compliance.
- Complete multi-tenant isolation guaranteed at database level via PostgreSQL RLS.

### Negative / Limitations
- Offline customer creation is intentionally deferred; customer search and creation remain online-first in MVP.
- Loyalty points, WhatsApp broadcast, and promotional campaigns remain out of scope for Phase 1F.
