# ADR-0016: V1 WhatsApp Integration & Customer Engagement Architecture

**Status**: Accepted  
**Date**: 2026-10-09  
**Phase**: V1 WhatsApp Integration & Customer Engagement  

---

## Context

Per `docs/SeWaroeng_PRD_v1.0.md` (Sections 19, 36) and `docs/architecture/foundation.md`, SeWaroeng requires transactional notification capabilities via WhatsApp to deliver digital receipts and order updates directly to customers.

However, WhatsApp is an external, asynchronous, rate-limited third-party communication channel. Integrating it directly into synchronous transactional workflows (such as point-of-sale checkout, payment processing, inventory deductions, or financial journal posting) would introduce unacceptable failure coupling, latency spikes, and potential transaction rollbacks.

Furthermore, credential security, customer marketing consent, subscription entitlement, and PostgreSQL Row-Level Security (RLS) multi-tenancy must be strictly preserved.

---

## Architectural Principles & Decisions

### Decision 1: Authority Separation & Failure Isolation

WhatsApp is **NOT** a source of truth for transactions, payments, customer identity, or accounting. The domain authority chain is strictly hierarchical:

```text
Sale (POS Authority)
   ↓
Payment (Settlement Authority)
   ↓
Finance Journal / Inventory Ledger (Canonical Double-Entry & Stock Truth)
   ↓ (Post-Commit Domain Event)
WhatsApp Notification Dispatcher (Async Outbox / Event Listener)
   ↓
WhatsApp Channel Adapter (Meta Cloud API / Mock Provider)
   ↓
Customer Delivery Status (Auditable Notification Log)
```

**Key Invariant**:
```text
WhatsApp failure ≠ Sale failure
WhatsApp failure ≠ Payment failure
WhatsApp failure ≠ Inventory failure
WhatsApp failure ≠ Finance failure
```

Provider timeouts, rate limits (HTTP 429), authentication failures, or transient network partitions **NEVER** abort or roll back a committed sale, payment settlement, inventory deduction, or journal entry. All event listeners are wrapped in dedicated failure-isolation blocks.

---

### Decision 2: Provider-Agnostic Abstraction Architecture

Business services, POS workflows, and notification dispatchers are decoupled from specific messaging vendors via an adapter pattern:

```text
Domain Events (PaymentSucceeded, SaleFinalized)
      ↓
WhatsAppNotificationService (Domain Logic, Consent, Entitlement)
      ↓
WhatsAppProviderManager (Registry & Resolver)
      ↓
WhatsAppProviderInterface (Contract)
      ├── MetaCloudWhatsAppProvider (Official Meta Cloud API)
      └── MockWhatsAppProvider (Deterministic Unit & Integration Testing)
```

1. **`WhatsAppProviderInterface`**: Defines contracts for `sendMessage`, `verifyConnection`, and `parseWebhookPayload`.
2. **`WhatsAppProviderManager`**: Resolves provider adapters dynamically based on tenant connection settings, preventing hardcoded vendor logic in controllers.
3. **`MockWhatsAppProvider`**: Supports deterministic simulation of success, rate limits, 500 server errors, timeouts, and invalid recipients without external network dependencies.
4. **`MetaCloudWhatsAppProvider`**: Implements official Meta WhatsApp Cloud API specifications, utilizing template messages and interactive text messages with Bearer authorization and error categorization.

---

### Decision 3: Credential Security & Masking

All provider secrets (Meta System User Access Tokens, App Secrets, Webhook Verify Tokens) are treated with maximum security:

1. **Encrypted at Rest**: Stored using Laravel `encrypted:array` cast (AES-256-CBC) in the `tenant_whatsapp_connections.credentials` column.
2. **Never Returned to Frontend**: Secret values are strictly masked in JSON serialization (`masked_credentials`: e.g. `EAA***`), preventing exposure via REST endpoints.
3. **No Logging**: Credentials, Authorization headers, and raw secrets are prohibited in application logs, audit trails, and exception messages.
4. **Automatic Re-verification**: Whenever credentials are modified, `verification_status` automatically resets to `unverified` until explicit verification succeeds.

---

### Decision 4: Customer Positive Consent, Opt-Out & Audit Provenance

To comply with Indonesian consumer protection laws and official Meta WhatsApp Business Messaging Policy:

1. **Phone Normalization**: All recipient phone numbers are canonicalized via `PhoneNormalizer` into Indonesian E.164 format (`+628...` / `628...`).
2. **Positive Consent Invariant**: Mere presence of a phone number does not confer consent. WhatsApp communication strictly requires affirmative consent (`whatsapp_opt_in = true` or consent record).
   - **Transactional Messages** (digital receipts, order updates): Require affirmative transactional opt-in.
   - **Marketing Messages**: Strictly prohibited without dual opt-in (`whatsapp_opt_in = true` AND `marketing_opt_in = true`).
