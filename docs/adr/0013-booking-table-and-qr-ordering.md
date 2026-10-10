# ADR 0013: Table Domain, Booking Lifecycles, and QR Table Ordering Architecture

## Status
Accepted

## Date
2026-10-06 (V1)

## Context
Following the successful validation and hardening of the SeWaroeng MVP (Auth, Tenancy, RBAC, Catalog, Inventory, Purchasing, Recipe/HPP, POS, Payment, Shift, Customer/CRM, Receipt, Finance, Dashboard, Offline POS, and Subscription), V1 introduces operational dine-in workflows per `docs/SeWaroeng_PRD_v1.0.md`:
1. Physical table management (`tables`).
2. Advance table bookings with overlap protection (`bookings`).
3. Dine-in operational occupancy sessions (`table_sessions`).
4. Secure public QR table ordering (`orders`, `order_items`).

### Architectural Invariants & Non-Negotiable Boundaries:
1. **Zero Financial Bypass**: `Booking` and `Order` are strictly operational domains. Neither is a financial source of truth.
2. **Existing Transactional Authority**: All monetary finalization, inventory decrement, double-entry bookkeeping, shift tracking, and receipts MUST flow exclusively through the established cores (`PosService`, `PaymentService`, `FinanceService`, `ReceiptService`, `InventoryService`).
3. **No Dual Inventory Deduction**: Order submission in the kitchen or table domain never deducts inventory. Inventory is deducted exactly once when the order is converted and finalized into an authoritative `Sale`.
4. **Public QR Security Model**: Public customer QR endpoints NEVER trust client-provided `tenant_id`, `outlet_id`, `table_id`, prices, or totals. All context is resolved server-side from opaque, high-entropy tokens.
5. **Multi-Tenant Row-Level Security (RLS)**: Tables, bookings, sessions, orders, and order items are strictly tenant-isolated at Layer 1 (Eloquent `TenantScope`) and Layer 2 (PostgreSQL RLS).

---

## Decisions

### 1. Domain Schema & Architectural Boundaries

Five normalized database entities represent the operational dine-in lifecycle:

1. **`tables`**:
   - `id UUIDv7`, `tenant_id`, `outlet_id`, `code`, `name`, `capacity`, `status`, `qr_token`, `qr_token_generated_at`, `is_active`, `metadata`.
   - Unique constraint: `(tenant_id, outlet_id, code)`.
   - Table states: `available`, `occupied`, `reserved`, `inactive`.
2. **`bookings`**:
   - `id UUIDv7`, `tenant_id`, `outlet_id`, `table_id (nullable)`, `customer_id (nullable)`, `booking_code`, `customer_name_snapshot`, `customer_contact_snapshot`, `party_size`, `starts_at`, `ends_at`, `status`, `notes`.
   - Booking states: `pending`, `confirmed`, `checked_in`, `cancelled`, `completed`, `no_show`.
3. **`table_sessions`**:
   - `id UUIDv7`, `tenant_id`, `outlet_id`, `table_id`, `booking_id (nullable)`, `session_code`, `status` (`active`, `closed`), `opened_at`, `closed_at`, `guest_count`, `opened_by_user_id`.
   - Encapsulates physical occupancy distinct from `Sale.status`.
4. **`orders`**:
   - `id UUIDv7`, `tenant_id`, `outlet_id`, `table_id`, `table_session_id`, `customer_id (nullable)`, `sale_id (nullable)`, `order_number`, `idempotency_key`, `source` (`qr_customer`, `pos_waiter`), `status` (`draft`, `submitted`, `accepted`, `preparing`, `ready`, `served`, `cancelled`, `converted`), `subtotal_minor`, `tax_minor`, `discount_minor`, `total_minor`, `customer_name_snapshot`, `customer_contact_snapshot`, `customer_notes`, `accepted_by_user_id`, `accepted_at`, `metadata`.
5. **`order_items`**:
   - `id UUIDv7`, `tenant_id`, `order_id`, `product_variant_id`, `product_name_snapshot`, `variant_name_snapshot`, `quantity`, `unit_price_minor`, `subtotal_minor`, `notes`, `selected_modifiers`.

