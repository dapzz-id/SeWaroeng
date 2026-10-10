# SeWaroeng Architecture Security Checklist

## Document Information
* **Document:** Architecture Security Checklist & Threat Mitigation Verification
* **Scope:** Architectural foundation (Tenancy, Identifiers, Auth, Offline Sync, Payments, APIs)
* **Target Audience:** Backend Engineers, Mobile Engineers, QA, DevOps, Security Reviewers

---

## 1. Threat Matrix & Architecture Verification Gates

| # | Threat Vector | Risk Description | Architectural Mitigation | Verification / Test Requirement |
| :- | :--- | :--- | :--- | :--- |
| **SEC-01** | **Cross-Tenant Data Access** | Tenant A queries or mutates records belonging to Tenant B by manipulating URL IDs, headers, or payloads. | Dual-layer isolation: Eloquent `TenantScope` (Layer 1) + PostgreSQL native Row-Level Security `USING (tenant_id = current_setting('app.current_tenant_id'))` (Layer 2). Client-provided `tenant_id` is never trusted. | Automated test suite in `TenantIsolationTest`: asserts 404/403 when User A accesses Tenant B UUID; raw SQL test confirms 0 rows returned if `app.current_tenant_id` does not match. |
| **SEC-02** | **Horizontal Privilege Escalation** | Cashier in Outlet 1 accesses or cancels orders in Outlet 2 within the same tenant. | Laravel Policies enforce outlet authorization (`OrderPolicy::update`). Cashier membership restricts `accessible_outlet_ids`. | Automated test: Cashier assigned to Outlet 1 sends `POST /api/v1/orders/{outlet_2_order}/void` -> Assert HTTP 403 `OUTLET_ACCESS_DENIED`. |
| **SEC-03** | **Vertical Privilege Escalation** | Cashier elevates permissions to Owner or performs Owner-only operations (e.g., viewing profit margins, changing staff roles). | RBAC enforced via server-side Policies; roles stored in PostgreSQL `user_tenant_memberships`, never client claims. In-app PIN switching requires server validation. | Automated test: Cashier token sends `GET /api/v1/finance/profit-loss` -> Assert HTTP 403 `UNAUTHORIZED_ROLE`. |
| **SEC-04** | **Token Theft & Session Hijacking** | Bearer token intercepted via network sniffing, malware, or log exposure. | Sanctum tokens stored as SHA-256 hashes in DB. Device metadata (`device_name`, `device_id`, IP) tracked per session. TLS 1.3 mandatory. Merchant Owner can revoke single device remotely. | Automated test: Owner invokes `DELETE /api/v1/devices/{id}` -> Target device's subsequent request immediately receives HTTP 401 `TOKEN_REVOKED`. |
| **SEC-05** | **Stale Authorization Vulnerability** | Terminated employee retains access via an unexpired client token or offline app cache. | Server resolves role and active membership status on **every request**. Revoking membership in `user_tenant_memberships` takes effect instantly without waiting for token expiration. | Automated test: Mark membership `status = 'suspended'` -> Next request with previously valid token receives HTTP 403 `TENANT_MEMBERSHIP_INACTIVE`. |
| **SEC-06** | **Offline Data Exposure (Lost / Stolen Tablet)** | POS Android tablet stolen from cafe counter; attacker attempts to extract SQLite database. | 1. SQLite encrypted at rest using SQLCipher with Android KeyStore-backed key.<br>2. Local database caches only active catalog and shift data; never stores financial journals, supplier costs, or tenant-wide data.<br>3. Remote wipe flag triggers DB purge on next connection. | Manual verification on Android APK: verify database file at `/data/data/.../databases/sewaroeng.db` cannot be opened by standard SQLite CLI without encryption key. |
| **SEC-07** | **Duplicate Transactions (Offline & Online)** | Cashier clicks "Charge" multiple times or sync worker retries upload over flaky network, creating duplicate sales. | 1. Client-generated UUIDv7 for all entities prevents key recreation.<br>2. Server-enforced `Idempotency-Key` header with 24-hour Redis / DB lock returns cached response for retries. | Automated test: Send identical `Idempotency-Key` and payload twice concurrently -> Verify exactly one order created, second request returns identical cached HTTP 200. |
| **SEC-08** | **Replay Attacks** | Man-in-the-middle replays a captured API request (e.g., payment confirmation or stock adjustment). | 1. Strict TLS 1.3 encryption.<br>2. Mutating requests require `Idempotency-Key` and timestamp within 300 seconds skew.<br>3. Expired or duplicate keys are rejected immediately. | Automated test: Send captured payload with timestamp older than 5 minutes -> Assert HTTP 400 `REQUEST_EXPIRED`. |
| **SEC-09** | **Webhook Replay & Forgery (Payment Gateway)** | Attacker sends fake payment success webhooks to mark orders as `paid` without paying. | 1. HMAC-SHA512 webhook signature verification against gateway secret.<br>2. Webhook event ID deduplication in `payment_webhooks` ledger.<br>3. Server queries payment provider directly (out-of-band verification) before updating order status. | Automated test: Send webhook with invalid signature -> Assert HTTP 401 `INVALID_SIGNATURE`. Send duplicate valid webhook -> Assert HTTP 200 with no secondary ledger mutation. |
| **SEC-10** | **Race Conditions (Concurrent Stock & Shift Mutations)** | Multiple cashiers sell the last available inventory item simultaneously. | 1. Inventory mutations are append-only rows in `inventory_movements` within `DB::transaction`.<br>2. Critical stock reservations use pessimistic locking (`SELECT ... FOR UPDATE`).<br>3. Cash drawer shifts enforce atomic transitions. | Concurrency test: 5 concurrent workers attempt to buy the last 1 item -> Exactly 1 succeeds, 4 fail with `INSUFFICIENT_STOCK` (or trigger deficit workflow if offline). |
| **SEC-11** | **Unauthorized Outlet / Warehouse Access** | User crafts an inventory transfer targeting a warehouse in another outlet or tenant. | Transfer policies verify both source and destination `outlet_id`/`warehouse_id` belong to the authenticated `tenant_id` and that user has transfer permissions for both nodes. | Automated test: Request transfer from Tenant A warehouse to Tenant B warehouse -> Assert HTTP 404/403. |

