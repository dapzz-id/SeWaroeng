# ADR 0011: Double-Entry Financial Ledger, Automated Posting, and Financial Reporting

## Status
Accepted

## Date
2026-10-06 (Phase 1G)

## Context
SeWaroeng requires a robust financial accounting and reporting foundation covering operational expenses, automated financial posting downstream from sales, payments, and inventory consumption, as well as authoritative Profit & Loss (P&L) and Cash Flow reporting.

Key business and architectural requirements:
1. **Separation of Concerns & Authority**: POS, Inventory, and Payment domains remain authoritative in their respective transactions. Finance is the accounting and control layer downstream of authoritative business events.
2. **Double-Entry Bookkeeping**: Every journal entry must enforce debit equals credit (`SUM(debit) == SUM(credit)`) in integer minor units (IDR). Imbalanced entries must never be persisted or posted.
3. **Immutability of Ledger**: Posted journals and expenses must not be updated or deleted. Corrections must occur via explicit reversal entries (`reversal`) creating balanced compensating lines.
4. **Authoritative Costing**: COGS posting must strictly consume authoritative cost already captured by the inventory costing engine (Moving Weighted Average, `SaleConsumption`), never recalculating cost inside Finance.
5. **Cash Flow vs P&L Integrity**: Cash flow strictly monitors physical cash liquidity (drawer cash), distinct from net profit or non-cash payment clearing.
6. **Multi-Tenant Security**: Full tenant isolation via forced PostgreSQL Row-Level Security (RLS), RBAC permissions, and idempotency guarantees.

## Decisions

### 1. Account & Journal Foundation
- **Accounts (`accounts`)**: Deterministic chart of accounts per business:
  - `1110`: Cash (Asset)
  - `1120`: Bank / Payment Clearing (Asset)
  - `1200`: Inventory (Asset)
  - `2110`: Tax Payable (Liability)
  - `3100`: Owner Equity (Equity)
  - `4100`: Sales Revenue (Revenue)
  - `4110`: Sales Return / Contra Revenue (Revenue)
  - `5100`: Cost of Goods Sold (Expense)
  - `6100`: Operating Expense (Expense)
- **Journal Entries (`journal_entries`)** & **Lines (`journal_lines`)**:
  - Double-entry validation: `total_debit_minor == total_credit_minor`.
  - State machine: `draft`, `posted`, `reversed`.
  - Immutability enforced in Eloquent model lifecycle (`updating` and `deleting` event hooks prevent mutation of posted entries).
  - Source tracking via polymorphic references (`source_type`, `source_id`).

### 2. Downstream Automated Posting
- **Sale & Payment Posting**:
  - When a sale is finalized and fully settled (`PaymentSucceeded` / `SaleFinalized`), a sales revenue journal entry is deterministically created:
    - `Dr 1110 (Cash)` for cash payments
    - `Dr 1120 (Bank / Payment Clearing)` for external gateway / transfer payments
    - `Cr 4100 (Sales Revenue)` for net sales revenue
    - `Cr 2110 (Tax Payable)` for tax component (if applicable)
  - Split payments allocate debit across Cash and Bank clearing proportionately to the payment attempts.
- **COGS Posting**:
  - Consumes authoritative captured COGS from `SaleConsumption`:
    - `Dr 5100 (COGS)`
    - `Cr 1200 (Inventory)`
- **Refund Posting**:
  - When a `PaymentRefund` succeeds:
    - `Dr 4110 (Sales Return)`
    - `Cr 1110 / 1120 (Cash / Bank Clearing)`
    - Compensating inventory return only if physical return is recorded.

### 3. Operational Expenses (`expenses`)
- Records store expenses (utilities, supplies, rent, salary, maintenance, marketing, other).
- Atomic financial posting on creation:
  - `Dr 6100 (Operating Expense)`
  - `Cr 1110 (Cash)` or `Cr 1120 (Bank)`
