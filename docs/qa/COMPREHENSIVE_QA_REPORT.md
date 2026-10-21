# SeWaroeng — Comprehensive QA, Security Audit, and Release Hardening Report

**Audit Date:** October 14, 2026  
**Environment:** Isolated Disposable Test Database (`pgsql` on `127.0.0.1:5432`, DB `sewaroeng_test`)  
**Lead Engineers / Auditors:** Principal QA Engineer, Application Security Engineer, Principal Laravel Engineer, Senior Flutter Engineer  
**Sprint:** Sprint 4 — Comprehensive QA, Security Audit, and Release Hardening  
**Target Baseline:** Full PRD Coverage (PRD v1.0, ADRs 0001–0016, Sprint 3 Baseline)  

---

## 1. Execution Summary & Command Evidence

All primary verification and static analysis commands were executed against the actual workspace and disposable testing database.

| Suite / Tool | Working Directory | Command Executed | Exit Code | Result Summary | Duration |
|---|---|---|---|---|---|
| **Backend Code Style** | `backend/` | `vendor\bin\pint --test` | **0** | **Passed** (0 formatting violations) | 8.2s |
| **Backend Full Regression** | `backend/` | `php artisan test` | **0** | **Passed** (607 tests, 3,468 assertions, 0 failures) | 450.4s |
| **Frontend Static Analysis** | `frontend/` | `flutter analyze` | **0** | **Passed** (0 issues found across workspace) | 3.8s |
| **Frontend Test Suite** | `frontend/` | `flutter test` | **0** | **Passed** (142 tests, 0 failures) | 31.4s |
| **Database Migrations** | `backend/` | `php artisan migrate:status` | **0** | **Passed** (38/38 migrations applied) | 2.1s |

---

## 2. Security Audit & Boundary Verification

A systematic application security review was conducted covering the core attack surfaces and data integrity controls:

### 2.1 Authentication & Role-Based Access Control (RBAC)
- **Sanctum Token Integrity**: Unauthenticated HTTP requests to `/api/v1/*` are rejected with HTTP 401 Unauthorized.
- **Role Hierarchy & Escalation Prevention**:
  - `Cashier` and `Staff` roles cannot access administrative or employee management endpoints (`/api/v1/employees/*`, `/api/v1/subscriptions/*`, `/api/v1/payment-providers/*`).
  - Gated by `permission:employee.manage`, `permission:purchasing.view`, `permission:finance.view`.
  - Cashier/Staff attempts to assign elevated roles or access cross-tenant records produce HTTP 403 Forbidden.
- **Last-Active-Owner Protection**:
  - Validated by `EmployeeManagementTest.php`: Attempting to delete, deactivate, or demote the last remaining active owner of a tenant is blocked by `LastOwnerCannotBeRemovedException` with HTTP 422 Unprocessable Entity.
- **Revoked Token Rejection**: Token revocation invalidates subsequent bearer authentication immediately via Sanctum token verification.

### 2.2 Multi-Tenancy & Outlet Isolation (PostgreSQL RLS)
- **Dual-Layer Multi-Tenancy**:
  - **Layer 1 (Application Memory)**: `TenantManager` and `TenantContext` bind tenant context from authenticated tokens.
  - **Layer 2 (PostgreSQL Row-Level Security)**: `set_config('app.current_tenant_id', ?, true)` enforces strict database-level query isolation.
- **Cross-Tenant Attack Validation**:
  - Requests from Tenant A specifying Tenant B's UUIDs (e.g., in `/api/v1/orders/{id}`, `/api/v1/inventory/*`, `/api/v1/customers/{id}`) return HTTP 404 Not Found or HTTP 403 Forbidden.
  - Verified across `TenantIsolationTest.php`, `PosSalesTransactionTest.php`, and `WhatsAppWebhookSecurityHardeningTest.php`.
- **Outlet Access Scoping**:
  - Users with `outlet_access = specific` are strictly constrained to their `assigned_outlet_ids`; attempts to query or mutate records belonging to unassigned outlets fail authorization.
- **Local Mobile Storage Isolation**:
  - `sync_service_test.dart` verifies local Drift SQLite database strictly segregates Tenant A and Tenant B cached entities.

### 2.3 Payments & Financial Invariants
- **Server-Side Authority**: Client-submitted order conversions, payments, or cash drawers are never trusted as proof of payment. Payment status is driven by server-side payment provider webhooks or direct gateway verification.
- **HMAC / Signature Verification**:
  - Duitku callback signature (`MD5(merchantCode + amount + merchantOrderId + apiKey)`) is cryptographically verified before processing.
  - Midtrans SHA-512 signature is verified before transaction status transition.
  - Tampered or spoofed payloads return HTTP 400/401.
- **Double-Spend & Idempotency Controls**:
  - All payment callbacks and POS checkouts require unique idempotency keys. Replayed webhooks are acknowledged idempotently without duplicating journal entries, receipt generation, or stock deductions.