3. **Tenant-Scoped Consent Isolation**: Consent is bounded to the specific tenant (`tenant_id`). Tenant A cannot leverage consent granted to Tenant B.
4. **Manual Override Protection**: Cashier phone overrides are checked against registered customer records for that tenant. Manual overrides without positive consent on file are rejected (`CONSENT_REQUIRED`).
5. **Opt-Out Enforcement**:
   - Customers can opt out via inbound keyword (`STOP`, `UNSUBSCRIBE`) or manual administrative request (`Customer::optOutWhatsApp()`).
   - Sets `whatsapp_opt_in = false`, `whatsapp_opted_out = true`, and `whatsapp_opted_out_at = now()`.
   - Opt-out is strictly respected by automatic event dispatchers, background queue retries (even if opted out between enqueue and execution), and manual resends (`RECIPIENT_OPTED_OUT` / `CUSTOMER_OPTED_OUT`).
6. **Audit Provenance**: All consent changes and opt-outs are appended chronologically to the `whatsapp_consent_history` JSONB array on the customer record with `tenant_id`, `category`, `source`, `timestamp`, `granted`, and `notes`. Historical consent records are never deleted.

---

### Decision 5: Message Lifecycle & Monotonic State Machine

Every outbound message is tracked in the `whatsapp_messages` ledger with an auditable, monotonic state machine:

```text
pending → sending → sent → delivered → read
                    ↘ failed
```

- **Monotonic Progression**: Late or out-of-order webhook callbacks cannot downgrade a message status (e.g., a delayed `sent` webhook cannot overwrite `delivered` or `read`).
- **Terminal Failed State**: Unrecoverable provider rejections transition to `failed` with sanitized error codes and messages for administrative visibility.
- **Auditable Metadata**: Correlated via `idempotency_key`, `correlation_id`, `provider_message_id`, and transition timestamps (`sent_at`, `delivered_at`, `read_at`).

---

### Decision 6: Asynchronous Delivery, Commit Boundary, Queue Retries & Durable Outbox Recovery

1. **After-Commit Queuing**: `PaymentSucceeded` and `SaleFinalized` events create an outbox record (`WhatsAppMessage` status `pending`) and dispatch `SendWhatsAppReceiptJob` with `$this->afterCommit = true`. No synchronous provider HTTP calls take place during open database transactions.
2. **Transaction Rollback Protection**: If a database transaction rolls back, all after-commit queue jobs are discarded by Laravel's `DatabaseTransactionsManager`. Zero messages are sent on rollbacks.
3. **Durable Enqueue Fault Isolation**: If queue broker connection fails during dispatch (e.g. Redis outage), the exception is trapped without aborting the POS or payment transaction. The outbox record captures `status = failed`, `error_code = 'ENQUEUE_FAILED'`, preserving full observability and reprocessing ability.
4. **Queue Driver Requirement**: Production deployments require durable queue drivers (`database` or `redis`) with active workers (`php artisan queue:work --queue=default`). `QUEUE_CONNECTION=sync` does not satisfy production asynchronous isolation.
5. **Idempotency Key**: Outbound receipt notifications generate unique keys: `whatsapp:receipt:{sale_id}`. Duplicate checkout events or retried webhook triggers are deterministically deduplicated.
6. **Bounded Retry Strategy**: Bounded retries (`$tries = 3`, `$backoff = [5, 15, 45]`) for transient errors (HTTP 429, 500, ambiguous timeouts). Ambiguous timeouts are classified as `AMBIGUOUS_TIMEOUT` and retried safely without assuming definitive failure.
7. **Terminal Failure Protection**: Non-retryable errors (invalid credentials, template rejections) fail terminally without wasting retry budget.
8. **Failure Visibility & Resend**: Authorized manual resend endpoint (`POST /api/v1/whatsapp/messages/{id}/resend`) verifies recipient consent and opt-out prior to resending.
9. **Durable Outbox Recovery Dispatcher (`WhatsAppOutboxRecoveryService`)**:
   - Independent background recovery daemon / scheduled task (`php artisan whatsapp:outbox-recover` scheduled every 5 minutes via `routes/console.php` with `withoutOverlapping()`).
   - Recovers: (a) stranded records with `status = 'pending'` older than 2 minutes where application crashed between commit and queue enqueue, (b) failed enqueue records (`status = 'failed'`, `error_code = 'ENQUEUE_FAILED'`), and (c) worker in-flight crashes (`status = 'sending'` older than 15 minutes without `provider_message_id`).
   - Idempotency & Locking: Atomic state updates (`status = 'sending'`, incremented `attempts`) prevent double-dispatch between concurrent workers and recovery tasks.
   - Terminal Status Guard: Messages in `sent`, `delivered`, or `read` are never resent.
   - Customer Opt-Out Re-check: If customer opted out between transaction commit and recovery, recovery aborts dispatch with `CUSTOMER_OPTED_OUT`.
   - Domain Invariant: Recovery never mutates POS Sales, Payments, accounting journals, or inventory ledgers.

