# ADR-0006: Payment & Settlement Architecture

**Status**: Accepted
**Date**: 2026-10-04
**Phase**: 1D — Payment & Settlement Foundation

---

## Context

Phase 1D introduces the Payment domain as a first-class authority in the SeWaroeng multi-tenant SaaS platform. Prior to this phase, Sale finalization (`Sale.status = 'finalized'`) was the terminal event for a transaction. Four significant architectural decisions were made that require explicit ADR-level justification beyond what is captured in `foundation.md`.

---

## Decision 1: Sale vs. Payment as Orthogonal Lifecycle Authorities

### Decision

`Sale` and `Payment` are maintained as strictly separate domain authorities with independent state machines:

- `Sale.status` (`open` → `finalized` → `cancelled`) answers: **"Is the business transaction finalized?"**
- `Payment.status` (`pending` → `processing` → `succeeded` / `failed` / `cancelled` / `refunded`) answers: **"Has this transaction been paid, and how?"**

Payment state is **never** stored as a field on `Sale`. No `sale.payment_status` column exists or will be added.

### Rationale

1. **Different lifecycles**: A sale can be finalized (inventory deducted, prices snapshotted) while payment remains pending (e.g. async QRIS scan not yet confirmed). These are distinct facts.
2. **Multiple payments per sale**: Split-tender payments require multiple `Payment` records against one `Sale`. A single `sale.payment_status` field cannot correctly represent partial payment states.
3. **Payment failure ≠ sale reversal**: A failed payment does **not** automatically reverse inventory. The business decides whether to re-tender or cancel the sale explicitly. Conflating the lifecycles would make this implicit and dangerous.
4. **Historical audit clarity**: Finalized sales are immutable records. Payment events are a separate audit trail.

### Consequences

- Settlement status (`paid`, `partially_paid`, `refunded`) is a **computed read-model** derived on-demand by `PaymentService::getSalePaymentSummary()`. It is not persisted.
- Future reporting that needs settlement status computes it from `payments` and `payment_refunds` tables, not from a `sales` column.

---

## Decision 2: Provider Abstraction via PaymentProviderInterface

### Decision

All payment provider logic is encapsulated behind `PaymentProviderInterface`. `PaymentService` contains **no `if provider == "X"` branching**. Provider-specific logic lives exclusively in adapter classes registered in `PaymentProviderManager`.

```
PaymentService
  └── PaymentProviderManager::resolve(providerName)
        └── PaymentProviderInterface
              ├── ManualCashPaymentProvider  ('manual', 'cash')
              └── MockPaymentProvider        ('mock')
```

The interface contract (`App\Services\Payment\Contracts\PaymentProviderInterface`) requires:
- `initiatePayment(Payment, array): array`
- `queryPayment(Payment): array`
- `processRefund(PaymentRefund): array`
- `verifyWebhookSignature(Request): bool`
- `parseWebhookEvent(Request): array`
- `getProviderName(): string`

### Current Provider Status (Phase 1D)

| Provider | Status |
|---|---|
| `manual` / `cash` | ✅ Implemented — synchronous, always succeeds |
| `mock` | ✅ Implemented — test/dev double; simulates success, failure, async |
| `qris` (Midtrans/Xendit/Duitku) | ❌ Interface ready, **no adapter implemented** |
| `card` | ❌ Interface ready, **no adapter implemented** |
| `bank_transfer` | ❌ Interface ready, **no adapter implemented** |
| `e_wallet` | ❌ Interface ready, **no adapter implemented** |

> **Critical**: The DB `CHECK` constraint accepts `qris`, `card`, `bank_transfer`, `e_wallet` as method values. However, no production adapter exists for these methods in Phase 1D. Using them without a registered adapter throws `UnsupportedPaymentProviderException` (HTTP 400 `UNSUPPORTED_PAYMENT_PROVIDER`).

### Rationale

1. **Open/Closed Principle**: Adding QRIS/Xendit/Duitku requires only a new adapter class + `registerProvider()` call. `PaymentService` is not modified.
2. **Testability**: `MockPaymentProvider` simulates all lifecycle paths without real network calls.
3. **Webhook polymorphism**: Signature verification and event parsing are adapter-specific. `PaymentService::handleWebhook()` delegates to `verifyWebhookSignature()` and `parseWebhookEvent()` on the resolved adapter.

### Consequences

- Real provider integration (QRIS etc.) is Phase 2. Phase 1D provides the architectural slot only.
- `ManualCashPaymentProvider::verifyWebhookSignature()` always returns `false` — cash payments have no external webhooks. This is intentional.

---

## Decision 3: Webhook Platform Context Bypass

### Decision

External payment provider webhooks arrive at `/api/v1/payments/webhooks/{provider}` without any tenant context. To resolve which payment/tenant a webhook refers to, a **narrow, transaction-scoped platform context bypass** is used:

```php
DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
// → queries payments table cross-tenant by provider_payment_id
DB::statement("SELECT set_config('app.is_platform_context', '', true)");
// → immediately cleared (is_local=true = transaction-scoped SET LOCAL)
```

RLS policy on `payments`, `payment_attempts`, `payment_refunds`:
```sql
USING (
    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
    OR NULLIF(current_setting('app.is_platform_context', true), '') = 'true'
)
```

After the target payment is located, **all subsequent mutations** execute within `TenantManager::runForTenant($tenantId, fn)` to restore full RLS isolation.

### Rationale

1. **Webhooks are provider-routed, not tenant-routed**: Providers call a platform endpoint with their own `payment_id`. The platform must resolve the tenant from the payment record.
2. **Narrow bypass**: `is_local = true` means the setting cannot persist beyond the current transaction boundary.
3. **NOT user-controllable**: `app.is_platform_context` is set exclusively by server-side code, never from request headers or client payload.
4. **No arbitrary tenant reads**: The platform context is used solely for a targeted `WHERE provider = ? AND provider_payment_id = ?` lookup. The result constrains the subsequent `runForTenant()` scope.