---

### 2. Table & Booking State Machines

#### Table State Transitions:
```text
available ──(Booking Confirmed)──> reserved ──(Customer Check-In)──> occupied ──(Table Release)──> available
     │                                                                   ↑
     └──────────────────────────(Walk-in Walk-Up)────────────────────────┘
```

#### Booking State Transitions:
```text
pending ──> confirmed ──> checked_in ──> completed
   │            │              │
   ▼            ▼              ▼
cancelled    no_show       cancelled
```

- Arbitrary or backward status jumps are strictly prohibited and validated in `BookingService`.
- Check-in transitions the booking to `checked_in`, immediately creates an active `table_session`, and shifts the table status to `occupied`.
- Booking cancellation or no-show restores the table status to `available` if reserved, without mutating historical sales or accounting journals.

---

### 3. Booking Overlap & Concurrency Protection

To prevent double-booking race conditions:
1. Overlap criterion between requested interval $[S_{req}, E_{req})$ and existing active bookings $[S_{exist}, E_{exist})$:
   $$\text{starts\_at} < E_{req} \quad \text{AND} \quad \text{ends\_at} > S_{req}$$
2. Transactional locking: `BookingService::createBooking` wraps creation in a database transaction with a pessimistic row lock on the target `Table` (`Table::lockForUpdate()->find(...)`).
3. Overlap rejection: Throws `DomainException` with standardized code `BOOKING_OVERLAP` returning HTTP 422. Contiguous (adjacent) bookings ($E_A = S_B$) are explicitly permitted.

---

### 4. Table Session & POS Separation

`Sale.status` is NOT used to model table occupancy.
- One active `table_session` exists per occupied table.
- A table session can aggregate multiple operational customer orders ($O_1, O_2, \dots$) submitted during the dine-in visit.
- Converting an order or finalizing checkout transitions the order to `converted`, links it to `sale_id`, and releases the table session back to `available` upon table checkout/release.

---

### 5. Secure Public QR Ordering & Token Hashing Architecture

#### One-Way Token Hashing & Zero-Plaintext Persistence:
- **Raw Bearer Credential**: The client receives a high-entropy 64-character hexadecimal raw token (`bin2hex(random_bytes(32))`), which is never persisted as database authority.
- **Persistent Authority**: Database stores solely the cryptographic hash: `tables.qr_token_hash = sha256(raw_token)` under a unique index `tables_qr_token_hash_unique`.
- **Transient Memory Lifecycle**: Raw tokens are held only in memory during initial table creation or rotation to return the QR URL to the manager client. Model attribute `qr_token_hash` is explicitly registered in `$hidden` to prevent accidental serialization into API responses or debug dumps.
- **Redacted Audit Trails**: `AuditLog::sanitizePayload` recursively strips all credentials, ensuring raw bearer tokens and token hashes are never captured in audit history.
- **Rotation & Revocation**:
  - Rotation (`POST /api/v1/tables/{table}/rotate-qr`): Atomically updates `qr_token_hash` with the new hash. The previous token is invalidated immediately ($O(1)$ rejection).
  - Revocation (`POST /api/v1/tables/{table}/revoke-qr`): Clears `qr_token_hash = null`, cutting off public access across show, menu, and order submission.

#### Public API Contracts & Strict Redaction:
- Canonical public endpoints:
  - `GET /order/{token}`: Serves mobile-first customer ordering Blade page.
  - `GET /api/v1/public/tables/{token}`: Returns safe public table and outlet metadata (`{ table: { code, name, capacity, status }, outlet: { name } }`). Completely redacts `tenant_id`, `outlet_id`, `table_id`, `qr_token_hash`, and internal UUIDs.
  - `GET /api/v1/public/tables/{token}/menu`: Returns active products and modifier groups available at the table's specific outlet.
  - `POST /api/v1/public/tables/{token}/orders`: Submits an operational order and returns a high-entropy, cryptographically random `tracking_token` along with `order_number`.
  - `GET /api/v1/public/tables/{token}/orders/{trackingToken}`: Live status tracking scoped strictly to the customer's specific order using cryptographic SHA-256 hash lookup (`orders.public_tracking_token_hash = sha256(trackingToken)`). The guessable `order_number` cannot be used as an authorization tracking credential, preventing IDOR. Normalized customer-safe status labels (`Diterima`, `Sedang Disiapkan`, `Siap Disajikan`, `Selesai`, `Dibatalkan`) are returned while redacting all internal UUIDs, tenant IDs, and financial records.