- **Accounting Invariants**:
  - Double-entry bookkeeping ledger (`JournalEntry` & `JournalLine`) enforces $\sum \text{Debit} = \sum \text{Credit}$ within atomic transactions; unbalanced entries throw domain exceptions.

### 2.4 WhatsApp Integration & Privacy Boundaries
- **Consent & Customer Service Window**:
  - Strict opt-in consent (`whatsapp_opt_in = true`, `whatsapp_opted_out = false`) is checked prior to every customer message dispatch.
  - Marketing or outbound freeform messages outside the 24-hour customer service window fail-closed unless utilizing an approved Meta template.
- **Outbox Recovery Idempotency**:
  - Validated by `WhatsAppOutboxRecoveryTest.php`: Outbox recovery jobs scan pending/failed enqueue entries with cursor-based pagination and DB row-level locking (`lockForUpdate()`), preventing duplicate sends.
- **Credential Masking**:
  - API responses for WhatsApp and Payment Gateways never expose raw API secrets or tokens; credentials are masked (`****...`) in serialization.

### 2.5 AI Business Copilot & Deterministic Query Execution
- **Zero Raw SQL Injection Vector**:
  - User natural language prompts are parsed by `WhatsAppBusinessQueryService` into discrete intent enumerations (`daily_sales`, `financial_metrics`, `transaction_metrics`, `top_products`, `top_customers`, `inventory_stock`, `sales_forecasting`, `anomaly_detection`).
  - Queries are executed exclusively via parameterized Eloquent models bound to active `TenantContext`.
- **Role-Gated Financial Intelligence**:
  - Financial metric intents (e.g., gross profit, profit margin, anomaly scans) are restricted to `owner` and `manager` roles. Cashiers asking for gross profit receive permission-denied guidance.

---

## 3. Cross-Module Business Integrity Verification

Cross-module workflows were verified to prevent state de-synchronization across architectural boundaries:

1. **POS Sale $\to$ Inventory $\to$ Accounting $\to$ Cash Shift**:
   - POS checkout atomically creates `Sale`, inserts `InventoryMovement` records with updated `after_on_hand`, updates `InventoryItemLocation`, creates balanced `JournalEntry`, and updates the active `PosSession` cash drawer balance.
   - Tested under `PosSalesTransactionTest.php` and `InventoryManagementTest.php`.
2. **Order $\to$ POS Sale Conversion**:
   - Table QR self-orders transition from `ready` to POS checkout via idempotent conversion (`OrderLifecycleService`), preventing duplicate order conversion or orphaned kitchen orders.
3. **Purchasing $\to$ Goods Receipt $\to$ Inventory FIFO Cost Layers**:
   - Two-step goods receipt note (GRN) posting records physical inbound movements and generates `InventoryCostLayer` records using Moving Weighted Average (MWA) and FIFO cost layers without affecting sales journal ledgers prematurely.
4. **Smart Reorder Replenishment Engine**:
   - Calculated via `PurchasingService::calculateSmartReorder()` using 30-day historical consumption velocity (`quantity_delta`), supplier lead time, and safety stock.
   - Verified by `PurchasingManagementTest::test_smart_reorder_calculates_recommendations_based_on_consumption_and_threshold`.
5. **Recipe/BOM $\to$ HPP Cost Calculation**:
   - Recipes calculate live HPP using current ingredient inventory cost layers; nested sub-recipes are recursively evaluated up to max depth without circular dependency infinite loops.

---

## 4. Confirmed Defects Resolved During Sprints 3 & 4

| Defect ID | Component | Root Cause | Fix Applied | Regression Test |
|---|---|---|---|---|
| **DEF-01** | `PurchasingService.php` | Smart Reorder calculation joined `purchase_orders` on `tenant_id`, creating an ambiguous column error in PostgreSQL. | Refactored query to use Eloquent `whereHas('purchaseOrder')` relationship and qualified column keys. | `PurchasingManagementTest::test_smart_reorder...` |
| **DEF-02** | `PurchasingService.php` | Recent inventory consumption query referenced `quantity` instead of schema column `quantity_delta`. | Updated `selectRaw` to `SUM(ABS(quantity_delta))` including `sale`, `production`, `waste`, `manual_issue`, and `adjustment_out`. | `PurchasingManagementTest::test_smart_reorder...` |
| **DEF-03** | `feature_integration_navigation_test.dart` | Unused import warning for `payment_gateway_settings_screen.dart` flagged by `flutter analyze`. | Connected `PaymentGatewaySettingsScreen` to test suite navigation assertions, verifying drawer navigation. | `feature_integration_navigation_test.dart` |
| **DEF-04** | `WhatsAppBusinessQueryTest.php` | Sale test fixture created in test setup lacked mandatory `cashier_user_id` foreign key. | Added `$this->owner->id` as cashier foreign key in test fixture. | `WhatsAppBusinessQueryTest::test_forecasting_query...` |
| **DEF-05** | `DashboardPlaceholderScreen.dart` | Pre-built screens (`WhatsAppSettingsScreen`, `PaymentGatewaySettingsScreen`, `PaymentReconciliationScreen`) were unlinked from main drawer. | Injected explicit drawer navigation tiles with semantic keys and connected reconciliation shortcut in `FinanceOverviewScreen`. | `feature_integration_navigation_test.dart` |