- Reversal flow creates an inverse compensating journal entry and sets expense status to `reversed`.

### 4. Authoritative Financial Reports
- **Profit & Loss**:
  - `Gross Revenue - Sales Returns = Net Revenue`
  - `Net Revenue - COGS = Gross Profit`
  - `Gross Profit - Operating Expenses = Net Profit`
- **Cash Flow**:
  - Strictly physical cash account (`1110`):
    - `Cash In`: Cash sales + cash drawer in
    - `Cash Out`: Cash expenses + cash refunds + cash drawer out
    - `Net Cashflow = Cash In - Cash Out`
  - Digital gateway / bank clearing is explicitly excluded from physical cash in/out.

### 5. Idempotency & Database Integrity
- `Idempotency-Key` enforced on all mutating endpoints (`/api/v1/finance/expenses`, `/reverse`).
- Unique database index on `[tenant_id, source_type, source_id]` (`WHERE source_id IS NOT NULL AND status = 'posted'`) ensures duplicate business events never produce duplicate journals.
- Canonical accounting source identity represents the business event:
  - `source_type = 'sale'`, `source_id = sale.id` for sales revenue recognition.
  - `source_type = 'sale_cogs'`, `source_id = sale.id` for COGS recognition.
  - `source_type = 'payment_refund'`, `source_id = refund.id` for refund contra-revenue.
  - `source_type = 'cash_movement'`, `source_id = movement.id` for drawer cash in/out.

### 6. Phase 1G.1 Hardening: Posting Lifecycle & Cash Movement Reconciliation
- **Deterministic Revenue Recognition Lifecycle**:
  - `SaleFinalized` and `PaymentSucceeded` both trigger `FinanceService::postSale(Sale $sale)`.
  - Invariant: A sale must be both `isFinalized()` AND fully settled (`paid_minor >= total_minor`) before posting.
  - If `PaymentSucceeded` arrives while the sale is still draft/open (reverse arrival order), revenue posting is deferred until `SaleFinalized` arrives.
  - If `SaleFinalized` arrives before payment is complete, revenue posting is deferred until the final payment succeeds.
  - Replay of `SaleFinalized x3` and `PaymentSucceeded x3` produces exactly ONE revenue journal and ONE COGS journal.
- **Split Payment Handling**:
  - A single sale with multiple payment methods (e.g. Rp 40k Cash + Rp 60k QRIS) produces exactly ONE balanced revenue journal: `Dr Cash 40k`, `Dr Bank Clearing 60k`, `Cr Sales Revenue 100k`.
- **CashMovement ↔ Finance Cash Account (1110) Reconciliation**:
  - `CashMovement::TYPE_CASH_IN`: Generates `Dr 1110 (Cash)` / `Cr 3100 (Owner Equity)`. Increases drawer cash and Finance Cashflow (`other_cash_in_minor`), but does NOT inflate P&L Sales Revenue.
  - `CashMovement::TYPE_CASH_OUT`: Generates `Dr 3100 (Owner Equity)` / `Cr 1110 (Cash)`. Decreases drawer cash and Finance Cashflow (`other_cash_out_minor`), but does NOT inflate P&L Operating Expenses.
  - `CashMovement::TYPE_OPENING` & `TYPE_CLOSING`: Administrative drawer session bounds; do NOT post to the general ledger and do NOT affect P&L or Net Cashflow.
  - `CashMovement::TYPE_SALE` & `TYPE_REFUND`: Already recognized via `postSale` and `postRefund` to prevent double-posting.
  - **Reconciliation Invariant**: Shift drawer closing cash equals `Shift Opening Float + Finance Net Cashflow`.

## Consequences
- **Positive**: Clean separation between operational POS/payment and accounting ledger. Ledger integrity is guaranteed with zero rounding or balancing errors. Auditability is preserved.
- **Trade-offs**: Complex accounting features such as Accounts Payable (AP), Accounts Receivable (AR), depreciation, and automated bank reconciliation are deferred to future phases.