#### Database-Level Invariants & Concurrency Enforcement:
- **Table Session Invariant**: PostgreSQL partial unique index `uq_table_sessions_active_table ON table_sessions (table_id) WHERE status = 'active'` strictly guarantees that a physical table can never have more than one active session simultaneously, even in the event of an application-level lock bypass. Application layer catches SQLSTATE 23505 and gracefully returns the single active session. Historical closed sessions (`status = 'completed'`) remain unblocked.
- **Notification Deduplication Guarantee**: PostgreSQL partial unique index `uq_notifications_tenant_dedup ON notifications (tenant_id, dedup_key) WHERE dedup_key IS NOT NULL AND resolved_at IS NULL` strictly prevents race-condition duplicate inserts across concurrent events. Event deduplication keys (`order_received_{order_id}`, `booking_created_{booking_id}`) contain authoritative entity UUIDs, ensuring that even under concurrent webhook or submission replays, exactly one notification row is committed.

#### Rate Limiting & Anti-Abuse (Automated HTTP Route Verified):
- Public endpoints are protected via isolated IP-based rate limiting buckets:
  - Table discovery, menu reads, and order tracking: `throttle:60,1,public_table_read` (60 requests per minute per IP; 61st yields HTTP 429).
  - Public order submissions: `throttle:30,1,public_table_order` (30 requests per minute per IP; 31st yields HTTP 429).
  - Rate limited responses (HTTP 429) strictly omit stack traces, tenant IDs, table IDs, and internal database details, returning standardized `Retry-After` headers.
- **Bucket Separation Proof**:
  - The middleware parameter prefix (`public_table_read` vs `public_table_order`) causes `ThrottleRequests::resolveRequestSignature` to construct isolated cache keys: `sha1('public_table_read|' . host . '|' . ip)` vs `sha1('public_table_order|' . host . '|' . ip)`.
  - Intensive menu browsing or polling (up to 60 req/min) does NOT deplete or exhaust the 30 req/min order submission capacity.
- **NAT / Shared Wi-Fi Evaluation & Trade-off**:
  - In dining establishments, multiple patrons frequently share a single public IPv4 NAT gateway via the restaurant's guest Wi-Fi.
  - With a 30 orders/minute bucket per IP, an outlet can process up to 1 order submission every 2 seconds across all devices sharing that Wi-Fi.
  - Scoping rate limits solely by IP prevents malicious bot floods and DoS on public submission endpoints.
  - Trade-off decision: For V1, the 30 orders/minute bucket is accepted because typical QR ordering concurrency per Wi-Fi NAT rarely exceeds 30 distinct orders in a single 60-second window. In high-density food courts (V2), we can introduce compound signatures (`sha1(ip . '|' . table_token)`) or CAPTCHA/Turnstile bypass if shared-NAT saturation is observed in production.

#### Customer-Facing Web Surface & Security Headers:
- The customer ordering interface is served directly via a mobile-first, responsive Blade web application (`/order/{token}`) utilizing vanilla CSS and vanilla JS (zero external heavy dependencies).
- Supported UI states: loading skeletons, empty menu, unavailable products, inactive/invalid QR banners, floating cart drawer, idempotent order submission with client-side UUID, and live order tracking stepper with 5-second polling that halts ONLY upon true customer-facing terminal states (`served`, `cancelled`).
- Security Headers Enforced:
  - `Referrer-Policy: no-referrer`
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: DENY`
  - `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
  - `Content-Security-Policy: default-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'`
- Frontend browser memory stores only the opaque token from the URL and active tracking state in `sessionStorage` (`active_order_${QR_TOKEN}`); raw tokens and tracking hashes are never written to audit logs or database columns in plaintext.

