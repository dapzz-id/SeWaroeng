# ADR-0007: Midtrans Production Payment Gateway Integration

**Status**: Accepted
**Date**: 2026-10-05
**Phase**: 1E — Production Payment Provider Integration

---

## Context

Following the completion of Phase 1D (Payment & Settlement Foundation) and Phase 1D.1 (Payment Security & Validation Hardening), SeWaroeng requires the integration of its first official production payment gateway provider (`TARGET_PROVIDER`).

For the Indonesian food and beverage (F&B) and micro-retail market, QRIS (Quick Response Code Indonesian Standard), Virtual Accounts (VA), and e-Wallets (GoPay, ShopeePay, OVO, DANA) are the predominant digital payment rails. Midtrans (Core API / Snap) is selected as the primary production payment gateway adapter.

This ADR documents the architectural decisions, security boundaries, status mappings, webhook verifications, and timeout policies established for the Midtrans adapter.

---

## Decision 1: Provider Selection & Isolation Boundary

### Decision

**Midtrans** is officially selected and implemented as the first production payment gateway adapter via:
`App\Services\Payment\Providers\MidtransPaymentProvider`

The adapter strictly implements `App\Services\Payment\Contracts\PaymentProviderInterface`.

Core payment services (`PaymentService`, `Sale`, `Payment`, `PaymentAttempt`, `PaymentRefund`, `PaymentWebhookEvent`) remain **100% provider-agnostic**. Under no circumstances are `if ($provider === 'midtrans')` or provider-specific conditionals introduced inside `PaymentService`.

All Midtrans-specific API endpoints, JSON request structures, response schemas, and signature computations are completely encapsulated within `MidtransPaymentProvider`.

---

## Decision 2: Authentication & Environment Separation

### Decision

Midtrans authentication uses HTTP Basic Authentication over TLS:
- Username: `ServerKey`
- Password: empty string (`""`)
- Encoded as `Authorization: Basic base64(ServerKey . ':')`

Configuration is managed server-side via `config/services.php` sourced strictly from environment variables:
- `MIDTRANS_SERVER_KEY`
- `MIDTRANS_CLIENT_KEY`
- `MIDTRANS_MERCHANT_ID`
- `MIDTRANS_IS_PRODUCTION` (boolean: `true` for Production, `false` for Sandbox)
- `MIDTRANS_BASE_URL` (optional override)

### Environment Endpoints:
- **Sandbox**: `https://api.sandbox.midtrans.com`
- **Production**: `https://api.midtrans.com`

### Client Secret Boundary:
Neither `ServerKey` nor any private signing credential is ever transmitted to the Flutter client, logged into database audit tables, or returned in API responses. The Flutter POS client only ever receives normalized, client-safe payment references and presentation data (e.g. `qr_string`, QR action URLs, VA numbers).

---

## Decision 3: Payment Initiation & Safe Presentation

### Initiation Flow

1. Cashier initiates digital payment via POS (`POST /api/v1/payments` followed by `POST /api/v1/payments/{payment}/process`).
2. Server validates that the associated `Sale` is finalized and outstanding balance is sufficient.
3. Server initializes `PaymentAttempt` with `attempt_number`, `status = processing`, and `requested_amount_minor = Payment.amount_minor`.
4. Provider adapter constructs authoritative charge request:
   - `order_id`: Authoritative internal reference (`Payment.reference_number`, e.g. `PAY-20261005-ABCDEF`).
   - `gross_amount`: Server-authoritative integer amount (`Payment.amount_minor`).
   - `currency`: Validated strictly as `IDR`. Any foreign currency is rejected prior to dispatch.
   - `payment_type`: Resolved from payment method (`qris`, `bank_transfer`, `gopay`).
5. HTTP request dispatched to Midtrans `POST /v2/charge`.
6. Midtrans returns HTTP 201 Created with `transaction_status: 'pending'`, `transaction_id`, and QR / VA metadata.
7. Adapter extracts client-safe presentation metadata:
   - `qr_string` (raw EMVCo QRIS string for on-screen rendering).
   - `actions` (QR image download URLs).
   - `va_numbers` (virtual account numbers for bank transfers).
   - `expiry_time`.
8. State persists as `Payment::STATUS_PROCESSING` awaiting customer fulfillment and webhook confirmation.

---

## Decision 4: Status Mapping

Midtrans status codes and fraud statuses are explicitly mapped into SeWaroeng core payment lifecycle states:

| Midtrans `transaction_status` | Midtrans `fraud_status` | SeWaroeng `Payment.status` | Description |
|---|---|---|---|
| `settlement` | any | `succeeded` | Definitive settlement into merchant account |
| `capture` | `accept` | `succeeded` | Credit card captured successfully |
| `capture` | `challenge` | `processing` | Flagged by fraud detection, awaiting review |
| `pending` | any | `processing` | Awaiting QR scan or customer transfer |
| `deny` | any | `failed` | Payment rejected by bank or issuer |
| `cancel` | any | `cancelled` | Payment cancelled by merchant or customer |
| `expire` | any | `failed` | Transaction window expired without payment |
| `refund` | any | `refunded` | Total refund completed |
| `partial_refund` | any | `partially_refunded` | Partial refund completed |
| *(unknown / unrecognized)* | any | `processing` | Defensive fallback; never false-fail |