---

### Decision 7: Webhook Security, Platform App Secret Ownership & Isolation

1. **Dual Endpoint Architecture**:
   - **Platform-Scoped Endpoint** (`POST /api/v1/webhooks/whatsapp/{provider}`): Designed for single Meta App multi-tenant architectures. Authenticates `X-Hub-Signature-256` using platform `services.whatsapp.meta.app_secret` via constant-time `hash_equals` over raw body. Maps verified `phone_number_id` to the active tenant connection.
   - **Tenant-Scoped Endpoint** (`POST /api/v1/webhooks/whatsapp/{tenant}/{provider}`): Authenticates against tenant-specific credentials.
2. **Cross-Tenant Evidence Rejection**: Incoming webhooks verify that payload `phone_number_id` matches the tenant connection. Mismatched evidence is rejected with a security log.
3. **GET Verification Challenge**: Supports Meta's `hub.challenge` webhook subscription handshake with strict verify-token validation.
4. **Platform Context & RLS**: External webhooks arrive unauthenticated from the public internet without an active tenant session. The webhook controller safely executes connection lookup under platform context, then processes the event strictly within `runForTenant($tenantId, ...)`.
5. **No Financial Modification**: Delivery callbacks update delivery status only; they **CANNOT** alter sales, payments, or point ledgers.

---

### Decision 8: Subscription Entitlement & Multi-Outlet RBAC

1. **Entitlement Enforcement**: Tenants must have active subscription entitlement for the `whatsapp` module (`$entitlementService->assertCan($tenant, 'whatsapp')`). Unentitled tenants receive HTTP 403.
2. **RBAC Permissions**:
   - `whatsapp.view`: View connection status and message audit log.
   - `whatsapp.manage`: Configure provider credentials and verification.
   - `whatsapp.send`: Trigger manual receipt sending and resend.

---

### Decision 9: Graph API Version Policy & Template Contracts

1. **Configurable Graph API Version & Official Lifecycle**:
   - **Audit Date**: 2026-10-10.
   - **Audit Acuan**: Meta Graph API Changelog & WhatsApp Business Platform Documentation.
   - **Expired Version Removal**: `v20.0` reached End-of-Life on September 24, 2026. Whitelist support is removed; requests specifying `v20.0` are rejected immediately with HTTP 422 / `\InvalidArgumentException`.
   - **Active Supported Versions**:
     - `v21.0`: Released October 2, 2024 — Active until January 21, 2027 (Default).
     - `v22.0`: Released January 21, 2025 — Active until May 20, 2027 (Supported).
   - **Evaluation of Newer Versions (`v26.0`, etc.)**: Graph API `v26.0` (released July 29, 2026) and intermediate versions (`v23.0`-`v25.0`) introduce breaking changes (e.g. Commerce Order Management API deprecation, ad targeting constraints). Newer versions are NOT assumed automatically compatible for WhatsApp Cloud API message contracts without live certified testing and are excluded from the allowlist.
   - **Zero Silent Fallback**: Unsupported and expired versions are strictly rejected on configuration load and API update without silent fallback.
2. **Approved Template Semantics**: Templates configured on connections are validated prior to dispatch. `REJECTED` templates fail fast with `TEMPLATE_REJECTED` without network calls.
3. **Log & Payload Sanitization**: All provider tokens, credentials, and authorization headers are recursively redacted (`[REDACTED]`) before logging or storing raw response metadata.

---

### Decision 11: PRD Section 19 Functional Coverage — Merchant Notifications, Customer Engagement & Safe Natural Language Query

To achieve complete functional closure for V1 WhatsApp requirements (PRD Section 19):

