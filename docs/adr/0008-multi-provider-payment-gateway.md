# ADR-0008: Multi-Provider Payment Gateway Architecture, Duitku Adapter, and Superadmin Platform Management

**Status**: Accepted  
**Date**: 2026-10-05  
**Phase**: 1E.3 — Multi-Provider Payment Gateway + Duitku + Superadmin  

---

## Context

Following Phase 1E (Midtrans Adapter) and Phase 1E.1 / Phase 1E.2, SeWaroeng requires expanding its payment infrastructure from a single payment gateway to a **multi-provider payment architecture** supporting both **Midtrans** and **Duitku**, while maintaining strict SaaS tenant isolation and introducing platform-scoped Superadmin management.

In multi-tenant SaaS, businesses have distinct payment gateway accounts with individual merchant credentials, fees, and settlement arrangements. A single global platform credential is insufficient and violates tenant autonomy. Furthermore, sensitive credentials must never be stored in plaintext or exposed to client applications.

---

## Architectural Decisions

### 1. Multi-Provider Abstraction Architecture

The payment architecture follows a pure dependency-inversion model:

```
Payment Core (PaymentService)
      │
      ▼
PaymentProviderManager
      │
      ├── MidtransPaymentProvider ─── implements ──┐
      ├── DuitkuPaymentProvider   ─── implements ──┼─► PaymentProviderInterface
      ├── ManualCashPaymentProvider ─ implements ──┤
      └── MockPaymentProvider     ─── implements ──┘
```

- **`PaymentProviderInterface`**: Defines provider contracts (`initiatePayment`, `handleWebhook`, `queryStatus`, `processRefund`, `supportsRefund`, `withConnection`, `verifyConnection`, `getProviderName`).
- **`PaymentProviderManager`**: Resolves provider instances statically or parameterized with tenant-specific connection configurations via `resolveForTenant($provider, $tenantId)`.
- **Zero Provider Logic in PaymentService**: `PaymentService` contains zero gateway-specific conditionals (`if ($provider === 'duitku')`). All provider communication, signature calculations, header generation, and status mapping are completely encapsulated within each provider adapter.

---

### 2. SaaS Tenancy & Tenant Provider Connections

Each tenant/business maintains independent payment gateway connections stored in `tenant_payment_connections`:

- **Primary Identification**:
  - `id`: UUIDv7
  - `tenant_id`: Foreign key referencing `tenants.id` (enforced with PostgreSQL Row Level Security)
  - `provider`: `midtrans`, `duitku`, etc.
  - `environment`: `sandbox` or `production`
  - Invariant constraint: `UNIQUE (tenant_id, provider, environment)`
- **Configuration & State**:
  - `status`: `active` or `inactive`
  - `is_default`: Flag indicating default provider for digital transactions
  - `merchant_identifier`: Merchant ID / Code (e.g., Duitku merchantCode, Midtrans merchantId)
  - `verification_status`: `unverified`, `verified`, or `failed`
  - `verified_at`: Timestamp of last successful verification
  - `last_error_code` / `safe_error_summary`: Safe error metadata without credentials
  - `capabilities`: Dynamic array of supported capabilities (e.g. `['qris', 'va', 'ewallet']`)

---

### 3. Secret Storage & Encryption at Rest

- **Application-Level Encryption**: All provider credentials (API keys, server keys, secrets) are encrypted before insertion into PostgreSQL using Laravel's `encrypted:array` cast (AES-256-CBC with application key).
- **Masking Guarantees**:
  - Secrets are never returned in plaintext by administrative APIs.
  - Public representation always masks keys (e.g. `************ABCD`).
  - Serialized models hide `encrypted_credentials` (`$hidden = ['encrypted_credentials']`).
  - Neither audit logs, application logs, exception traces, nor frontend clients ever receive plaintext secrets.

---

### 4. Platform-Scoped Superadmin Role & Context