---

## Decision 5: Webhook Signature Verification & Idempotency

### Signature Verification Algorithm

Midtrans signs webhook notifications using SHA-512 over the concatenated values of `order_id`, `status_code`, `gross_amount`, and the server key:

$$\text{Signature} = \text{SHA512}(\text{order\_id} \parallel \text{status\_code} \parallel \text{gross\_amount} \parallel \text{ServerKey})$$

- Verification uses timing-attack-safe `hash_equals($expected, $signatureKey)`.
- If any required component or the server key is missing, verification fails immediately.
- Invalid signature returns HTTP 400 (`WEBHOOK_SIGNATURE_INVALID`) prior to any database mutation or payment state change.

### Idempotency & Event Deduplication

To ensure robust idempotency against webhook retries:
- Webhook events are logged in `payment_webhook_events` with `UNIQUE(provider, external_event_id)`.
- Midtrans does not provide a separate unique webhook delivery ID; therefore, the adapter derives a deterministic event identity:
  $$\text{external\_event\_id} = \text{id\_event} \;\lor\; \text{transaction\_id} \mathbin{\Vert} \text{status\_code} \mathbin{\Vert} \text{transaction\_status}$$
- Duplicate deliveries of the exact same event return `status: duplicate_ignored` with HTTP 200, generating no redundant audit logs or domain events.
- Monotonic state protections ensure late-arriving out-of-order events (e.g. `pending` received after `settlement`) return `status: out_of_order_ignored` without mutating terminal states.

### Defense-in-Depth Cross Validation

`PaymentService` enforces two strict checks on all incoming webhook events:
1. **Currency Invariant**: `$eventData['currency'] === $payment->currency` (`PaymentWebhookCurrencyMismatchException` $\rightarrow$ 400).
2. **Amount Invariant**: `$eventData['amount_minor'] === $payment->amount_minor` (`PaymentWebhookAmountMismatchException` $\rightarrow$ 400).

---

## Decision 6: Multi-Tenant RLS Security Boundary

Webhook callbacks originate from external public gateway servers and contain **no authenticated tenant session**.

### Security Rules:
1. `X-Tenant-ID` and client-supplied tenant headers are **completely ignored** on the public webhook ingestion route (`POST /api/v1/payments/webhooks/midtrans`).
2. Webhook ingestion operates initially under platform context (`app.is_platform_context = true`) solely to resolve the target `Payment` by `reference_number` (`order_id`) or `provider_payment_id` (`transaction_id`).
3. Once the target payment is resolved, the tenant is determined server-side:
   `$tenantId = $payment->tenant_id`
4. The remaining mutation, payment attempt update, audit logging, and domain event dispatch are executed strictly inside `TenantManager::runForTenant($tenantId, ...)`, establishing full PostgreSQL Row-Level Security (RLS) enforcement.
5. If the payment reference cannot be resolved, the webhook event is marked `PAYMENT_NOT_FOUND` and safely halts without throwing cross-tenant errors.

---

## Decision 7: Network Timeout, Retry & Status Query Policy

### Network Timeout Safety

When contacting Midtrans APIs:
- A network timeout (`Illuminate\Http\Client\ConnectionException`) or 5xx gateway server error represents an **unknown outcome**.
- The adapter **must not** mark the payment `failed`, because money may have already been debited from the customer's account.
- The adapter returns `status: Payment::STATUS_PROCESSING` with `response_code: 'NETWORK_TIMEOUT'` or `'GATEWAY_5XX'`.

### Status Query Identifier Rules & Response Validation

Midtrans provides status inquiries via `GET /v2/{identifier}/status`. The identifier must be dynamically resolved (`resolveStatusIdentifier`):
1. **Standard Core API (QRIS, Cards, Bank Transfer)**: Uses authoritative internal reference `order_id = Payment.reference_number`.
2. **BI SNAP & DANA**: Current Midtrans documentation mandates `transaction_id` (`Payment.provider_payment_id`) for status inquiries on BI SNAP transactions or DANA e-wallet channels.
3. **Response Validation**: Merely receiving HTTP 200 does not mutate payment state. The adapter strictly verifies:
   - `order_id`: Must match `Payment.reference_number`.
   - `gross_amount`: Must match `Payment.amount_minor`.
   - `currency`: Must match `Payment.currency` (`IDR`).
   Any mismatch immediately aborts with `STATUS_QUERY_ORDER_MISMATCH`, `STATUS_QUERY_AMOUNT_MISMATCH`, or `STATUS_QUERY_CURRENCY_MISMATCH`, preventing unauthorized state mutation.
4. **Monotonic Guards**: An already succeeded payment can never be demoted back to processing or failed.

---

## Decision 8: Hardened Refund Lifecycle & Semantics

In accordance with official Midtrans documentation and acquiring network clearing realities:

