# ADR-0015: Payment Reconciliation & Settlement Verification Architecture

**Status**: Accepted  
**Date**: 2026-10-09  
**Phase**: V1 Payment Reconciliation & Settlement Verification  

---

## Context

With multi-provider payment gateway integration (ADR-0006, ADR-0007, ADR-0008), SeWaroeng supports synchronous and asynchronous payment settlements via Midtrans and Duitku. However, discrepancies can arise between external payment gateway reports (statements, delayed webhooks, batch settlements) and internal transaction logs due to network partitions, delayed callbacks, fee deductions, rounding differences, or manual overrides.

To ensure financial integrity without compromising existing invariants (double-entry bookkeeping, PostgreSQL RLS tenant isolation, multi-outlet authorization, offline-first boundaries), a formal **Payment Reconciliation** subsystem is required.

---

## Architectural Principles & Decisions

### Decision 1: Authority Separation & Domain Boundaries

Reconciliation **is not** an alternative source of truth for transactions, payments, or ledger balances. The domain authority chain is strictly hierarchical:

```text
Sale (POS Authority)
   ↓
Payment / PaymentAttempt (Payment Gateway Domain)
   ↓
Settlement (Provider Evidence / Callback Verification)
   ↓
Reconciliation (Audit, Verification & Exception Matching)
   ↓
Finance / Double-Entry Journal (Accounting Truth)
```

1. **Sale**: Canonical authority on items sold, discounts applied, and total due.
2. **Payment**: Canonical authority on payment attempts, authorizations, and canonical statuses (`pending`, `settled`, `failed`, `refunded`).
3. **Reconciliation**: A verification and matching layer (`payment_reconciliations` table) that audits alignment between gateway-reported settlement data and internal payment records.
4. **Finance**: Double-entry journal entries (`journal_entries`, `journal_lines`) remain the sole financial source of truth. Reconciliation **NEVER** creates duplicate journals or alters posted historical ledger entries.

---

### Decision 2: Provider Evidence Normalization (`ProviderEvidence` DTO)

Reconciliation logic must remain completely agnostic to provider-specific payloads. All external provider records (Midtrans settlement feeds, Duitku reports, webhook events, batch statements) are ingested and normalized into a standard Data Transfer Object:

```text
App\Services\Payment\Reconciliation\ProviderEvidence:
  - provider: string ('midtrans' | 'duitku' | 'manual' | etc.)
  - providerReference: string (external transaction ID / settlement ID)
  - internalReference: ?string (order_id / payment_id correlation)
  - amountMinor: int (integer IDR minor units, cents/sen)
  - feeMinor: int (gateway fee deducted in minor units)
  - currency: string (3-letter ISO code, e.g. 'IDR')
  - channel: ?string ('qris' | 'bank_transfer' | 'e_wallet' | etc.)
  - settlementStatus: string ('settled' | 'pending' | 'failed' | 'refunded')
  - occurredAt: ?CarbonInterface
  - rawMetadata: ?array
```

No provider conditional logic (`if ($provider === 'midtrans')`) is permitted within the core reconciliation service.

---

### Decision 3: Deterministic Matching Rules & Identity Priority

Matching between external gateway evidence and internal database records follows a deterministic priority cascade:

1. **Provider Transaction Reference Match**: Direct match on `PaymentAttempt.provider_payment_id` or `Payment.provider_payment_id`.
2. **Internal Attempt Correlation Reference**: Match on `PaymentAttempt.provider_reference` or `Payment.reference`.
3. **Internal UUID Correlation**: Match on `Payment.id` or `PaymentRefund.id` when referenced in payment metadata.
4. **Amount & Currency Validation**: Exact comparison between `expected_amount_minor` and gateway reported `amount_minor`. Must have identical currency (e.g. `'IDR'`). Any difference triggers `AMOUNT_MISMATCH` or `CURRENCY_MISMATCH`.
5. **Tenant & Outlet Ownership Verification**: Records must strictly match the authenticated tenant context and assigned outlet boundary.
6. **Lifecycle State Consistency**: Discrepancies between gateway state (`settled`) and internal state (`failed` or `pending`) trigger `STATUS_CONFLICT`.

Guessable identifiers (customer name, customer phone, raw order sequence number, timestamps) are strictly forbidden as primary matching keys.

---

### Decision 4: Reconciliation State Machine

Reconciliation records follow an auditable state machine:

- `unmatched`: Provider reported a settlement, but no internal payment attempt could be correlated.
- `matched`: External provider evidence perfectly corresponds with internal payment records in reference, amount, currency, and lifecycle state.
- `mismatched`: Correlated record found, but variance detected in amount, currency, or lifecycle status.
- `needs_review`: Complex discrepancies requiring management or financial investigation.
- `resolved`: Administratively resolved discrepancy with mandatory resolution audit notes, timestamp, and actor ID.

**Invariance**: Reconciling a record **NEVER** mutates an internal `Payment` to `settled` automatically. Payment state transitions remain governed solely by the canonical payment settlement pipeline (`PaymentService`).

---

### Decision 5: Idempotency & Database Integrity

Reconciliation ingestion and replay must be idempotent across all channels (repeated webhooks, duplicate batch statement rows, replayed API calls).

- **Composite Database Unique Constraint**:
  `UNIQUE (tenant_id, provider, provider_reference)` on the `payment_reconciliations` table ensures no duplicate reconciliation records can be created for the same external transaction.
- **Tenant Isolation**: PostgreSQL Row Level Security (RLS) is enforced on `payment_reconciliations` via `tenant_isolation_policy`.
- **Database Composite Foreign Keys**:
  Composite foreign keys enforce tenant integrity across tables:
  - `(tenant_id, payment_id)` → `payments (tenant_id, id)`
  - `(tenant_id, outlet_id)` → `outlets (tenant_id, id)`

---

### Decision 6: Accounting Integrity & Mismatch Semantics

1. **No Duplicate Financial Postings**:
   When reconciliation matches a payment, it verifies existing posted journal entries. It does not create new entries if the payment has already posted to clearing/revenue.
2. **Immutable Historical Ledgers**:
   Mismatches (such as unexpected gateway fee deductions or provider chargebacks) do not overwrite past posted journals. Any financial adjustments must be handled via explicit compensating journal entries through standard accounting services, preserving the complete audit trail.

---

### Decision 7: Multi-Tenant & Multi-Outlet Authorization Matrix

- **Owner**: Full read access across all outlets within their tenant; authority to execute discrepancy resolutions.
- **Manager / Cashier**: Filtered strictly to their explicitly assigned outlets via `assertOutletAccess` server-side check.
- **Cross-Tenant Requests**: Fail closed via PostgreSQL RLS and strict TenantContext enforcement.
- **Client Claims**: Authority resolution is strictly server-authoritative (`TenantContext` and Sanctum token claims). Client-provided `tenant_id` or `outlet_id` headers/body fields are validated against server authority and never blindly trusted.

---

### Decision 8: Offline Boundary Preservation

Offline POS terminals continue to operate strictly under the cash/manual payment boundary:
- Offline terminals cannot create fake settled digital gateway payments.
- Online payment reconciliation occurs exclusively on the backend server against verified gateway settlement evidence.
- Sync engine operations cannot inject external reconciliation claims from untrusted offline clients.

---

## Consequences

- **Positive**: Complete auditability of payment gateways, automated discrepancy detection, protection against double-crediting, strict multi-outlet isolation, zero journal duplication.
- **Operational**: Discrepancies generate clear alerts in the merchant portal (`PaymentReconciliationScreen`), empowering store managers and owners to resolve exceptions with auditable notes.