#### Multi-Tenant Context Resolution:
- Public endpoints execute via `resolveTableByToken` which safely queries `tables` by `qr_token_hash` across RLS boundaries without exposing tenant headers. Once the tenant is identified, all subsequent queries execute inside `tenantManager->runForTenant((string) $table->tenant_id, ...)`.


---

### 6. Server-Authoritative Price Calculation

Customers and public QR clients are untrusted:
- Request payload sends only `product_variant_id`, `quantity`, and selected modifiers.
- The server retrieves the canonical catalog price using `ProductVariant::resolvePrice($outletId)` and modifier deltas.
- Client-submitted `unit_price_minor`, `subtotal_minor`, or `total_minor` are strictly discarded and overwritten by server-side calculations.

---

### 7. Order Submission Idempotency & Dedicated Encrypted Tracking Replay Architecture

- `POST /api/v1/public/tables/{token}/orders` supports the standard `Idempotency-Key` header and payload parameter.
- **Problem**: Raw tracking tokens must not be stored in plaintext database columns or application logs, yet an idempotent retry of a dropped network request must return the exact same tracking credential so the customer can track their order. Generic `metadata` should not store sensitive credentials.
- **Architectural Solution (Dedicated Ciphertext Column Replay)**:
  - On initial order creation, `OrderService` encrypts the raw tracking token using Laravel's application key via AES-256-CBC (`Crypt::encryptString($rawTrackingToken)`).
  - The ciphertext is stored in a dedicated, nullable database column: `orders.public_tracking_token_ciphertext` (introduced via migration `2026_10_07_000005`).
  - Model attribute `public_tracking_token_ciphertext` is explicitly registered in `Order::$hidden` alongside `public_tracking_token_hash`. This strictly prevents accidental serialization into API responses, JSON dumps, or `AuditLog` records.
  - Generic `orders.metadata` is strictly preserved for business metadata (e.g. `payload_hash`) and cleansed of credential storage.
  - When a client replays the same `Idempotency-Key` with an identical payload hash:
    1. `OrderService` matches the existing order.
    2. Decrypts `public_tracking_token_ciphertext` back into transient runtime property `$existingOrder->raw_tracking_token`.
    3. Returns the deterministic response containing the exact same `order_number` and `tracking_token`.
  - Replaying the same key with an altered payload immediately fails with HTTP 422 / 409 `IDEMPOTENCY_CONFLICT`.
  - Customer can immediately use this replayed tracking token to check status at `GET /api/v1/public/tables/{token}/orders/{trackingToken}`. Attempting status checks via `order_number` is strictly rejected with HTTP 404 (IDOR prevention).

#### APP KEY & Encryption Key Policy:
1. **Framework Encryption Standard**: Utilizes Laravel's native `Illuminate\Support\Facades\Crypt` abstraction backed by AES-256-CBC with HMAC-SHA256 authenticated encryption. No custom cryptography is introduced.
2. **Key Authority**: The encryption key is derived solely from the environment variable `APP_KEY`. The raw encryption key is NEVER persisted in database tables, config cache files committed to source control, or application logs.
3. **Decryption Failure Semantics**:
   - If `Crypt::decryptString` fails due to corrupted ciphertext or missing replay credential:
     - `OrderService` catches `DecryptException` or empty ciphertext.
     - An internal warning is logged with only non-sensitive contextual metadata (`order_id`, `tenant_id`). The raw key, ciphertext, and customer payloads are NEVER logged.
     - The service throws `IdempotencyReplayUnavailableException`, returning HTTP 409 Conflict with code `IDEMPOTENCY_REPLAY_UNAVAILABLE`.
     - NO duplicate order is created, NO new tracking token is generated, and zero cryptographic secrets or stack traces are leaked to the client.
4. **Key Rotation Policy (Framework Previous Keys Standard)**:
   - Laravel natively supports `APP_PREVIOUS_KEYS` (comma-separated list of previous encryption keys in `.env`).
   - Standard Key Rotation Procedure:
     a) Generate new 32-byte key: `php artisan key:generate --show`.
     b) Set `APP_PREVIOUS_KEYS` to include the current `APP_KEY`.
     c) Set `APP_KEY` to the newly generated key.
     d) Laravel's Encrypter decrypts existing `public_tracking_token_ciphertext` using previous keys fallback while encrypting new records with the primary key.
     e) In-flight customer tracking and idempotent retries continue without disruption.
     f) After the idempotency retention window (24 hours), deprecated keys can be safely retired.