### 1. HTTP Acceptance vs Financial Clearing
- Midtrans Direct Refund API (`POST /v2/{order_id}/refund`) returns HTTP 200 `"Success, refund request is accepted"`. This signifies API request acceptance, **NOT** financial settlement.
- **State Progression**:
  $$\text{pending} \longrightarrow \text{processing} \longrightarrow \text{completed} \quad (\text{or } \text{failed})$$
- Initial refund dispatch sets `PaymentRefund.status = 'processing'`.
- The refund is marked `completed` **ONLY** when `bank_confirmed_at` is confirmed via Midtrans webhook notification or status query inquiry.

### 2. Supported vs Unsupported Methods
- **Supported Methods**: QRIS (supported acquirers), GoPay, ShopeePay, Credit Card.
- **Unsupported Methods**: Bank Transfer / Virtual Accounts (BCA, BNI, BRI, Mandiri, Permata). Midtrans Core API does not support direct refunds for VA without manual disbursement (Iris).
- Attempting to refund an unsupported payment method immediately fails with stable code `PAYMENT_REFUND_NOT_SUPPORTED` and throws `PaymentRefundNotSupportedException`. No false `completed` state is ever recorded.

### 3. Webhook Mapping & Amount Validation
- Midtrans refund notifications are mapped directly to the exact internal `PaymentRefund` record via `PaymentRefund.reference_number = refund_key`.
- Incoming refund amount is validated: $\text{incoming refund amount} == \text{internal PaymentRefund.amount\_minor}$.
- On mismatch: the webhook fails with `REFUND_AMOUNT_MISMATCH` and throws `PaymentWebhookAmountMismatchException`.
- Once `bank_confirmed_at` is present, the refund transitions to `completed`, and `Payment.status` transitions to `partially_refunded` or `refunded`.
- **Authoritative Invariant**: Original `Payment.amount_minor` remains strictly immutable.

### 4. Idempotency on Network Timeout
- If a timeout (`ConnectionException`) occurs during refund dispatch, the refund remains in `processing` with `response_code: 'REFUND_NETWORK_TIMEOUT'`.
- The internal `refund_key` is strictly retained and never blindly regenerated, allowing idempotent reconciliation via status query or webhook.

---

## Decision 9: Known Provider Limitations

1. **Bank Transfer / Virtual Account Refunds**: Midtrans does not support automated direct API refunds for standard Virtual Account (BCA, Mandiri, BNI, BRI) payments without enterprise disbursement APIs (Iris). Attempted refunds fail safely with `PAYMENT_REFUND_NOT_SUPPORTED`.
2. **Webhook Rate Limits**: Midtrans recommends acknowledging webhooks within 5 seconds with HTTP 200/400. Ingestion is lightweight and protected by application rate limiting (`throttle:60,1`).
3. **Sandbox vs Production Behavior**: Midtrans Sandbox generates simulated QR codes and mock payment simulator interfaces. Real sandbox clearing occurs asynchronously.

---

## Decision 10: Environment Separation & Verification Status

To ensure complete honesty and precision in audit reporting, testing tiers are strictly separated:
- **Unit Tests (`CODE VERIFIED`)**: Provider behavior and payload simulation.
- **Feature Tests (`CODE VERIFIED`)**: Full SeWaroeng application lifecycle, database state transitions, and idempotency tests.
- **Real Sandbox Integration (`SANDBOX VERIFIED` vs `NOT VERIFIED`)**: Actual HTTP interactions against `https://api.sandbox.midtrans.com` using live credentials.

A dedicated Artisan command is provided for on-demand live sandbox verification:
```bash
php artisan midtrans:verify-sandbox --server-key=SB-Mid-server-YOUR-REAL-KEY
```

### Verification Matrix (Phase 1E.2)

| Capability | Code | Sandbox |
|---|---|---|
| QRIS initiation | VERIFIED | NOT VERIFIED |
| Webhook | VERIFIED | NOT VERIFIED |
| Status inquiry | VERIFIED | NOT VERIFIED |
| Refund | VERIFIED | NOT VERIFIED |
| Expiry | VERIFIED | NOT VERIFIED |
| Timeout behavior | VERIFIED | N/A |

*Note: In accordance with credential safety protocols, Sandbox verification was halted safely without fabricating success because the local environment (`.env`) contains placeholder credentials (`SB-Mid-server-test-placeholder`). Live sandbox execution can be run on-demand via `php artisan midtrans:verify-sandbox --server-key=...` once real merchant credentials are provided.*

---

## Consequences & Verification

- **Pint Style Conformance**: Passed with 0 violations (`vendor\bin\pint --test`).
- **Backend Test Suite**: 223 tests passed (995 assertions), including 34 dedicated `MidtransPaymentProviderTest` feature tests covering all QRIS, VA, Webhook, status query, and refund lifecycle cases.
- **Flutter Test Suite**: 67 passed with 0 failures, `flutter analyze` clean with 0 warnings.
- **Gate Status**: `PASS WITH LIMITATIONS` (Explicitly: `SANDBOX NOT VERIFIED`).