1. **Merchant-Side Operational Notifications**:
   - **Daily Sales Summary** (`TYPE_MERCHANT_DAILY_SUMMARY`): Aggregates daily net sales, count, and average order value via `DashboardService` read model; triggered manually or scheduled daily at 22:00 via `whatsapp:send-daily-summary`.
   - **Low-Stock Alert** (`TYPE_MERCHANT_LOW_STOCK`): Dispatched when inventory item balance falls below reorder level. Idempotent per item location and date.
   - **Booking Reminder** (`TYPE_MERCHANT_BOOKING_REMINDER`): Dispatched to merchant for upcoming reservations today. Scheduled hourly via `whatsapp:booking-reminders`.
   - **Payment Settlement Status** (`TYPE_MERCHANT_SETTLEMENT`): Dispatched upon payment reconciliation or gateway settlement completion.
   - **Anomaly Alert** (`TYPE_MERCHANT_ANOMALY`): Dispatched upon shift closing cash variances or suspicious operational activities.
   - **Merchant Notification Preferences**: Stored in `tenants.settings.whatsapp_notifications` with granular toggles (`daily_sales_summary`, `low_stock`, `booking_reminder`, `payment_settlement`, `anomaly_alert`). Merchant can disable specific categories without affecting transactional customer messaging.

2. **Customer-Side Engagement**:
   - **Order Confirmation** (`TYPE_ORDER_CONFIRMATION`): Real-time confirmation for accepted table and QR orders.
   - **Booking Confirmation & Reminder** (`TYPE_BOOKING_CONFIRMATION`, `TYPE_BOOKING_REMINDER`): Positive opt-in reservation confirmations and day-of reminders.
   - **Digital Receipt** (`TYPE_TRANSACTIONAL_RECEIPT`): Authoritative POS receipt notification upon payment completion.
   - **Promotions** (`TYPE_MARKETING_PROMO`): Requires strict affirmative dual consent (`whatsapp_opt_in = true` AND `marketing_opt_in = true`). If marketing opt-in is absent, delivery is rejected fail-closed with `MARKETING_OPT_IN_REQUIRED`. Customers can opt out at any time via keyword or settings.
   - **Order Status Update** (`TYPE_ORDER_STATUS_UPDATE`): Dispatched upon operational order state transitions (`preparing`, `ready`, `served`).

3. **Natural Language Business Query (Section 19 Safe Parser)**:
   - Evaluated against V1 vs V2 roadmap: Full AI Business Copilot / LLMs belong to V2 (PRD Section 48).
   - V1 implementation utilizes `WhatsAppBusinessQueryService`, a deterministic, safe, parameterized Indonesian natural language intent parser:
     - "Omzet hari ini?" -> Mapped safely to `DashboardService::getDashboardData('today')`.
     - "Produk paling laku minggu ini?" -> Mapped safely to `DashboardService::getDashboardData('7_days')['top_products']`.
     - "Stok [item] cukup sampai besok?" -> Mapped safely to `InventoryItem::where('name', 'ilike', ...)` stock balances.
     - Unrecognized queries -> Safe guidance response listing supported queries.
   - **Strict Multi-Tenant Boundary**: Bounded to active authenticated tenant and PostgreSQL RLS session; zero raw SQL execution; cross-tenant query attempts return zero records or throw entitlement exceptions.

4. **Generalized Outbox Job & Recovery**:
   - `SendWhatsAppMessageJob`: Generic async queue job handling all Section 19 message types with execution-time consent and entitlement re-validation.
   - `WhatsAppOutboxRecoveryService`: Extended to recover both receipt jobs and generic message jobs across all Section 19 types without duplicate transmission.

---

### Decision 12: Real Provider Integration Status

- **Unit & Integration Verification**: Verified with `MockWhatsAppProvider` across all failure scenarios, rate limits, timeouts, idempotency, and multi-tenant isolation (82 backend tests across 10 WhatsApp test suites, 7 Flutter WhatsApp tests).
- **Official Spec Compliance**: `MetaCloudWhatsAppProvider` written strictly to Meta WhatsApp Cloud API specifications (`v21.0` default, `v22.0` active allowlist; `v20.0` EOL excluded; `v26.0` excluded pending live certification).
- **Production Status**: **`NOT VERIFIED (Mock verified)`** — Live Meta Cloud API credentials, official WABA registration, approved templates, and public HTTPS webhook endpoints are mandatory prerequisites for live production rollout.

---

## Consequences & Verification

- **Backend WhatsApp Test Suite**: 82 passed (338 assertions across 10 test suites, 0 failures).
- **Backend Test Suite (Total)**: 546+ tests passed.
- **Laravel Pint**: PASSED with 0 styling violations (`{"tool":"pint","result":"passed"}`).
- **Flutter Analyze**: 0 issues found (ran in 2.2s).
- **Flutter Test Suite**: 141 passed (ran in 28s).