---

## 2. Mandatory Coding Rules for Developers

1. **Never use `DB::table()` on tenant tables without explicit tenant scoping:**
   * Always prefer Eloquent models with `BelongsToTenant` trait.
   * If a raw query is required for performance, include `where('tenant_id', app(TenantContext::class)->id())` explicitly and pass PHPStan review.

2. **Never expose internal database auto-increments or predictable IDs:**
   * All public APIs consume and return UUIDv7 strings.

3. **Never trust client-calculated money:**
   * Frontend sends line items, quantities, and chosen option IDs.
   * Laravel calculates subtotal, discounts, taxes, and final total authoritatively.

4. **Never update financial or inventory balances in-place:**
   * Stock must be changed exclusively via an `InventoryMovement` entry.
   * Cash balances must be changed exclusively via a `PaymentLedger` entry.

5. **Log all administrative and security actions:**
   * Voiding an order, manual stock adjustment, user permission change, and shift discrepancy must append an entry to `audit_logs` with `user_id`, `tenant_id`, `ip_address`, `before_state`, and `after_state`.

---

## 3. Pre-Merge Automated Gates

Before any PR merging in Phase 1 and beyond, the CI/CD pipeline must enforce:
* `composer test`: 100% pass on all feature, unit, and tenant isolation tests.
* `phpstan analyse`: Level 8 static analysis with zero baseline errors.
* `flutter test`: 100% pass on all local DB and sync worker tests.
* Migration review check: Every new tenant table includes foreign key to `tenants(id)` and RLS policy call.