---

### 8. Order Lifecycle & Public Status Projection across POS Conversion

1. **Internal Domain Separation vs Public Projection**:
   - Internal Order domain lifecycle: `submitted` → `accepted` → `preparing` → `ready` → `served` → `converted` (or `cancelled`).
   - When orders are converted to a Sale (`OrderService::convertOrdersToSale`), the billing authority shifts to `Sale`, but kitchen/dining preparation may still be ongoing.
   - The public tracking endpoint (`GET /api/v1/public/tables/{token}/orders/{trackingToken}`) projects authoritative customer status:
     - `submitted` / `accepted`: Public status `received` (Label: `Diterima`)
     - `preparing`: Public status `preparing` (Label: `Sedang Disiapkan`)
     - `ready`: Public status `ready` (Label: `Siap Disajikan`)
     - `served` (or finalized Sale): Public status `served` (Label: `Selesai`, Terminal)
     - `cancelled` (or cancelled Sale): Public status `cancelled` (Label: `Dibatalkan`, Terminal)
     - `converted` with open Sale: Public status `received` (or current kitchen stage), maintaining continuous customer polling until the physical order is served or finalized.
2. **Single Inventory Deduction**:
   - Order submission does NOT decrement inventory or reserve stock.
   - When the converted `Sale` is finalized via `PosService::finalizeSale`, inventory consumption is executed exactly once via `InventoryService` (supporting nested recipes/BOMs).
3. **Double-Entry Finance Posting**:
   - Double-entry journal vouchers are posted exclusively during `PaymentService` settlement and `PosService` finalization into `FinanceService`. Order creation or cancellation never creates journal entries.
4. **Receipt Generation**:
   - Customer receipts are rendered strictly from the finalized `Sale` via `ReceiptService`.

---

### 9. Subscription Entitlement Enforcement

Feature gates are enforced via `SubscriptionEntitlementService`:
- Feature keys: `booking` and `qr_table_order`.
- Authenticated table and booking endpoints verify `can('booking')` and `can('qr_table_order')`.
- Public QR endpoints verify the owning tenant's entitlement prior to serving the menu or accepting orders; tenants without the required entitlement receive HTTP 403 `FORBIDDEN`.

---

### 10. Notifications & Audit Logging

- **Notifications**:
  - `booking_created`: Dispatched to outlet staff upon booking confirmation.
  - `order_received`: Dispatched to POS and kitchen terminals upon customer QR order submission.
  - Both notifications are idempotent and tenant-scoped with deduplication keys.
- **Audit Trails**:
  - Emits structured domain audit logs for: `booking_created`, `booking_confirmed`, `booking_checked_in`, `booking_cancelled`, `table_status_changed`, `table_qr_rotated`, `table_qr_revoked`, `order_submitted`, `order_accepted`, `order_converted`.
  - Sensitive tokens, credentials, and customer personal credentials are never logged.

---

## Consequences

### Positive:
- Seamless operational dine-in and QR ordering flow without duplicating POS transaction cores.
- Zero risk of financial discrepancy or double inventory deduction.
- Total defense-in-depth against client tampering, IDOR, and cross-tenant leakage.
- Deterministic idempotency retry: customer retains tracking credential across network timeouts without plaintext storage.
- Clean database migrations with automated fresh and rollback verification leaving zero residual schema objects.
- Full compatibility with existing offline POS, shift management, and double-entry finance.

### Negative / Trade-offs:
- Public QR ordering requires network connectivity (customer web client is online-only).
- High table concurrency relies on PostgreSQL row locks on the `tables` entity during booking.
- Shared guest Wi-Fi NAT: 30 orders/minute per IP limits total submissions across all patrons sharing one router, requiring compound keying in V2 if high-density single-IP saturation occurs.