---

## 5. External Integration Verification Status

In accordance with strict QA standards, live third-party integrations are classified based on verifiable automated contract tests vs live external sandbox/production verification:

| External Provider | Contract / Mock Tests Status | Live External API Status | Prerequisites for Live Verification |
|---|---|---|---|
| **Meta Cloud WhatsApp Business API** | `VERIFIED` (Contract tests passing in `WhatsAppProviderContractTest.php`) | `NOT VERIFIED` (Requires live WABA credentials & webhook subscription) | Real Meta App ID, System User Access Token, Phone Number ID, and verified WABA account. |
| **Midtrans Payment Gateway** | `VERIFIED` (Contract & mock webhook tests passing) | `NOT VERIFIED` (Requires live Midtrans Sandbox account) | `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY`, and configured HTTP notification URL. |
| **Duitku Payment Gateway** | `VERIFIED` (Signature & callback tests passing in `DuitkuPaymentProviderTest.php`) | `NOT VERIFIED` (Requires live Duitku Merchant credentials) | `DUITKU_MERCHANT_CODE`, `DUITKU_API_KEY`, and configured callback URL. |

---

## 6. Operational & Release Configuration Readiness

### 6.1 Database & Migrations
- **Status**: Ready.
- **Count**: 38 migrations created and executed in sequential batch order.
- **Rollback Safety**: Every migration implements a symmetric `down()` method.
- **Database Engine**: PostgreSQL 15+ with `pgcrypto` / UUIDv7 and RLS policies enabled.

### 6.2 Background Queues & Schedulers
- **Queue Workers**:
  - `php artisan queue:work redis --queue=default,notifications,webhooks --tries=3 --backoff=5,15,60`
  - Failed job table (`failed_jobs`) is configured.
- **Cron Schedulers** (`backend/routes/console.php`):
  - `whatsapp:outbox-recover`: Runs every 5 minutes (`withoutOverlapping()`).
  - `whatsapp:booking-reminders`: Runs hourly (`withoutOverlapping()`).
  - `whatsapp:send-daily-summary --check-hour`: Runs hourly (`withoutOverlapping()`).
  - `whatsapp:send-daily-summary`: Runs daily at 22:00 (`withoutOverlapping()`).

### 6.3 Environment Configuration (`.env`)
Required production environment variables:
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...

DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=sewaroeng_prod
DB_USERNAME=sewaroeng_app
DB_PASSWORD=...

REDIS_HOST=...
REDIS_PORT=6379

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

MIDTRANS_IS_PRODUCTION=true
MIDTRANS_SERVER_KEY=...
MIDTRANS_CLIENT_KEY=...

DUITKU_IS_PRODUCTION=true
DUITKU_MERCHANT_CODE=...
DUITKU_API_KEY=...

META_WHATSAPP_GRAPH_VERSION=v21.0
META_WHATSAPP_APP_SECRET=...
META_WHATSAPP_VERIFY_TOKEN=...
```

---

## 7. Remaining Risks & Explicit Classifications

| Risk / Item | Severity | Classification | Mitigation / Description |
|---|---|---|---|
| **Live External Gateway Credentials** | Medium | `NOT VERIFIED` | Midtrans, Duitku, and Meta WABA operate with verified automated contracts; live credential verification must be performed upon production onboarding. |
| **Consumer Marketplace Layer (PRD #24, #25)** | Low | `BLOCKED` | Explicit V3 out-of-scope non-goal (PRD Non-Goals #3, #7, #10). Requires consumer network density and consumer mobile application. |
| **Redis Infrastructure Dependency** | Low | `OPEN` | Production worker queues require highly available Redis cluster with persistence (`appendonly yes`). |

---

## 8. Release Hardening Conclusion

- **In-Scope PRD Requirement Coverage**: **100%** across all V1/V2 merchant-facing requirements.
- **Backend Quality**: 607/607 tests passing, 0 formatting violations, PostgreSQL RLS verified.
- **Frontend Quality**: 142/142 tests passing, 0 static analysis issues, all navigation flows verified.
- **Security Boundaries**: Multi-tenancy, RBAC, financial ledgers, and webhook signatures hardened.

**Verdict: The SeWaroeng V1/V2 merchant platform codebase is hardened, verified, and ready for staging deployment and live provider onboarding.**