- **Platform vs Tenant Context**: A Superadmin manages the SeWaroeng platform, provider registry, and tenant gateway credentials, but is **not** a tenant role (unlike `owner`, `manager`, or `cashier`).
- **Authorization**:
  - Superadmin status is designated via `users.is_superadmin = true`.
  - Platform permissions: `platform.payment_provider.view`, `platform.payment_provider.manage`, `platform.payment_provider.verify`, `platform.payment_provider.enable`, `platform.payment_provider.disable`.
  - Enforced via `EnsurePlatformSuperadmin` middleware on `/api/v1/platform/*` routes.
- **Controlled Platform Context**:
  - Superadmin requests execute in explicit platform context (`app.is_platform_context = true`), allowing read/write on platform definitions and tenant connection records without arbitrary tenant header switching or RLS leakage.

---

### 5. Duitku Payment Provider Adapter

`App\Services\Payment\Providers\DuitkuPaymentProvider` implements official Duitku POP specifications:

#### A. Create Invoice (POP API)
- **Endpoints**:
  - Sandbox: `https://api-sandbox.duitku.com/api/merchant/createInvoice`
  - Production: `https://api-prod.duitku.com/api/merchant/createInvoice`
- **Authentication**:
  - Headers: `x-duitku-signature`, `x-duitku-timestamp`, `x-duitku-merchantcode`
  - Signature formula: `hash_hmac('sha256', $merchantCode . $timestampMs, $apiKey)`
- **Payload & ID Mapping**:
  - `paymentAmount`: Mapped from `Payment.amount_minor` (IDR integer).
  - `merchantOrderId`: Mapped from `Payment.reference_number` (`PAY-YYYYMMDD-XXXXXX`).
  - `returnUrl`, `callbackUrl`: Configured platform callback endpoints.
  - Response normalized: `provider_payment_id` = Duitku `reference`, `checkout_url` = `paymentUrl`, `va_number` = `vaNumber`, `qr_string` = `qrString`.

#### B. Payment Method Query
- **Endpoint**: Sandbox `https://sandbox.duitku.com/webapi/api/merchant/paymentmethod/getpaymentmethod`
- **Signature formula**: `hash_hmac('sha256', $merchantCode . $amount . $datetime, $apiKey)`
- Dynamic capabilities are parsed and cached conservatively.

#### C. Webhook Callback
- **Route**: `POST /api/v1/payments/webhooks/duitku`
- **Content-Type**: `application/x-www-form-urlencoded`
- **Signature formula**: `hash_hmac('sha256', $merchantCode . $amount . $merchantOrderId, $apiKey)`
- **Multi-Tenant Routing**:
  1. Identifies tenant via `merchantCode` and `merchantOrderId` lookup.
  2. Verifies HMAC-SHA256 signature using the resolved tenant's encrypted API key.
  3. Enters `TenantManager::runForTenant($tenant, ...)` to ensure strict RLS isolation.
  4. Validates exact amount match and currency (`IDR`).
  5. Processes monotonic status transitions idempotently (returns HTTP 200 with `duplicate_ignored` on replays).

#### D. Transaction Status Inquiry
- **Endpoint**: Sandbox `https://sandbox.duitku.com/webapi/api/merchant/transactionStatus`
- **Signature formula**: `hash_hmac('sha256', $merchantCode . $merchantOrderId, $apiKey)`
- Mappings: `00` = succeeded, `01` = pending, `02` = canceled/failed.
- Rate-limited and bounded status inquiry avoids aggressive polling.

#### E. Refund Semantics
- Duitku POP payment adapter does not inherently support debit refunds (which require dedicated SNAP API integration).
- `supportsRefund(?Payment $payment = null)` explicitly returns `false`.
- Calling `processRefund()` throws `PaymentRefundNotSupportedException` with code `PAYMENT_REFUND_NOT_SUPPORTED`. No funds or ledger entries are falsely mutated.

---

### 6. Provider Health & Verification