### Why `payment_webhook_events` Has No RLS

`payment_webhook_events` is a platform-level deduplication ledger. Its `tenant_id` is initially null until resolved. Adding RLS would prevent recording webhook events before tenant resolution — making idempotency checks impossible for unresolvable events.

### Consequences

- This bypass is a deliberate, narrow exception to the fail-closed RLS model.
- Any future use of `app.is_platform_context` MUST be reviewed as a security-critical change.
- Scope is strictly limited to: `PaymentService::handleWebhook()` → payment lookup only.

---

## Decision 4: Payment State Machine Invariants & Monotonic Webhook Guard

### Decision

**Valid transitions**:
```
pending         → processing
pending         → failed
pending         → cancelled
processing      → succeeded
processing      → failed
succeeded       → partially_refunded  (via partial refund)
succeeded       → refunded            (via full refund)
partially_refunded → refunded
```

**Forbidden regressions (hard-rejected)**:
```
succeeded → processing   (REJECTED — PaymentInvalidStateException)
succeeded → pending      (REJECTED)
succeeded → failed       (REJECTED)
cancelled → *            (REJECTED — terminal)
refunded  → *            (REJECTED — terminal)
failed    → succeeded    (REJECTED — must create new Payment)
```

**Monotonic Out-of-Order Webhook Guard**: A `succeeded` payment receiving a late webhook claiming `processing` or `pending` status:
- Records and deduplicates the webhook event
- Returns `out_of_order_ignored` without mutating payment state

### Rationale

1. **Financial correctness**: A succeeded payment represents money received and must never regress through ordinary state mutation.
2. **Webhook delivery ordering**: Provider webhook delivery order is not guaranteed. The monotonic guard ensures idempotent, safe handling of late-arriving events.
3. **Retry semantics**: A `failed` payment requires a **new** `Payment` record (preserving complete audit history), not mutating the failed record back to `pending`.
4. **Refund immutability**: `PaymentRefund` records are separate financial records. The original `Payment.amount_minor` is **never mutated** during refunds.

### Consequences

- Payment retry after failure = new `Payment` + new `PaymentAttempt`. Old `Payment` remains `failed` for audit.
- `PaymentAttempt` is an immutable append-only log of every network call. Retry unit = `Payment`, not `PaymentAttempt`.
- Settlement re-calculation always reads live from `SUM(payments.amount_minor WHERE status IN ('succeeded',...))`.

---

## Decision 5: Semantic Webhook Validation & Sensitive Data Redaction (Phase 1D.1)

### Decision

1. **Amount & Currency Invariant**: Signature authenticity verifies origin but not semantic consistency with the internal record. Every incoming webhook event must satisfy:
   - `incoming.amount_minor === payment.amount_minor` (mandatory on `succeeded` status; validated on any status if supplied). Mismatches throw `PaymentWebhookAmountMismatchException` (HTTP 400 `PAYMENT_WEBHOOK_AMOUNT_MISMATCH`).
   - `incoming.currency === payment.currency` (Phase 1: `IDR`). Mismatches throw `PaymentWebhookCurrencyMismatchException` (HTTP 400 `PAYMENT_WEBHOOK_CURRENCY_MISMATCH`).
   - Mismatched events reject state mutation, emit no domain events, create no side-effects, and persist safe audit metadata.
2. **Sensitive Instrument Redaction**: PAN, CVV, private keys, API secrets, passwords, and tokens are strictly sanitized using `AuditLog::sanitizePayload()` before persistence in `payment_attempts.response_payload`, `payment_webhook_events.payload`, and `payments.metadata`.

### Rationale

Webhooks must never be blindly trusted merely because their HMAC signature is valid. Payload tampering, misconfigured provider routing, or currency conversions could otherwise silently credit an incorrect amount. Sensitive credentials must never be stored in persistent relational ledgers.

---

## Decision 6: Deterministic Idempotent Rejection & Exception Boundary (Phase 1D.1)

### Decision

1. **Deterministic Retry Rejection**: When a provider retries the same `(provider, external_event_id)` that previously failed validation (such as amount mismatch), the system immediately throws the corresponding mismatch exception again rather than masking it as `duplicate_ignored`.
2. **Persistence Outside Rolled-Back Transactions**: When semantic validation fails inside `TenantManager::runForTenant()`, the tenant transaction rolls back (ensuring zero payment mutation), while `handleWebhook` catches the exception to record `STATUS_FAILED` with the stable `error_code` on the platform-level `payment_webhook_events` table before re-throwing.

### Rationale

External payment webhooks rely on deterministic HTTP response codes (400 vs 200) to understand whether to stop retrying or escalate. Masking failed retries as 200 `duplicate_ignored` would falsely signal provider success.

---

## Settlement vs. Accounting Boundary

**Payment succeeded ≠ bank/provider settlement cleared.**

`Payment.status = 'succeeded'` means: the provider acknowledged authorization and funds are in transit or reserved.

**Actual bank settlement** (T+1/T+2 clearing to merchant account) is a future accounting/finance domain concern not implemented in Phase 1D. No bank reconciliation logic exists. Do not claim bank settlement is complete based solely on payment status.

---

## Related ADRs

- [ADR-0001](./0001-tenant-isolation.md): Tenant Isolation (RLS basis)
- [ADR-0004](./0004-offline-first-sync.md): Offline-First Sync (client payment state machine)
- [ADR-0005](./0005-pos-inventory-location-and-nested-bom-consumption.md): POS Inventory Location & BOM (Sale finalization boundary)