Superadmin endpoints provide a safe `POST /api/v1/platform/tenants/{tenant}/payment-connections/{provider}/verify` operation:
- **Duitku Verification**: Executes a lightweight `getPaymentMethod` capability check using configured credentials.
- **Midtrans Verification**: Performs a non-destructive status check or ping against the API.
- Never creates a fake customer charge.
- Updates `verification_status` (`verified` / `failed`), `verified_at`, and safe error summaries.

---

### 7. Security Hardening & Database Invariants (Phase 1E.3.1)

To harden multi-provider operations against race conditions, injection, and invalid states:

#### A. Code-Authoritative Adapter Registry
- `PaymentProviderManager::ALLOWLISTED_ADAPTERS` defines an immutable code-level mapping (`provider_key => AdapterClass`).
- The database `adapter_class` column serves purely as descriptive metadata and is **never** used to dynamically resolve or instantiate classes, preventing arbitrary class loading attacks.

#### B. Database-Enforced Merchant Identifier Uniqueness
- Table `tenant_payment_connections` enforces `UNIQUE (provider, environment, merchant_identifier)`.
- Guarantees deterministic zero-or-one resolution for inbound webhooks (e.g. Duitku `merchantCode`), preventing cross-tenant identifier collisions.

#### C. Database-Enforced Single Default Provider
- Table `tenant_payment_connections` enforces a PostgreSQL partial unique index:
  ```sql
  CREATE UNIQUE INDEX uq_tenant_env_default_connection 
  ON tenant_payment_connections (tenant_id, environment) 
  WHERE is_default = TRUE;
  ```
- Guarantees at the database engine level that no tenant can ever have more than one default payment provider per environment under concurrent execution.

#### D. Credential Rotation Lifecycle
- Any update to `encrypted_credentials`, `merchant_identifier`, or `environment`:
  - Automatically resets `verification_status` to `unverified`.
  - Clears `verified_at`, `last_error_code`, and `last_error_message`.
  - Automatically unsets `is_default` to `false`.
- A connection must be re-verified via Superadmin `verifyConnection()` before it can be used for live payments or chosen as default.

#### E. Effective Provider Availability Resolver
- Centralized in `PaymentProviderAvailabilityResolver`:
  - `manual` / `cash`: Always available (special internal synchronous provider).
  - `mock`: Available in local/testing environments.
  - External gateways (`midtrans`, `duitku`): Available if and only if:
    1. Platform provider definition `is_enabled == true`.
    2. Tenant payment connection exists for the matching environment.
    3. Connection `status == 'active'`.
    4. Connection `verification_status == 'verified'`.
- Inactive or unverified connections can never be selected as the effective default for payment creation.

#### F. Fail-Closed Webhook Routing
- `PaymentService::handleWebhook` enforces provider-agnostic extraction (`$provider->extractMerchantIdentifier($request)`).
- Webhook lookup strictly fails closed:
  - Unknown `merchant_identifier` → rejects with HTTP 400 (`WEBHOOK_SIGNATURE_INVALID`).
  - Environment mismatch → rejects with HTTP 400 (`WEBHOOK_SIGNATURE_INVALID`).
  - Cross-tenant injection attempt → rejects with HTTP 400 (`WEBHOOK_SIGNATURE_INVALID`).

---

## Verification Status

| Provider | Adapter Implementation | Automated Test Suite | Real Sandbox E2E | Status |
|---|---|---|---|---|
| **Midtrans** | `MidtransPaymentProvider` | 34 Tests Passed | Not Verified (No Sandbox Credentials) | **CODE VERIFIED / SANDBOX NOT VERIFIED** |
| **Duitku** | `DuitkuPaymentProvider` | 13 Tests Passed | Not Verified (No Sandbox Credentials) | **CODE VERIFIED / SANDBOX NOT VERIFIED** |

Total backend test suite: **250 passed (1119 assertions)**.  
Frontend test suite: **68 passed**.
Pint style check: **PASS (0 violations)**.  
Flutter analyze: **PASS (0 issues)**.

