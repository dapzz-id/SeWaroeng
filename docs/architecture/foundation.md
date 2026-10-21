# SeWaroeng Architecture Foundation (Phase 0B–1D Hardened)

This document describes the foundational technical architecture established for the SeWaroeng SaaS platform prior to implementing business domain modules.

---

## 1. Repository Structure

The monorepo is structured cleanly into backend, frontend, scripts, and architectural documentation:

```text
sewarong/
├── .github/
│   └── workflows/
│       └── ci.yml               # Automated CI pipeline for Backend & Frontend
├── backend/                     # Laravel 13.x REST API application
│   ├── app/
│   │   ├── Domain/
│   │   │   └── Tenant/          # TenantContext, TenantManager
│   │   ├── Http/
│   │   │   ├── Middleware/      # Tenant context, outlet access, correlation ID, idempotency
│   │   │   └── Responses/       # Standard ApiResponse envelope
│   │   ├── Models/              # Foundation models (UUIDv7, Auditable, Tenant-scoped)
│   │   ├── Scopes/              # Eloquent TenantScope (application-level isolation)
│   │   └── Traits/              # HasUuidV7, BelongsToTenant
│   ├── config/                  # Sanctum, database, cache, redis
│   ├── database/
│   │   ├── migrations/          # Schema migrations + PostgreSQL RLS policies + Idempotency
│   │   └── seeders/             # Roles & Permissions seeder
│   └── tests/
│       ├── Feature/             # Tenancy, RLS, Auth, RBAC, Outlet, Idempotency tests
│       └── Unit/                # UUIDv7 and AuditLog sanitization tests
├── frontend/                    # Flutter cross-platform mobile/desktop application
│   ├── lib/
│   │   ├── core/
│   │   │   ├── database/        # Drift local SQLite database & tables
│   │   │   ├── device/          # Persistent UUIDv7 device identification service
│   │   │   └── sync/            # Transactional outbox state machine & causal service
│   │   └── main.dart
│   └── test/
│       └── core/                # DeviceService & OutboxService unit tests
├── scripts/
│   ├── setup-db-staging.sql     # Isolated PostgreSQL staging setup script
│   ├── setup-db-dev.sql         # Local dev and test PostgreSQL setup script
│   └── setup-db-production.sql  # Inactive production PostgreSQL setup template
└── docs/
    ├── SeWaroeng_PRD_v1.0.md
    ├── architecture-security-checklist.md
    ├── architecture/
    │   ├── foundation.md
    │   └── phase-0-security-gate.md
    └── adr/
        ├── 0001-tenant-isolation.md
        ├── 0002-identifier-strategy.md
        ├── 0003-authentication-authorization.md
        └── 0004-offline-first-sync.md
```

---

## 2. Core Technologies & Versions

- **Backend Framework**: Laravel 13.34.0 (API-First, no Blade UI)
- **Runtime**: PHP 8.4.17
- **Primary Database**: PostgreSQL 18 with Row-Level Security (RLS) enabled
- **Cache & Message Broker**: Redis (standalone, dedicated connection)
- **Mobile/Client SDK**: Flutter 3.44.8 / Dart 3.12.2
- **Local Client Storage**: SQLite via Drift ORM with code generation

---

## 3. Database Scoping & Table Classifications

To prevent circular dependency deadlocks during authentication while enforcing ironclad multi-tenant isolation, tables are strictly classified:

### 3.1 Platform / Identity Tables (NO RLS)
- **`users`**: Global identity catalog. Required during authentication before any tenant context exists. Contains `is_active` status.
- **`tenants`**: Global tenant catalog. Contains tenant code, business type, owner, and active status.
- **`personal_access_tokens`**: Global Sanctum session tokens with UUIDv7 PK, device identifier, and soft revocation timestamp (`revoked_at`).

### 3.2 Authorization Mapping Tables (NO RLS)
- **`user_tenant_memberships`**: Connects users to tenants. Must be readable by an authenticated user during tenant context resolution before `app.current_tenant_id` is set.
- **`roles`**, **`permissions`**, **`role_permissions`**: System-wide RBAC definitions.
- **`outlet_user_access`**: Connects user memberships to specific permitted outlets.

### 3.3 Tenant-Scoped Entity Tables (RLS ENABLED & FORCED)
- **`outlets`**: Business physical/virtual branches owned by a tenant.
- **`tenant_test_records`**: Test harness entity.
- *(Future Phase 1+: `orders`, `order_items`, `products`, `inventory_batches`, `shifts`, `invoices`, `ledger_entries`, `customers`)*.
- Enforces:
  ```sql
  CREATE POLICY outlets_tenant_isolation_policy ON outlets
      FOR ALL
      USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
      WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
  ```

### 3.4 System / Compliance Tables
- **`audit_logs`**: Dual-scope audit trail. Supports platform-wide actions (`tenant_id = null`, e.g. login failures) and tenant-specific operations. Automatically redacts sensitive fields.
- **`idempotency_records`**: Durable mutation ledger with unique partial indexes on `(tenant_id, idempotency_key)` and `(idempotency_key WHERE tenant_id IS NULL)`.

---

## 4. Tenancy & Row-Level Security (RLS)

### Application-Level Isolation
- Models implementing `BelongsToTenant` apply `TenantScope`, appending `WHERE tenant_id = ?` to all SELECT queries and auto-stamping `tenant_id` on model creation.
- Tenant context is resolved per-request via `ResolveTenantContext` middleware.
- Client passes `X-Tenant-ID` header. The server verifies active membership in `user_tenant_memberships` and checks `user->is_active` and `tenant->is_active`. If missing, mismatched, or unauthorized, it rejects with `403 Forbidden`. The header **never** acts as proof of access.

### Database Defense-in-Depth (PostgreSQL RLS)
- The database connection runs under the non-superuser application role `sewaroeng_app` (`NOSUPERUSER NOBYPASSRLS`).
- **Fail-Closed Behavior**: If `app.current_tenant_id` is empty, missing, or invalid, `nullif(...)` yields `NULL`, resulting in **zero rows returned**.
- **Transaction-Scoped Context**: Session parameters are set using `SELECT set_config('app.current_tenant_id', ?, true)`. The third parameter `is_local = true` ensures the configuration is strictly bound to the active transaction (`SET LOCAL`), automatically resetting when the transaction commits or aborts.
- `TenantManager::runForTenant($tenant, $callback)` abstraction sets the transaction context, executes the callback, and guarantees context clearing in an outer `finally` block even when worker jobs throw unhandled exceptions.

---

## 5. Dual-Layer Idempotency Architecture

Complies with ADR-0004 & Phase 0C specifications:
- Supported via `Idempotency-Key` header on all mutation endpoints.
- **Dual-Layer Architecture**:
  1. **L1 (Redis)**: Distributed lock (`idempotency:lock:...`) preventing concurrent race conditions + high-speed response cache (24h TTL).
  2. **L2 (PostgreSQL `idempotency_records`)**: Durable persistence ensuring no duplicate business mutations occur if Redis crashes, restarts, or evicts keys.
- **Execution Lifecycle**:
  1. Cryptographic request fingerprint: `sha256(method + path + json(body))`.
  2. If record exists with `status = 'completed'`:
     - If fingerprint matches: replays stored response with `X-Idempotent-Replay: true`.
     - If fingerprint differs: rejects with `409 Conflict` (`IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`).
  3. If record exists with `status = 'processing'`: rejects concurrent attempts with `409 Conflict` (`REQUEST_IN_PROGRESS`) and `Retry-After: 2`.
  4. If record does not exist: inserts record in `processing` state and executes downstream handler.
  5. On 2xx/4xx response: updates record to `completed`, stores HTTP status and body, caches in Redis.
  6. On 5xx error or unhandled exception: updates record to `failed`, allowing safe retry by client.

---

## 6. Offline Foundation & Local Storage (Flutter)

- **Local Storage Engine**: Drift + SQLite (`sewaroeng_local.sqlite`).
- **Device Identification**: `DeviceService` generates and stores a persistent application-level UUIDv7 device ID, stable across launches and reset on factory reinstall.
- **Sync Outbox**: `SyncOutboxTable` records queued offline commands with fields: `id`, `command_type`, `aggregate_type`, `aggregate_id`, `payload`, `attempt_count`, `next_attempt_at`, `state`, `last_error`, `causation_id`, `sequence_number`.
- **State Machine Transitions**:
  - `pending` -> Enqueued locally.
  - `uploading` -> Network dispatch in progress.
  - `synced` -> Confirmed 200 OK by backend (Terminal state; invalid transitions like `synced -> uploading` are strictly rejected).
  - `failed` -> Network error; exponential backoff scheduled: `min(2^attempt * 2s, 300s)`.
  - `conflict` -> Server domain validation rejection (requires supervisor resolution).
  - `requires_action` -> Manual cashier/manager override required.
- **Causal Ordering**: Commands with a `causation_id` are held in the queue until the parent command has reached the `synced` state.
- **Security Policy on Local Storage**: Current local storage is strictly limited to device identity and outbox command queue metadata. SQLCipher database encryption is a mandatory gate before persisting business ledger data locally.

---

## 7. Security & Privacy Safeguards

- **Audit Log Redaction**: `AuditLog` automatically sanitizes payloads, replacing keys matching `password`, `pin`, `token`, `secret`, `api_key`, `credit_card`, and `authorization` with `[REDACTED]`.
- **Error Shield**: Centralized exception handling in `bootstrap/app.php` wraps all `api/*` errors in the standard JSON envelope. When `APP_DEBUG=false`, internal stack traces, file paths, and SQL statements are strictly suppressed.
- **Authentication Safeguards**: Inactive users (`is_active=false`) and soft-revoked tokens (`revoked_at IS NOT NULL`) are immediately rejected.
- **Rate Limiting**: Public authentication routes are throttled to 5 attempts per minute per IP.
- **Safe Health Check**: `GET /api/v1/health` returns only safe operational booleans (`{"data": {"status": "ok"}}`) without exposing internal hostnames, credentials, or environment details.

---

## 8. Business Profile & Outlet Architecture (Phase 1A.3)

### 8.1 Business Profile Configuration
- **Entity**: `tenants` table serves as both the security isolation boundary (`tenant_id`) and the user-facing business profile.
- **Business Type**: Extensible `business_type` column supporting initial verticals (`fnb`, `retail`, `service`, `production`, `online_seller`) without coupling business logic to hardcoded database enums.
- **Status Lifecycle**: `active` (operational), `suspended` (operations locked), and `archived` (read-only historical). Transitions are strictly restricted to authorized administrators/owners (`business.update`).
- **Structured Address**: Explicit columns (`address_line`, `city`, `province`, `postal_code`, `country_code`).
- **Merchant Coordinates**: Optional `latitude` (-90 to +90) and `longitude` (-180 to +180). Coordinates represent merchant-owned private operational data, accessible only with `business.view` or `outlet.view`. Consumer discoverability requires separate opt-in contracts in future phases.

### 8.2 Currency & Timezone Lifecycle
- **Currency**: Canonical ISO-4217 uppercase code (default `IDR`). Display strings (`Rp`, `Rupiah`) and floating-point representations are strictly forbidden in transaction logic.
  - *Lifecycle Rule*: Currency changes are permitted during setup but will be locked once downstream financial ledger transactions exist in Phase 1D+.
- **Timezone**: Canonical IANA timezone identifier (default `Asia/Jakarta`).
- **Timezone Hierarchy**:
  ```text
  outlet.timezone (explicit override)
         ↓
  tenant.timezone (business default)
         ↓
  system fallback ("Asia/Jakarta")
  ```
- **Temporal Invariant**: Database columns store timestamps in UTC. The effective IANA timezone governs business reporting boundaries and UI presentation.

### 8.3 Outlet Invariants & Lifecycle
- **Tenant Scope & RLS**: Every outlet belongs to exactly one tenant. An outlet can never transfer tenant ownership (`tenant_id` is immutable).
- **Canonical Outlet Code**: Normalized uppercase string (e.g., `PUSAT`, `CABANG01`). Enforced unique per tenant via unique index `(tenant_id, code)`.
- **Status Lifecycle**:
  - `active`: Fully operational.
  - `inactive`: Retained for audit and reporting history; cannot be selected as an active operational outlet or receive transactions once POS is enabled.
- **Operating Hours**:
  - Foundational structured JSON schema for 7 days (`monday` through `sunday`).
  - Each day defines `closed` (boolean), `open` (`HH:mm` 24-hour format), and `close` (`HH:mm`).
  - Invariant validation: `open <= close`.

### 8.4 Context-Switching Protocol
Client state maintains `activeTenantId` and `activeOutletId` as UI context and cache only. Every request is independently validated server-side.
- **Business Switching Sequence**:
  1. User selects Tenant B.
  2. Client issues request with `X-Tenant-ID: Tenant-B`.
  3. Server validates authenticated user membership and active status.
  4. Client updates `activeTenant` to B and **immediately clears** `activeOutlet` and stored `activeOutletId`.
  5. Client loads outlets for Tenant B and selects a valid, active outlet belonging to Tenant B.
- **Cross-Tenant Constraint**:
  - Storing or sending `Tenant A + Outlet from Tenant B` is structurally rejected.
  - Selecting an inactive outlet as active operational context is rejected.

### 8.5 Authorization & Auditing
- Dedicated granular permissions:
  - `business.view`, `business.update`
  - `outlet.view`, `outlet.create`, `outlet.update`, `outlet.activate`, `outlet.deactivate`
- Audited actions with actor, tenant, entity, request ID, and sanitized before/after state:
  - `business_updated`, `business_status_changed`
  - `outlet_created`, `outlet_updated`, `outlet_status_changed`

### 8.6 Multi-Outlet Scoping & Server Authority Invariants
- **Role to Outlet Mapping**:
  - `owner`: Tenant-wide access. In memberships, `assigned_outlet_ids` is `null`, permitting access to all outlets and cross-outlet aggregate queries.
  - `manager` & `cashier`: Explicitly bound to one or more outlets via `assigned_outlet_ids` in membership. If an outlet is not in their assigned set, access is strictly blocked with HTTP 403 `OUTLET_ACCESS_DENIED`.
- **Server-Authoritative Enforcement**:
  - The client is never trusted to dictate authorization. Any `outlet_id` in request payloads, route parameters, or query filters is validated against `User::hasOutletAccess($outletId)`.
  - Operational endpoints (POS sales, sale items, payments, receipts, shifts, expenses, tables, bookings, table sessions, orders, stock transfers) execute fail-closed outlet access checks prior to state retrieval or mutation.
- **Product-Outlet Availability Enforcement**:
  - Products deactivated for a specific outlet via `product_outlet_availabilities` (`is_available = false`) are automatically excluded from the POS catalog endpoint for that outlet.
  - Adding or finalizing sale items for an unavailable product throws `ProductNotAvailableAtOutletException` (HTTP 422 `PRODUCT_NOT_AVAILABLE_AT_OUTLET`).
- **Cross-Outlet Reporting Semantics**:
  - Financial reports (P&L, Cash Flow, Journal Entries) and Dashboard summaries support two explicit query modes:
    1. *Outlet-Specific*: Scoped to a specific `outlet_id`. Restricted managers/cashiers can only query outlets they are assigned to.
    2. *Tenant-Wide Aggregate*: Accessible only to users with tenant-wide permissions (`owner`). Automatically deduplicates transactions, honors cancellation/refund semantics, and maintains defense-in-depth tenant isolation via PostgreSQL RLS.
- **Offline Outbox & Sync Boundary**:
  - Device/client context is untrusted. When syncing offline operations (e.g. offline POS sales), `SyncService` verifies that the authenticated user possesses explicit access to the transaction's `outlet_id`.
  - Offline sync payloads attempting to forge unauthorized outlet IDs fail with HTTP 403 `OUTLET_ACCESS_DENIED` and are retained safely in local storage for administrative review without corrupting server state.

---

## 9. Catalog, Product, Category, Variant & Pricing Architecture (Phase 1B.1)

### 9.1 Tenant Relationship Integrity (Composite Foreign Keys)
Row-Level Security (RLS) is backed by PostgreSQL composite foreign keys across all tenant-scoped catalog relationships:
- `products(tenant_id, category_id) -> categories(tenant_id, id)`
- `product_variants(tenant_id, product_id) -> products(tenant_id, id)`
- `prices(tenant_id, variant_id) -> product_variants(tenant_id, id)`
- `prices(tenant_id, outlet_id) -> outlets(tenant_id, id)`
- `product_outlet_availabilities(tenant_id, product_id) -> products(tenant_id, id)`
- `product_outlet_availabilities(tenant_id, outlet_id) -> outlets(tenant_id, id)`
- `modifiers(tenant_id, modifier_group_id) -> modifier_groups(tenant_id, id)`
- `product_modifier_groups(tenant_id, product_id) -> products(tenant_id, id)`
- `product_modifier_groups(tenant_id, modifier_group_id) -> modifier_groups(tenant_id, id)`
- `product_barcodes(tenant_id, variant_id) -> product_variants(tenant_id, id)`

Cross-tenant references are physically impossible at the database level regardless of application code bugs.

### 9.2 Product & Variant Normalization Invariants
- POS operates uniformly on `ProductVariant` as the concrete sellable item.
- Every `Product` has at least one `ProductVariant`. Products without user-facing variants normalize into `Product + Standard Default Variant`.
- Exactly one default variant per product is enforced via PostgreSQL partial unique index:
  `CREATE UNIQUE INDEX idx_product_variants_one_default ON product_variants (product_id) WHERE (is_default = true);`
- Deleting or archiving the final remaining active variant of a product is strictly rejected.

### 9.3 Single Source of Truth for Barcodes
- Authoritative barcode storage resides exclusively in `product_barcodes`.
- No duplicated barcode column in `product_variants`.
- Uniqueness enforced tenant-wide via `UNIQUE(tenant_id, barcode)`.

### 9.4 Authoritative Pricing Architecture & Range Exclusion
- Dedicated `prices` table; money is represented as `bigint` (no floating-point values).
- Database-level overlapping period rejection via PostgreSQL `EXCLUDE USING gist`:
  - Default prices: `EXCLUDE USING gist (tenant_id WITH =, variant_id WITH =, tstzrange(effective_from, coalesce(effective_until, 'infinity'::timestamptz), '[)') WITH &&) WHERE (is_active = true AND outlet_id IS NULL)`
  - Outlet prices: `EXCLUDE USING gist (tenant_id WITH =, variant_id WITH =, outlet_id WITH =, tstzrange(effective_from, coalesce(effective_until, 'infinity'::timestamptz), '[)') WITH &&) WHERE (is_active = true AND outlet_id IS NOT NULL)`
- Price resolution precedence:
  1. Active outlet-specific price for the queried outlet.
  2. Active default tenant price (`outlet_id IS NULL`).
- Overlaps raise database code `23P01` and map to `422 PRICE_OVERLAPPING`.

### 9.5 Product-Outlet Availability
- `product_outlet_availabilities` stores explicit overrides.
- Default behavior: without an override row, products are available at all outlets.
- An explicit row with `is_available = false` deactivates the item for that outlet without duplicating the product record.

### 9.6 Modifiers System
- `modifier_groups -> modifiers` and `product_modifier_groups` association.
- Server validation enforces `min_selection <= max_selection` and single/multiple selection semantics.

### 9.7 Authorization & Granular Permissions
- Separate permissions:
  - `catalog.view`: View products, categories, modifiers, prices.
  - `catalog.create`: Create new products and variants.
  - `catalog.update`: Edit existing products and variants.
  - `catalog.archive`: Deactivate/archive catalog entities.
  - `catalog.price.update`: Authoritative pricing mutation.
  - `catalog.modifier.manage`: Manage modifier groups and items.
  - `catalog.category.manage`: Manage categories and hierarchy.
- Cashier role receives only `catalog.view`.

---

## 10. Inventory Foundation Architecture (Phase 1B.2)

### 10.1 Core Principle & Catalog Invariant
- **Separation of Concerns**: Catalog defines *WHAT is sold*; Inventory defines *HOW MUCH exists, WHERE it exists, and WHY it changed*. Authoritative stock quantity and cost price are strictly forbidden from leaking into Catalog models.
- **Price Resolution Invariant**: A catalog product variant with no configured price cannot silently fallback to Rp 0 or 0. Resolution throws `PriceNotConfiguredException`, returning HTTP 422 `PRICE_NOT_CONFIGURED`, preventing transactional selling of unpriced items.

### 10.2 ERD & Tenant Integrity
```text
TENANT
├── OUTLET (tenant_id, id)
│     └── INVENTORY_LOCATION (tenant_id, outlet_id, id)
│           └── INVENTORY_ITEM_LOCATION (tenant_id, location_id, inventory_item_id)
│                 └── INVENTORY_MOVEMENT (tenant_id, location_id, inventory_item_id)
├── PRODUCT_VARIANT (tenant_id, id)
│     └── INVENTORY_ITEM (tenant_id, variant_id, id)
├── STOCK_OPNAME (tenant_id, location_id, id)
│     └── STOCK_OPNAME_ITEM (tenant_id, opname_id, inventory_item_id)
└── STOCK_TRANSFER (tenant_id, source_location_id, destination_location_id, id)
      └── STOCK_TRANSFER_ITEM (tenant_id, transfer_id, inventory_item_id)
```
- **Composite Foreign Keys**: All inter-table relationships enforce tenant match at the PostgreSQL engine level (e.g. `(tenant_id, variant_id) REFERENCES product_variants(tenant_id, id)`, `(tenant_id, outlet_id) REFERENCES outlets(tenant_id, id)`).
- **PostgreSQL Row-Level Security**: All 8 inventory tables enforce `ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY`, failing closed (`WHERE 1 = 0`) unless a valid `app.current_tenant_id` session config is bound.

### 10.3 Immutable Ledger & Balance Read Model
- **Authoritative Source of Truth**: The ledger (`inventory_movements`) is strictly append-only. Updates and deletions are prevented at the Eloquent Model lifecycle (`saving`/`deleting` hooks throw `LogicException`). Corrections require reversing movements.
- **Balance Read Model**: `inventory_item_locations` maintains materialized balances (`on_hand`, `reserved`, `available`).
- **Deterministic Quantity Precision**: Quantities are stored as `numeric(14, 4)` to support fractional measurements (e.g., 0.5000 kg, 1.2500 liter) without floating-point inaccuracies.
- **Reconciliation Engine**: `InventoryService::reconcile($tenantId, $locationId, $itemId)` computes the sum of ledger deltas against `inventory_item_locations.on_hand`. Discrepancies are detected and can be corrected without destructive overwrites.

### 10.4 Concurrency Strategy & Negative Stock Policy
- **Pessimistic Locking**: `InventoryService` acquires row-level locks (`SELECT ... FOR UPDATE`) on `inventory_item_locations` within atomic database transactions, preventing race conditions and lost updates between concurrent operations.
- **Negative Stock Policy**: Default is disabled (`allow_negative_stock = false`). Attempting to deduct stock below zero throws `InsufficientStockException` (HTTP 422 `INSUFFICIENT_STOCK`). If explicitly enabled on the item, negative balances are permitted and auditable.

### 10.5 Stock Opname & Stock Transfer Lifecycles
- **Stock Opname**: Multi-stage workflow (`draft` -> `submitted` -> `approved` / `rejected`). Entering physical counts does not mutate stock. Approval is restricted to authorized roles (`inventory.opname.approve`), is idempotent, and atomically posts compensating movements for calculated variances.
- **Stock Transfers**: Multi-stage workflow (`draft` -> `pending` -> `approved` -> `in_transit` -> `received` / `cancelled`). On dispatch, stock is deducted from the source location with movement type `transfer_out`. On receipt, stock is added to the destination location with movement type `transfer_in`.

### 10.6 Permissions & Authorization
- Granular permissions:
  - `inventory.view`: Read locations, items, balances, movements.
  - `inventory.adjust`: Execute delta-based stock adjustments.
  - `inventory.opname.create`, `inventory.opname.review`, `inventory.opname.approve`: Physical count and approval pipeline.
  - `inventory.transfer.create`, `inventory.transfer.approve`, `inventory.transfer.receive`: Inter-location transfer pipeline.
  - `inventory.location.manage`: Create and manage inventory locations.
  - `inventory.threshold.manage`: Configure reorder levels and negative stock policies.
- Owner role possesses all inventory permissions; Cashier role possesses strictly `inventory.view`.

### 10.7 Ledger Hardening & Reservation Semantics (Phase 1B.2 Final)
- **Physical Stock Separation**:
  - `on_hand`: Strictly represents physical stock, authoritative ledger SUM of physical movements (`PHYSICAL_MOVEMENT_TYPES`).
  - `reserved`: Represents stock allocated/committed but not yet consumed (`RESERVATION_MOVEMENT_TYPES`).
  - Invariant: `available = on_hand - reserved`.
  - Invariant: `reserveStock` and `releaseStock` strictly DO NOT mutate `on_hand`. Movements are recorded with `quantity_delta = 0.0000` for physical stock provenance while mutating `reserved`.
  - Negative reservation prevention: `reserved` can never become negative. Reserving more than `available` throws `InsufficientStockException` when negative availability is not allowed.
- **Authoritative Reconciliation & Repair**:
  - Materialized balances in `inventory_item_locations` are read models/projections.
  - `InventoryService::reconcile` computes `ledger_sum` from authoritative physical movements and detects discrepancies.
  - `InventoryService::repairProjection` rebuilds materialized read-model balances strictly from ledger provenance and records an auditable `AuditLog` (`action = 'inventory_projection_repaired'`), never executing silent balance corrections or tampering with the immutable ledger.

---

## 11. Purchasing & Supplier Foundation Architecture (Phase 1B.3)

### 11.1 Domain Principles & Boundaries
- **Purchasing Answers**: *From whom* items were bought, *what* was bought, *quantity*, *historical purchase cost*, *destination location*, *reception timestamp*, *receiving user*, and *inventory ledger impact*.
- **Domain Decoupling**:
  - Catalog stores *WHAT is sold* (never authoritative cost price).
  - Inventory remains the *sole authority for stock* (ledger append-only).
  - Purchasing serves as the *command source* for inventory movements; it never executes direct `UPDATE inventory_item_locations`.
  - Finance / General Ledger / HPP calculations are deferred to future phases; Phase 1B.3 builds the granular historical purchase cost foundation.

### 11.2 ERD & Tenant Integrity
```text
TENANT
├── SUPPLIERS (tenant_id, id)
│     [status: active / inactive]
├── PURCHASE_ORDERS (tenant_id, supplier_id, destination_location_id, id)
│     ├── PURCHASE_ORDER_ITEMS (tenant_id, purchase_order_id, inventory_item_id, id)
│     └── PURCHASE_RECEIPTS (tenant_id, purchase_order_id, destination_location_id, id)
│           └── PURCHASE_RECEIPT_ITEMS (tenant_id, purchase_receipt_id, purchase_order_item_id, inventory_item_id, id)
```
- **PostgreSQL Composite Foreign Keys**:
  - `purchase_orders(tenant_id, supplier_id) -> suppliers(tenant_id, id)`
  - `purchase_orders(tenant_id, destination_location_id) -> inventory_locations(tenant_id, id)`
  - `purchase_order_items(tenant_id, inventory_item_id) -> inventory_items(tenant_id, id)`
  - `purchase_receipts(tenant_id, purchase_order_id) -> purchase_orders(tenant_id, id)`
  - `purchase_receipt_items(tenant_id, purchase_receipt_id) -> purchase_receipts(tenant_id, id)`
  - Cross-tenant references are strictly rejected at the database engine level.
- **PostgreSQL RLS**: All 5 purchasing tables enforce `ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY`, failing closed (`WHERE 1 = 0`) unless `app.current_tenant_id` session setting matches.

### 11.3 Purchase Order Lifecycle & State Machine
- **PO States**: `draft` -> `submitted` -> `approved` -> `partially_received` -> `received` (or `cancelled`).
- **Stock Invariant**: Neither PO creation (`draft`) nor approval (`approved`) mutates stock. Stock remains untouched until actual Goods Receipt (GRN).
- **Supplier Status Invariant**: Inactive suppliers (`status = 'inactive'`) cannot be used to create new purchase orders (throws `SupplierInactiveException` / HTTP 422 `SUPPLIER_INACTIVE`).

### 11.4 Goods Receipt (GRN) & Inventory Integration
- **Goods Receipt Aggregate**: `purchase_receipts` and `purchase_receipt_items` generate human-readable `GRN-YYYYMMDD-XXXXXX`.
- **Atomic Posting Pipeline (`PurchasingService::postReceipt`)**:
  1. Acquires pessimistic row locks (`SELECT ... FOR UPDATE`) on PO, PO items, and receipt items.
  2. Validates receipt state is `draft` (posted receipts are strictly immutable).
  3. Enforces **Over-Receiving Policy**: `received_quantity <= remaining_quantity`. Exceeding remaining throws `PurchaseReceiptOverQuantityException` (HTTP 422 `PURCHASE_RECEIPT_OVER_QUANTITY`).
  4. Calls `InventoryService::recordMovement` with `movement_type = 'purchase_receipt'`, atomically increasing `on_hand` and balance read model.
  5. Stores historical purchase cost (`unit_cost_minor` in integer IDR currency minor units, e.g. Rp 15.000 = 15000). Never uses float/double.
  6. Atomically updates `purchase_order_items.received_quantity`.
  7. Transitions PO status: `received` if all items fully received, `partially_received` if items remain.
  8. Emits `PurchaseReceiptPosted` domain event and writes audit log.

### 11.5 Permissions & Least Privilege
- Granular permissions:
  - `purchasing.view`: Read suppliers, purchase orders, receipts.
  - `purchasing.supplier.manage`: Create/edit suppliers.
  - `purchasing.create`, `purchasing.update`, `purchasing.submit`: Manage draft and submitted POs.
  - `purchasing.approve`: Authorize submitted POs.
  - `purchasing.receive`: Create and post Goods Receipts.
  - `purchasing.cancel`: Cancel POs or draft receipts.
- Role Assignment: Owner receives full purchasing privileges; Cashier has zero purchasing mutation permissions (enforced by RBAC middleware returning 403 Forbidden).

---

## 12. Recipe / BOM & Costing Foundation Architecture (Phase 1B.4 Hardened)

### 12.1 Costing Authority Matrix

SeWaroeng establishes an explicit, unambiguous single-authority model across business domains:

| Domain | Authority |
|---|---|
| Product identity | Catalog Product / ProductVariant (`products`, `product_variants`) |
| Selling price | Catalog Price domain (`prices` table + outlet/default resolution rules) |
| Physical stock | Inventory Movement Ledger (`inventory_movements`) |
| Current valuation | Inventory Cost State / MWA (`inventory_cost_states.average_cost_minor`) |
| Historical purchase price | Purchasing (`purchase_order_items.unit_cost_minor`) |
| Historical cost provenance | Inventory Cost Layers (`inventory_cost_layers`) |
| Recipe consumption | Recipe/BOM (`recipes`, `recipe_versions`, `recipe_components`) |
| Future COGS | Inventory Consumption × authoritative location MWA |

> [!IMPORTANT]
> **FIFO is NOT the authoritative valuation method in Phase 1.**
> Moving Weighted Average (MWA) is the sole authoritative costing method across SeWaroeng. Outbound sales consumption, inter-location transfers, and inventory write-offs are economically valued using the authoritative location MWA. The system does not employ dual valuation or hidden FIFO layer selection for COGS.

### 12.2 Authoritative Moving Weighted Average (MWA) Invariants

All monetary calculations use integer IDR minor units (`bigint`) with `bcmath` fixed-point decimal precision (`scale = 8`) rounded half-up to integer. Floating-point arithmetic is strictly prohibited.

#### 1. Inbound Invariant
When stock increases via purchase receipt, transfer-in, adjustment-in, or opening balance:
$$\text{new\_mwa} = \text{ROUND}\left( \frac{(\text{on\_hand\_before} \times \text{old\_mwa}) + (\text{inbound\_qty} \times \text{inbound\_unit\_cost})}{\text{on\_hand\_before} + \text{inbound\_qty}} \right)$$

* **Special Case (`on_hand_before <= 0`)**: If physical stock prior to receipt was zero or negative, the previous average cost is superseded and the new receipt establishes a fresh cost basis:
  $$\text{new\_mwa} = \text{inbound\_unit\_cost}$$

#### 2. Outbound Invariant
When stock decreases via sales consumption, adjustment-out, waste, or transfer-out:
$$\text{outbound\_valuation} = \text{ROUND}(\text{consumed\_qty} \times \text{current\_location\_mwa})$$

* **MWA Invariance on Outbound**: Outbound consumption strictly **does NOT** recalculate or mutate the unit MWA cost basis:
  $$\frac{(Q - q) \times \bar{c}}{Q - q} = \bar{c}$$
  Current MWA remains identical until a subsequent inbound valuation event occurs.

#### 3. Inter-Location Transfer Invariant
Inter-location transfers preserve the source location's cost basis:
* Transfer Out: Stock is deducted at source location; source MWA is unchanged.
* Transfer In: Destination location receives goods valued at the source location's MWA at dispatch time. Transfers are not treated as external supplier purchases.
$$\text{dest\_mwa} = \text{ROUND}\left( \frac{(\text{dest\_qty\_before} \times \text{dest\_mwa\_before}) + (\text{transferred\_qty} \times \text{source\_mwa})}{\text{dest\_qty\_before} + \text{transferred\_qty}} \right)$$

#### 4. Negative Stock Invariant
* When `allow_negative_stock = true` and an outbound movement drives on-hand balance negative, the existing location MWA is preserved so valuation queries remain well-defined without dividing by zero or negative numbers.
* When subsequent inbound stock arrives (`on_hand_before <= 0`), the negative balance is replenished and the inbound receipt's unit cost establishes the fresh MWA cost basis.

#### 5. Inventory Adjustment Invariant
* `adjustment_in` without an explicit cost basis adopts the current location MWA.
* `adjustment_in` when no prior MWA exists requires an explicit unit cost basis or explicit domain state.
* The system **never silently fabricates Rp 0** as an unknown cost basis.

### 12.3 Cost Layer Semantics (Model A — Historical Cost Provenance Only)

SeWaroeng Phase 1 strictly implements **Model A: Historical Cost Provenance Only**:
* **Role**: `inventory_cost_layers` provides an append-only, strictly immutable historical record of every inbound stock increment (purchase receipt, transfer-in, adjustment-in, opening balance, stock opname).
* **Stored Attributes**:
  - `quantity`: Original inbound quantity received.
  - `unit_cost_minor`: Inbound historical unit cost in integer minor units.
  - `total_cost_minor`: Total historical cost (`ROUND(quantity × unit_cost_minor)`).
  - `source_movement_id`: Foreign key link to the causing `inventory_movements` ledger entry.
  - `source_type` & `source_reference`: PO number, GRN number, transfer number, or opname reference.
* **Strict Immutability**:
  - Cost layers are 100% immutable upon insertion (`static::updating` throws `DomainException`).
  - The misleading `remaining_quantity` column has been completely removed from `inventory_cost_layers`.
  - The architecture does **not** pretend cost layers track live physical lot depletion.
* **Architectural Separation: Costing vs. Lot Tracking**:
  - **COSTING METHOD**: Moving Weighted Average (MWA) managed strictly in `inventory_cost_states`. Outbound COGS is always `consumed_quantity × current_mwa`.
  - **PHYSICAL STOCK**: Managed strictly in the append-only `inventory_movements` ledger and projected into `inventory_item_locations`.
  - **LOT TRACKING**: An optional future operational capability (for expiry/FIFO picking if needed), completely decoupled from the financial valuation engine.

### 12.4 Future Outbound Readiness (Phase 1C POS Contract)

Future POS outbound sales consumption will execute against this deterministic contract:
1. **Sale Initiation**: POS submits completed order items.
2. **Atomic Transaction**:
   - Acquire pessimistic row lock on `inventory_item_locations` and `inventory_cost_states`.
   - Read current authoritative location MWA.
   - Validate physical stock availability (or check `allow_negative_stock`).
   - Create immutable `inventory_movements` ledger entry (`movement_type = 'sales_consumption'`).
   - Calculate consumed valuation: `ROUND(quantity × current_mwa)`.
   - Persist sales COGS provenance with applied MWA and reference ID.
   - Update materialized on-hand balance.
   - Commit transaction atomically.
3. No FIFO layer matching or layer search is performed.

### 12.5 Recipe / BOM & Nested Component Architecture

* **Domain Separation**:
  - Catalog defines *WHAT is sold* (`products`, `product_variants`).
  - Inventory defines *WHAT is produced / stocked* (`inventory_items`).
  - Recipe defines *WHAT components are consumed* (`recipes`, `recipe_versions`, `recipe_components`).
* **Nested Ownership Model**:
  - `recipes.product_variant_id`: Sellable product variant recipe.
  - `recipes.inventory_item_id`: Semi-finished or finished good recipe.
  - Exclusive ownership enforced via database check constraint `chk_recipes_owner_exclusive`.
* **Cycle Detection**:
  - Depth-First Search with recursion stack tracking over the entire BOM graph (variants + items) prior to version publication.
  - Rejects direct ($A \to A$), two-node ($A \to B \to A$), and three-node ($A \to B \to C \to A$) cycles with stable domain error `RECIPE_CYCLE_DETECTED`.
  - Permitted: Diamond DAGs containing shared sub-components.
* **Deterministic Recipe Explosion (Read Model)**:
  - `RecipeService::explodeRecipe`: Recursively resolves leaf raw materials and packaging with yield normalization and waste factor multiplication.
  - Guarded against infinite recursion (`max_depth = 10`).
  - Read-only; causes zero inventory mutations.
* **Recursive Cost Estimation**:
  - `RecipeService::estimateCost`: Computes estimated batch and per-unit costs using current location MWAs for leaf items and child recipe estimates for semi-finished items.
  - Excludes Catalog selling prices. Labeled "Estimated Recipe Cost", distinct from final accounting COGS.

---

## 13. POS / Sales Transaction Core (Phase 1C Hardened)

### 13.1 POS Transaction Lifecycle & Domain Model
The POS sales domain cleanly separates cart management, sales order lifecycle, and immutable accounting finalization:
* **Tables**:
  - `sales`: Transaction aggregate root (`id UUIDv7`, `tenant_id`, `outlet_id`, `reference_number`, `status`, `opened_at`, `finalized_at`, `cancelled_at`, `cashier_user_id`, `subtotal_minor`, `discount_minor`, `tax_minor`, `total_minor`).
  - `sale_items`: Line items capturing variant identity, quantity, price snapshot, subtotal, discount, total, and recipe version snapshot.
  - `sale_item_modifiers`: Selected modifiers capturing modifier identity, group name snapshot, modifier name snapshot, unit price adjustment snapshot, and subtotal.
  - `sale_consumptions`: Append-only, immutable inventory consumption and historical COGS provenance records.
* **Reference Format**: `SALE-YYYYMMDD-XXXXXX` (e.g. `SALE-20261004-AB12CD`).
* **State Machine**:
  - `open`: Initial cart or ticket. May be updated, added to, or cancelled without physical inventory movement.
  - `finalized`: Atomically finalized. Stock deducted, MWA captured, COGS recorded, prices and recipes snapshotted. Immutable.
  - `cancelled`: Cancelled cart. If cancelled while `open`, no movements are created. If cancelled while `finalized`, compensating `sales_reversal` movements are generated to restore stock at the original cost basis.

### 13.2 Price Snapshot Semantics
At finalization time, selling prices are resolved through the dedicated Price domain:
1. **Precedence Rules**: Active outlet-specific price override (`outlet_id = sale.outlet_id`) takes precedence over tenant-wide default price (`outlet_id IS NULL`).
2. **Fail-Closed Guarantee**: If no active price is configured for a variant at the finalization timestamp, the transaction is rejected immediately with domain error `PRICE_NOT_CONFIGURED`. The system **never falls back to Rp 0**.
3. **Immutability**: The resolved price is snapshotted into `sale_items.unit_price_minor`. Subsequent changes to Catalog prices have zero effect on historical sales transactions.

### 13.3 Modifier Snapshot Semantics
Selected modifiers scale with item portions and snapshot their historical price adjustment:
* For each portion, modifier price adjustments (`price_delta`) are recorded in integer minor units.
* At finalization, `modifier_name_snapshot`, `modifier_group_name_snapshot`, and `unit_price_minor` are snapshotted into `sale_item_modifiers`.
* Subsequent changes to modifier group names, options, or price adjustments do not alter historical sale records.

### 13.4 Server-Authoritative POS Inventory Source Location
To ensure strict security and physical accuracy, POS stock depletion is strictly server-authoritative:
1. **Outlet Default Location**: The `outlets` table designates a `default_pos_location_id` referencing `inventory_locations(id)` with a database composite foreign key enforcing identical tenant ownership:
   ```sql
   CONSTRAINT fk_outlets_pos_location_tenant
       FOREIGN KEY (tenant_id, default_pos_location_id)
       REFERENCES inventory_locations(tenant_id, id) ON DELETE SET NULL
   ```
2. **Deterministic Resolution Pipeline (`Outlet::resolvePosInventoryLocation`)**:
   - If the client specifies a `location_id`, the server resolves that location within the tenant and validates `location.outlet_id == outlet.id`. If there is any mismatch, the transaction is rejected with HTTP 422 `INVENTORY_LOCATION_OUTLET_MISMATCH`.
   - If no `location_id` is supplied, the server uses `$outlet->default_pos_location_id`.
   - If `default_pos_location_id` is unconfigured, the server resolves the primary active location for that outlet (`type IN ('outlet', 'kitchen')`).
   - If no active location belongs to the outlet, the transaction fails closed with HTTP 422 `NO_POS_INVENTORY_LOCATION_FOR_OUTLET`.
3. **No Cross-Boundary Leaks**: Clients can never inject arbitrary location IDs from other outlets or other tenants.

### 13.5 Decoupling Recipe Dependency Explosion from Physical Consumption
We strictly distinguish the **Recipe Dependency Graph** from the **Physical Inventory Consumption Policy**:
* **Pure Recipe Explosion (`RecipeService::explodeRecipe`)**: A read-only calculation resolving theoretical leaf raw materials and packaging. It serves theoretical requirements, batch estimation, and yield analysis. It never triggers physical stock movements.
* **Physical Consumption Policy (`PosService::resolvePhysicalConsumptionRequirements`)**: Determines the exact physical inventory items and quantities to deduct from the shelf at the point of sale.

### 13.6 Semi-Finished Goods Consumption Semantics (Mode A vs Mode B)
In Food & Beverage operations, intermediate items (e.g. Sambal, Broth, Dough) may either be pre-produced or prepared on-the-fly. The system supports both deterministic operational modes:
* **Item-Level Policy (`inventory_items.production_mode`)**:
  - `stocked` (Mode A — Pre-Produced / Batched): The item is held as stock (`type = 'semi_finished'`). When ordered, POS deducts the semi-finished item directly from inventory. The child recipe is **not** traversed for stock deduction, and child raw materials are **not** consumed.
  - `make_to_order` (Mode B — Fresh-Prep / Phantom): The item is assembled on-demand. When ordered, POS traverses the child recipe and deducts the child components from stock. The intermediate semi-finished item is **not** consumed from stock.
* **Component-Level Override (`recipe_components.consumption_mode`)**:
  - A parent recipe component may explicitly set `consumption_mode` (`stocked` or `make_to_order`), overriding the item-level default for specific culinary contexts.
* **Effective Mode Resolution**:
  $$\text{effective\_mode} = \text{component.consumption\_mode} \mathbin{??} \text{item.production\_mode} \mathbin{??} \text{'stocked'}$$

### 13.7 Zero Double-Consumption Invariant & Costing Semantics
1. **Mandatory Invariant**: A sale must **never** consume both a semi-finished component and that component's child raw materials for the same physical production path.
2. **COGS Accounting**:
   - **Mode A (Stocked)**: $\text{COGS} = \text{consumed\_quantity} \times \text{current\_location\_MWA of semi-finished item}$. Raw materials were already accounted for during kitchen batch manufacturing.
   - **Mode B (Make-to-Order)**: $\text{COGS} = \sum (\text{child\_quantity} \times \text{child\_location\_MWA})$. Raw materials are expensed directly at checkout.

### 13.8 Historical Recipe & Consumption Snapshot
Every finalized `sale_items` row records an immutable `recipe_snapshot` containing:
* `recipe_version_id`: The published recipe version UUID active at checkout.
* `theoretical_leaves`: Full recursive leaf breakdown from pure BOM explosion.
* `consumption_plan`: Physical consumption tree explicitly recording each item's `production_mode`, resolved `consumption_mode`, and deducted quantities.
Future recipe edits or item mode changes have zero retrospective effect on finalized sale records or historical auditability.

### 13.9 Complete Inventory Availability Aggregation
Before any stock is mutated, the complete physical transaction set is evaluated:
1. All physical requirements across all sale items are aggregated by `inventory_item_id`.
   - *Example*: Line 1 requires 18g Coffee + Line 2 requires 18g Coffee $\implies$ Total requirement = 36g Coffee.
2. Availability check:
   $$\text{available} = \text{on\_hand} - \text{reserved} \ge \text{required}$$
   unless the item policy explicitly permits negative stock (`allow_negative_stock = true`).
3. If any item has insufficient stock, the transaction is rejected with `INSUFFICIENT_STOCK`, causing an atomic rollback.

### 13.10 Concurrency Model & Deadlock Prevention
To guarantee deterministic execution under high-volume concurrent cashier traffic:
* **Ordered Locking**: All affected `inventory_item_locations` and `inventory_cost_states` rows are acquired in ascending order sorted by `inventory_item_id`.
* **Pessimistic Row Locks**: `SELECT ... FOR UPDATE` ensures serialized balance evaluation and deduction.
* Two cashiers competing for the last unit of stock execute deterministically: exactly one cashier succeeds; the other receives `INSUFFICIENT_STOCK`.

### 13.11 MWA Outbound Valuation & COGS Snapshot
* **Outbound Valuation Authority**: Outbound consumption valuation is strictly:
  $$\text{COGS} = \text{consumed\_quantity} \times \text{captured\_current\_location\_MWA}$$
* **No MWA Mutation**: Outbound movements (`movement_type = 'sales_consumption'`) do **not** alter `inventory_cost_states.average_cost_minor`. Only inbound valuation-changing events modify MWA.
* **No Cost Layer Mutation**: POS consumption does **not** consume or mutate `inventory_cost_layers`.
* **Provenance Record (`sale_consumptions`)**:
  - `consumed_quantity`: Deterministic fixed-point decimal (4 scale).
  - `unit_cost_minor`: Location MWA captured at finalization time.
  - `total_cost_minor`: $\text{ROUND}(\text{consumed\_quantity} \times \text{unit\_cost\_minor})$.
  - `inventory_movement_id`: Foreign key link to the immutable sales consumption movement.
  - COGS valuation is permanently sealed; future purchase receipts that change MWA do not retroactively alter finalized COGS.

### 13.12 Cancellation & Compensating Reversals
* **Open Sale Cancellation**: Status transitions to `cancelled`. No physical inventory movements are generated.
* **Finalized Sale Cancellation**:
  - Original `sales_consumption` movements remain immutable.
  - Compensating `sales_reversal` movements (`movement_type = 'sales_reversal'`) are inserted into the inventory ledger.
  - On-hand stock is restored in `inventory_item_locations`.
  - Reversal movements use the original captured unit cost basis.

### 13.13 Durable Idempotency
All mutation endpoints (`POST /pos/sales`, `PUT /pos/sales/{id}`, `POST /pos/sales/{id}/finalize`, `POST /pos/sales/{id}/cancel`) enforce the `Idempotency-Key` header:
* Replaying the same key with an identical payload returns the cached response with `X-Idempotent-Replay: true`.
* Replaying the same key with a modified payload returns HTTP 409 `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`.
* Replays never duplicate sales, double-deduct inventory, or double-count COGS.

### 13.14 Offline-First Contract & Conflict Resolution
The offline architecture respects the server-authoritative model:
* Clients generate client-side UUIDv7 IDs for outbox tracking.
* Server remains authoritative for tenant verification, price resolution, stock availability, MWA capture, and finalization.
* Supported sync states: `SYNC_PENDING`, `SYNCED`, `CONFLICT`, `REJECTED`.
* If a conflict occurs (e.g. insufficient stock or inactive price), the sale is marked `REJECTED` or `CONFLICT` and never silently marked as finalized on the server.

### 13.15 Granular RBAC Permissions
* `pos.view`: View POS catalog, open carts, and status.
* `pos.create`: Create open sales and update cart lines.
* `pos.finalize`: Finalize sales and commit inventory consumption.
* `pos.cancel`: Cancel open and finalized sales.
* `pos.history.view`: View transaction history and audit details.



---

## 14. Payment & Settlement Foundation (Phase 1D)

### 14.1 Core Authority Principle — Sale vs. Payment Separation

SeWaroeng strictly separates the **Sales lifecycle** from the **Payment lifecycle** as two orthogonal authorities:

| Authority | Responsibility |
|---|---|
| **Sale** | "Is the business transaction finalized?" (`status`: `open` → `finalized` → `cancelled`) |
| **Payment** | "Has the transaction been paid, and how?" (`status`: `pending` → `succeeded` / `failed` / `cancelled` / `refunded`) |

> [!IMPORTANT]
> Payment state is **never** stored as a field on `Sale`. `Sale.status` is immutable at finalization and represents the merchandising/inventory lifecycle, not the settlement state. These two lifecycles are strictly decoupled and evolve independently.

### 14.2 Domain Model & ERD

```text
TENANT
└── SALE (finalized)
      └── PAYMENT (tenant_id, sale_id, id)
            ├── PAYMENT_ATTEMPTS (tenant_id, payment_id, id)
            └── PAYMENT_REFUNDS (tenant_id, payment_id, id)

PAYMENT_WEBHOOK_EVENTS (platform-scoped; no tenant_id initially)
```

**Table Responsibilities**:
- `payments`: Aggregate root for a single payment intent against a finalized sale. One sale may have multiple payments (e.g. split tender, failed retry replaced by a new attempt).
- `payment_attempts`: Immutable append-only log of every provider API call for a given payment intent, including provider reference codes, response codes, and timestamps.
- `payment_refunds`: Full or partial refund records against a `succeeded` or `partially_refunded` payment.
- `payment_webhook_events`: Platform-scoped deduplication and audit record of all external provider webhook calls, keyed by `(provider, external_event_id)`.

**Reference Formats**:
- `PAY-YYYYMMDD-XXXXXX` (e.g. `PAY-20261004-AB12CD`)
- `REF-YYYYMMDD-XXXXXX` (e.g. `REF-20261004-XY78EF`)

### 14.3 Payment State Machine

```text
pending
  ├──[processPayment → provider succeeds]──► succeeded
  ├──[processPayment → provider fails]──────► failed
  ├──[cancelPayment]─────────────────────────► cancelled
  └──[processPayment → provider async]──────► processing
         ├──[webhook: succeeded]──────────────► succeeded
         └──[webhook: failed]─────────────────► failed

succeeded
  ├──[refundPayment (partial)]──────────────► partially_refunded
  └──[refundPayment (full)]─────────────────► refunded
```

**Terminal States**: `cancelled`, `refunded`. A `succeeded` payment can never revert to `pending`, `processing`, or `failed`.

### 14.4 Invariants & Guards

1. **Sale Must Be Finalized**: `PaymentService::createPayment` enforces `Sale.status === 'finalized'`. Attempting to pay an open or cancelled sale throws `PaymentSaleNotFinalizedException` (HTTP 422 `PAYMENT_SALE_NOT_FINALIZED`).
2. **No Overpayment**: Payment amount must not exceed the outstanding balance (`Sale.total_minor − Σ succeeded payments + Σ completed refunds`). Violations throw `PaymentAmountExceedsOutstandingException` (HTTP 422 `PAYMENT_EXCEEDS_OUTSTANDING`).
3. **Concurrency Serialization**: Both `Sale` and `Payment` rows are locked with `SELECT ... FOR UPDATE` before any mutation, preventing double-payment race conditions under concurrent cashier traffic.
4. **No Refund Overshoot**: `PaymentService::refundPayment` validates `refund_amount ≤ refundable_balance`. Violations throw `PaymentRefundExceedsPaidException` (HTTP 422 `PAYMENT_REFUND_EXCEEDS_PAID`).
5. **Monotonic State Guard (Webhooks)**: A `succeeded` payment can never regress to `processing`, `pending`, or `failed` status via an out-of-order webhook delivery. Similarly, `refunded` cannot transition to `succeeded` or `processing`, and `partially_refunded` cannot transition to `failed`. Such events are safely ignored and marked `OUT_OF_ORDER_IGNORED`.
6. **Webhook Amount Validation (Phase 1D.1)**: Signature authenticity proves origin but not semantic correctness against internal records. Webhook payload amounts must strictly match internal `payment.amount_minor`. Mismatches throw `PaymentWebhookAmountMismatchException` (HTTP 400 `PAYMENT_WEBHOOK_AMOUNT_MISMATCH`), aborting state mutation and emitting no domain events.
7. **Webhook Currency Validation (Phase 1D.1)**: Webhook payload currency must match `payment.currency` (Phase 1: `IDR`). Mismatches throw `PaymentWebhookCurrencyMismatchException` (HTTP 400 `PAYMENT_WEBHOOK_CURRENCY_MISMATCH`).
8. **Sensitive Data Redaction (Phase 1D.1)**: All provider payloads (`payment_attempts.response_payload`, `payment_webhook_events.payload`, `payments.metadata`) are sanitized with `AuditLog::sanitizePayload()` before persistence. PAN, card numbers, CVV, private keys, API secrets, and credentials are redacted as `[REDACTED]`.

### 14.5 Settlement Read Model

`PaymentService::getSalePaymentSummary(Sale)` computes an on-demand settlement summary from the financial aggregate state. It does **not** mutate any state:

```text
settlement_status:
  'unpaid'             — no succeeded payments
  'partially_paid'     — paid > 0 but outstanding > 0
  'paid'               — outstanding == 0 and refunded == 0
  'partially_refunded' — refunded > 0 but refunded < total
  'refunded'           — paid >= total and refunded >= paid
```

Fields returned: `total_minor`, `paid_minor`, `refunded_minor`, `net_paid_minor`, `outstanding_minor`, `settlement_status`, `payments_count`.

### 14.6 Provider Abstraction & Unsupported Providers

```text
PaymentProviderManager
  └── resolves provider string → PaymentProviderInterface
        ├── ManualCashPaymentProvider  ('manual', 'cash') — synchronous cash provider
        └── MockPaymentProvider        ('mock')           — test/dev double
```

`PaymentProviderInterface` contract:
- `initiatePayment(Payment, array): array` — Initiates the payment, returns `{status, provider_reference, response_code, response_payload}`.
- `processRefund(PaymentRefund): array` — Processes the refund, returns `{status, provider_reference}`.
- `verifyWebhookSignature(Request): bool` — Validates HMAC or provider signature.
- `parseWebhookEvent(Request): array` — Extracts `{external_event_id, provider_payment_id, status, amount_minor, currency, payload}`.
- `getProviderName(): string` — Returns canonical provider identifier.

> [!WARNING]
> While DB schema constraints accept `qris`, `card`, `bank_transfer`, `e_wallet`, no production adapters exist in Phase 1D/1D.1. Attempting to process payments via these unregistered providers throws `UnsupportedPaymentProviderException` (HTTP 400 `UNSUPPORTED_PAYMENT_PROVIDER`), causing full transaction rollback with zero mutation.
>
> `ManualCashPaymentProvider::verifyWebhookSignature()` always returns `false`. Cash payments cannot be converted or altered via public webhook paths.

### 14.7 Webhook Processing, Platform RLS & Cross-Tenant Protection

Webhook events arrive without an active tenant context at the public endpoint (`POST /api/v1/payments/webhooks/{provider}`).

1. **Cryptographic Authentication**: The webhook signature is verified first via `$provider->verifyWebhookSignature($request)`. Invalid signatures throw `WebhookSignatureInvalidException` (HTTP 400 `WEBHOOK_SIGNATURE_INVALID`).
2. **Platform Context Resolution**: The target payment is located using a narrow platform-context bypass:
   ```php
   DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
   try {
       $payment = Payment::query()->withoutGlobalScopes()->where('provider', $providerName)...->first();
   } finally {
       DB::statement("SELECT set_config('app.is_platform_context', '', true)");
   }
   ```
   - Transaction scoped (`is_local = true`).
   - Cleared in `finally` guaranteed block (never leaks across queries or on exception).
   - Never client-controlled.
3. **Cross-Tenant Protection**: Client-injected tenant headers (e.g. `X-Tenant-ID`) are completely ignored on the public webhook route. The tenant context is derived strictly from the internal `Payment.tenant_id`. All business logic runs inside `TenantManager::runForTenant($payment->tenant_id, fn)`. Attacker cannot mutate or inspect cross-tenant payments.
4. **Webhook Idempotency & Deterministic Retry Rejection**:
   - `(provider, external_event_id)` is unique.
   - Successful replays return `duplicate_ignored` without duplicate side effects or events.
   - Failed events (e.g. `PAYMENT_WEBHOOK_AMOUNT_MISMATCH`) deterministically reject retries of the same event ID with the identical failure code.
   - Corrected provider events with new `external_event_id` succeed if amount matches.

### 14.8 PostgreSQL RLS Policy

Payment tables (`payments`, `payment_attempts`, `payment_refunds`) enforce:
```sql
USING (
    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
    OR NULLIF(current_setting('app.is_platform_context', true), '') = 'true'
)
```
- Standard cashier/owner requests: isolated to their tenant.
- Platform webhook handler: bypasses via `app.is_platform_context = 'true'` (transaction-scoped, never leaks).

`payment_webhook_events` has **no RLS** by design — it is a platform-level deduplication table with no tenant data until the `tenant_id` is back-filled after provider lookup.

### 14.9 Composite Foreign Keys & Tenant Integrity

All payment table inter-relations enforce composite tenant ownership at the PostgreSQL engine level:
- `payments(tenant_id, sale_id) → sales(tenant_id, id)`
- `payment_attempts(tenant_id, payment_id) → payments(tenant_id, id)`
- `payment_refunds(tenant_id, payment_id) → payments(tenant_id, id)`

Cross-tenant payment references are structurally impossible at the database level regardless of application code bugs.

### 14.10 RBAC Permissions
- `payment.view`: Read payment status and settlement summary for a sale.
- `payment.create`: Initiate a new payment against a finalized sale.
- `payment.process`: Trigger provider processing for a pending payment.
- `payment.cancel`: Cancel a pending or processing payment. (**Manager only** — Cashier role receives HTTP 403 Forbidden.)
- `payment.refund`: Issue full or partial refunds against succeeded payments. (**Manager only** — Cashier role receives HTTP 403 Forbidden.)
- `payment.reconcile`: Access payment reconciliation and reporting. (Accountant and Manager roles.)

> [!NOTE]
> The webhook endpoint (`POST /api/v1/payments/webhooks/{provider}`) is **intentionally public** — external payment providers cannot authenticate with Sanctum tokens. It is protected exclusively by HMAC signature verification delegated to the provider adapter (`verifyWebhookSignature()`). There is no `payment.webhook.receive` RBAC permission; this protection lives at the cryptographic layer, not the authorization layer.

---

## 15. Production Payment Gateway Integration — Midtrans (Phase 1E)

Phase 1E introduces the first official production payment gateway adapter: **Midtrans** (`App\Services\Payment\Providers\MidtransPaymentProvider`).

### 15.1 Implementation Status
- **Midtrans Core API Adapter**: `IMPLEMENTED` (`midtrans`)
- **QRIS Dynamic Generation**: `IMPLEMENTED` (`method: 'qris'`)
- **Bank Transfer / VA**: `IMPLEMENTED` (`method: 'bank_transfer'`)
- **Webhook SHA-512 Verification**: `IMPLEMENTED`
- **Webhook Deterministic Idempotency & Out-of-Order Guards**: `IMPLEMENTED`
- **Server-Authoritative Status Inquiry API**: `IMPLEMENTED` (`POST /api/v1/payments/{payment}/sync-status`)
- **Direct Programmatic Refund API**: `IMPLEMENTED` (`POST /v2/{order_id}/refund`)
- **Multi-Tenant RLS Webhook Resolution**: `IMPLEMENTED`
- **Offline Client Auto-Settlement**: `PROHIBITED` (Always server-authoritative)
- **Secondary Real Providers (Xendit/Duitku)**: `INTERFACE-ONLY` (deferred to future phases)

### 15.2 Authentication & Configuration
Midtrans API requests authenticate using HTTP Basic Auth:
`Authorization: Basic base64(ServerKey . ':')`
with `Accept: application/json` and `Content-Type: application/json`.

Environment variables (`config/services.php`):
- `MIDTRANS_SERVER_KEY`: Server-side secret key (never exposed to Flutter, API responses, or logs).
- `MIDTRANS_CLIENT_KEY`: Client-safe key for optional future frontend SDKs.
- `MIDTRANS_MERCHANT_ID`: Midtrans merchant ID.
- `MIDTRANS_IS_PRODUCTION`: Boolean flag (`true` = `https://api.midtrans.com`, `false` = `https://api.sandbox.midtrans.com`).
- `MIDTRANS_BASE_URL`: Optional endpoint override.

### 15.3 Order Reference Mapping & Initiation
- **Merchant Order Reference**: `Payment.reference_number` (e.g. `PAY-20261005-XXXXXX`) maps directly to Midtrans `order_id`.
- **Provider Reference**: Midtrans `transaction_id` (UUIDv4) persists to `Payment.provider_payment_id` and `PaymentAttempt.provider_reference`.
- **Authoritative Amount**: Dispatched strictly from server-authoritative `Payment.amount_minor` (IDR).
- **Presentation Data**: Client-safe QR string (`qr_string`), action URLs (`actions`), and VA numbers (`va_numbers`) are returned in `PaymentAttempt.response_payload` and `Payment.metadata.provider_result` for Flutter presentation without leaking gateway credentials.

### 15.4 Status Mapping Matrix
Midtrans transaction and fraud statuses map monotonically to internal payment statuses:

| Midtrans `transaction_status` | Midtrans `fraud_status` | Internal `Payment.status` | Note |
|---|---|---|---|
| `settlement` | any | `succeeded` | Final settlement |
| `capture` | `accept` | `succeeded` | Card capture success |
| `capture` | `challenge` | `processing` | Under manual fraud review |
| `pending` | any | `processing` | Awaiting customer fulfillment |
| `deny` | any | `failed` | Payment rejected |
| `cancel` | any | `cancelled` | Payment cancelled |
| `expire` | any | `failed` | Transaction window expired |
| `refund` | any | `refunded` | Total refund completed |
| `partial_refund` | any | `partially_refunded` | Partial refund completed |
| *(unknown / unrecognized)* | any | `processing` | Safe fallback; prevents false failure |

### 15.5 Webhook Security & Ingestion
- **Route**: `POST /api/v1/payments/webhooks/midtrans` (rate-limited via `throttle:60,1`).
- **Signature Algorithm**:
  $$\text{SHA512}(\text{order\_id} \parallel \text{status\_code} \parallel \text{gross\_amount} \parallel \text{ServerKey})$$
  Verified via timing-safe `hash_equals()`. Tampered or missing signatures reject with HTTP 400 (`WEBHOOK_SIGNATURE_INVALID`).
- **Idempotency Key**: Derived deterministically as `external_event_id = transaction_id-status_code-transaction_status`. Duplicate delivery returns HTTP 200 `duplicate_ignored`.
- **Cross-Tenant Guard**: Public webhooks ignore `X-Tenant-ID`. The tenant is determined server-side from resolved `Payment.tenant_id` and processed via `TenantManager::runForTenant()`.
- **Amount & Currency Validation**: Strict defense-in-depth verification ensures `gross_amount` matches `Payment.amount_minor` and `currency` matches `Payment.currency` (`IDR`), rejecting mismatches with HTTP 400.

### 15.6 Timeout, Failure & Status Inquiry Policy
- **Network Timeout & 5xx Gateway Errors**: Represent unknown outcomes. The payment remains in `Payment::STATUS_PROCESSING` with `response_code: 'NETWORK_TIMEOUT'` to prevent duplicate charging.
- **Dynamic Status Inquiry Identifier**: Exposes `POST /api/v1/payments/{payment}/sync-status` calling Midtrans `GET /v2/{identifier}/status`. The identifier is dynamically resolved:
  - Standard Core API (QRIS, Cards, Bank Transfer): `order_id = Payment.reference_number`.
  - BI SNAP / DANA e-wallet: Mandates `transaction_id = Payment.provider_payment_id`.
- **Status Inquiry Response Validation**: Does not blindly accept HTTP 200. Cross-validates:
  - `order_id`: Must match internal `reference_number`.
  - `gross_amount`: Must match internal `amount_minor`.
  - `currency`: Must match internal `currency` (`IDR`).
  Mismatches abort with `STATUS_QUERY_*_MISMATCH` without mutating payment state.
- **Monotonic Guards**: An already succeeded payment can never be demoted back to processing or failed.

### 15.7 Hardened Refund Lifecycle & Semantics
- **State Progression**: `pending` $\rightarrow$ `processing` $\rightarrow$ `completed` (or `failed`).
- **HTTP Acceptance vs Clearing**: Midtrans HTTP 200 acceptance sets `PaymentRefund.status = 'processing'`. It is only marked `completed` once `bank_confirmed_at` is confirmed via webhook or status inquiry.
- **Supported Methods**: QRIS (GoPay/ShopeePay acquirers), GoPay, ShopeePay, Credit Card.
- **Unsupported Methods**: Bank Transfer / Virtual Accounts (BCA, BNI, BRI, Mandiri, Permata). Refunds on VA reject immediately with stable code `PAYMENT_REFUND_NOT_SUPPORTED` and throw `PaymentRefundNotSupportedException`.
- **Webhook Mapping & Idempotency**: Refund notifications map to internal `PaymentRefund` by `refund_key = PaymentRefund.reference_number`. Incoming amount is strictly validated against `PaymentRefund.amount_minor`. Replays return `duplicate_ignored`.
- **Timeout Safety**: Timeouts during refund dispatch retain the existing `refund_key` and leave the refund in `processing` with `REFUND_NETWORK_TIMEOUT` without generating a duplicate key.
- **Immutability**: Original `Payment.amount_minor` remains strictly immutable during partial or full refunds.

### 15.8 Verification Matrix & Test Separation
- **Code Verified**: Automated Unit & Feature Test suite (34 feature tests in `MidtransPaymentProviderTest`, 243 total backend tests).
- **Real Sandbox Verified**: Verified via dedicated Artisan tool (`php artisan midtrans:verify-sandbox`).
- **Current Status**: `PASS WITH LIMITATIONS` (Explicitly: `SANDBOX NOT VERIFIED` due to placeholder keys in `.env`).

#### Midtrans Verification Matrix (Phase 1E.2)

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

## 16. Multi-Provider Payment Gateway & Duitku Integration (Phase 1E.3)

Phase 1E.3 expands SeWaroeng's payment infrastructure into a **multi-provider architecture** supporting both **Midtrans** and **Duitku**, backed by tenant-aware provider connections and platform-scoped Superadmin management.

### 16.1 Architecture Overview
- **Core Decoupling**: `PaymentService` remains completely provider-agnostic.
- **Provider Registry & Manager**: `PaymentProviderManager` manages provider registration (`manual`, `cash`, `mock`, `midtrans`, `duitku`) and resolves provider instances parameterized with tenant credentials via `resolveForTenant($provider, $tenantId)`.
- **Tenant Connection Model**: `tenant_payment_connections` table stores per-tenant gateway configurations:
  - Invariant: `UNIQUE (tenant_id, provider, environment)`.
  - Credentials encrypted at rest via Laravel `encrypted:array` cast (AES-256-CBC).
  - PostgreSQL Row Level Security (RLS) enabled and forced.
  - Masked presentation: `maskSecret()` displays `************ABCD`. Plaintext credentials are never exposed in logs, serialized models, or Flutter.

### 16.2 Platform Superadmin Management
- **Role Boundary**: Superadmin is a platform-scoped role (`users.is_superadmin = true`), not a tenant role.
- **Controlled Platform Context**: Requests to `/api/v1/platform/*` execute under `EnsurePlatformSuperadmin` middleware setting `app.is_platform_context = true`, bypassing tenant header switching and preventing RLS leaks.
- **Permissions**: `platform.payment_provider.view`, `platform.payment_provider.manage`, `platform.payment_provider.verify`, `platform.payment_provider.enable`, `platform.payment_provider.disable`.
- **Safe Verification**: `POST /api/v1/platform/tenants/{tenant}/payment-connections/{provider}/verify` verifies credentials using non-destructive metadata API queries without creating fake customer charges.

### 16.3 Duitku Provider Adapter (POP API)
- **Create Invoice**:
  - Sandox: `https://api-sandbox.duitku.com/api/merchant/createInvoice`
  - Production: `https://api-prod.duitku.com/api/merchant/createInvoice`
  - Headers: `x-duitku-signature` computed as `HMAC_SHA256(merchantCode + timestampMs, apiKey)`.
  - Maps `Payment.amount_minor` to `paymentAmount` (IDR) and `Payment.reference_number` to `merchantOrderId`.
- **Get Payment Method**:
  - Endpoint: `https://sandbox.duitku.com/webapi/api/merchant/paymentmethod/getpaymentmethod`
  - Signature: `HMAC_SHA256(merchantCode + amount + datetime, apiKey)`.
- **Webhook Callback**:
  - Route: `POST /api/v1/payments/webhooks/duitku`
  - Content-Type: `application/x-www-form-urlencoded`
  - Signature: `HMAC_SHA256(merchantCode + amount + merchantOrderId, apiKey)`.
  - Multi-tenant routing: Maps `merchantCode` to `TenantPaymentConnection`, runs within `TenantManager::runForTenant()`, validates amount/currency, and updates payment state idempotently.
- **Status Inquiry**:
  - Endpoint: `https://sandbox.duitku.com/webapi/api/merchant/transactionStatus`
  - Signature: `HMAC_SHA256(merchantCode + merchantOrderId, apiKey)`.
  - Status mapping: `00` = succeeded, `01` = pending, `02` = cancelled/failed.
- **Refund Limitation**:
  - Core Duitku POP payment adapter does not support automated debit refunds.
  - `supportsRefund()` returns `false`. `processRefund()` throws `PaymentRefundNotSupportedException` (`PAYMENT_REFUND_NOT_SUPPORTED`).

### 16.4 Provider Verification Matrix (Phase 1E.3 & 1E.3.1)

| Provider | Implementation | Unit & Feature Tests | Live Sandbox Status | Gate Status |
|---|---|---|---|---|
| **Midtrans** | `MidtransPaymentProvider` | 34 Tests Passed | NOT VERIFIED (No live sandbox credentials) | **CODE VERIFIED** |
| **Duitku** | `DuitkuPaymentProvider` | 13 Tests Passed | NOT VERIFIED (No live sandbox credentials) | **CODE VERIFIED** |

*Overall Gate Status*: **PASS WITH LIMITATIONS** (Code Verified, Sandbox Not Verified due to external credentials unavailability in local environment).

### 16.5 Multi-Provider Security Invariants (Phase 1E.3.1)
- **Code-Authoritative Adapter Registry**: Provider adapters are registered via `PaymentProviderManager::ALLOWLISTED_ADAPTERS` allowlist; database `adapter_class` is metadata and never dynamically instantiated.
- **Merchant Identifier Uniqueness**: PostgreSQL unique constraint `UNIQUE (provider, environment, merchant_identifier)` on `tenant_payment_connections` ensures deterministic webhook resolution.
- **Single Default Provider per Environment**: PostgreSQL partial unique index `UNIQUE (tenant_id, environment) WHERE is_default = TRUE` prevents concurrent double-defaults.
- **Credential Rotation Invalidation**: Credential, merchant identifier, or environment changes automatically demote verification status to `unverified` and unset `is_default`.
- **Centralized Provider Availability**: `PaymentProviderAvailabilityResolver` requires platform enabled + tenant connection exists + active + verified + environment match.
- **Fail-Closed Webhook Routing**: Unmatched merchant codes, environment mismatches, or cross-tenant payments fail closed with HTTP 400.

---

## 17. Cash Drawer & Shift Management (Phase 1F)

Phase 1F establishes production-grade shift lifecycle and physical cash tracking for F&B and retail counter operations.

### 17.1 Aggregate Boundaries & Decoupling
- **Unified Shift & Cash Drawer Session**: The `Shift` model acts as the aggregate authority for both the cashier's operational shift and the drawer session.
- **Decoupled Payment Domain**: `Payment` remains the authoritative source of payment status. Cash Drawer only tracks the operational cash impact:
  - Successful cash payments (`method = 'cash'`) trigger `ShiftService::recordSaleCashPayment()` atomically.
  - Non-cash payments (QRIS, VA, E-Wallet, Card) create **zero** cash movements.
  - Cash refunds create `CashMovement(type = 'refund', direction = 'out')`.
  - Non-cash refunds do not alter the cash drawer.
  - Duplicate recordings are prevented at the database level by `uq_cash_movements_reference` on `(shift_id, reference_type, reference_id)`.

### 17.2 Database Invariants & Security
- **One Open Shift per Outlet**: Enforced via PostgreSQL partial unique index:
  ```sql
  CREATE UNIQUE INDEX uq_shifts_active_outlet ON shifts (outlet_id) WHERE status = 'open';
  ```
- **State Invariants & Non-Negative Money**:
  - `chk_shifts_state_invariants`: Rejects `closed` status without `closed_at` and `closing_cash_minor`.
  - `chk_shifts_opening_cash` & `chk_shifts_closing_cash`: Enforce non-negative integer minor units (`BIGINT >= 0`).
  - `chk_cash_movements_amount`: Ensures `amount_minor > 0`.
- **Row-Level Security (RLS)**: Enabled and forced on `shifts` and `cash_movements` with `tenant_id` session verification.
- **Pessimistic Locking**: `lockForUpdate()` serializes concurrent mutations and closing attempts.

### 17.3 Immutable Cash Ledger
- `cash_movements` is append-only (no `updated_at`, no update/delete operations allowed).
- All discrepancies must be corrected using compensating `adjustment` movements.
- Types: `opening`, `sale`, `cash_in`, `cash_out`, `refund`, `adjustment`, `closing`.

### 17.4 Server Authority for Cash Summary
- **Expected Closing Formula**:
  $$\text{expected\_closing} = \text{opening} + \text{cash\_sales} + \text{cash\_in} - \text{cash\_out} - \text{cash\_refund} \pm \text{adjustments}$$
- **Variance Formula**:
  $$\text{variance} = \text{actual\_closing} - \text{expected\_closing}$$
- Client applications format numbers only; the backend is the sole authority for drawer reconciliation.

### 17.5 Online-Only Cash Management Policy
- POS sales continue to support offline outbox synchronization.
- Cash drawer mutations (`open`, `cash_in`, `cash_out`, `adjust`, `close`) require active online connectivity to prevent unsynchronized floats or duplicate withdrawals.

---

## 18. Basic Finance, Expense, Cash Flow & Basic P&L (Phase 1G)

Phase 1G establishes the double-entry accounting foundation, operational expense tracking, automated financial posting, and authoritative financial reporting.

### 18.1 Domain Boundary & Authority Chain

```text
Sale (POS Authority)
   ↓ (SaleFinalized)
Payment (Payment Authority)
   ↓ (PaymentSucceeded)
Financial Posting (Downstream Event Handler)
   ↓
Journal Entry (Finance Authority)
   ├── Cash (1110) / Bank / Payment Clearing (1120)
   ├── Sales Revenue (4100) / Tax Payable (2110)
   ├── COGS (5100)
   └── Inventory (1200)

Expense (Expense Authority)
   ↓
Journal Entry (Finance Authority)
   ├── Operating Expense (6100)
   └── Cash (1110) / Bank (1120)
```

- **Downstream Decoupling**: POS, Payment, and Inventory maintain absolute authority over operational transactions. Finance never mutates Sale or Payment statuses, nor does it recalculate inventory valuation.
- **Costing Authority**: COGS posting strictly pulls the authoritative captured cost from `SaleConsumption` (Moving Weighted Average), never calculating a secondary HPP inside Finance.

### 18.2 Accounting Model & Chart of Accounts

Each business tenant is initialized with a deterministic standard Chart of Accounts:
- `1110`: Cash (Asset)
- `1120`: Bank / Payment Clearing (Asset)
- `1200`: Inventory (Asset)
- `2110`: Tax Payable (Liability)
- `3100`: Owner Equity (Equity)
- `4100`: Sales Revenue (Revenue)
- `4110`: Sales Return / Contra Revenue (Revenue)
- `5100`: Cost of Goods Sold (Expense)
- `6100`: Operating Expense (Expense)

All financial figures are strictly stored as `BIGINT` minor units (integer Rupiah, e.g., Rp100.000 = `100000`). Float/double types are strictly forbidden.

### 18.3 Journal Invariants & Immutability

1. **Balance Invariant**: Every posted journal entry strictly enforces:
   $$\sum \text{Debit} = \sum \text{Credit}$$
   Imbalanced entries are rejected at the application level with `FinancialJournalImbalancedException` and prevented at the database schema level.
2. **Immutability of Ledger**: Posted journal entries and lines cannot be edited or deleted. Model-level lifecycle hooks (`updating` and `deleting`) abort mutations on posted journals.
3. **Reversal Architecture**: Corrections are executed exclusively through compensating reversal entries (`status = 'reversed'`), ensuring an indelible audit trail.

### 18.4 Downstream Automated Posting & Lifecycle Integrity

- **Canonical Source Identity**: Financial posting attaches directly to canonical business entities (`source_type = 'sale', source_id = sale.id`), never to transient technical events (`SaleFinalized`, `PaymentSucceeded`). Guarded by PostgreSQL unique index `uq_journal_entries_source_idempotent` on `(tenant_id, source_type, source_id) WHERE source_id IS NOT NULL AND status = 'posted'`.
- **Posting Preconditions**: Both `SaleFinalized` and `PaymentSucceeded` route to `FinanceService::postSale(Sale $sale)`. A sale is posted if and only if it is finalized (`isFinalized()`) AND fully settled (`paid_minor >= total_minor`).
- **Reverse Arrival Safety**: If payment succeeds while the sale remains unfinalized, posting is deferred until `SaleFinalized` triggers. If finalization occurs before settlement, posting is deferred until the final payment succeeds.
- **Split Payment Allocation**: Debits are distributed across Cash (`1110`) and Bank/Payment Clearing (`1120`) in exact proportions to the payment methods, balanced against Sales Revenue (`4100`).
- **COGS Posting**: Reads authoritative `SaleConsumption` records, debiting COGS (`5100`) and crediting Inventory (`1200`) under canonical identity `source_type = 'sale_cogs', source_id = sale.id`.
- **Refund Posting**: Triggered upon `PaymentRefunded`. Debits Sales Return (`4110`) and credits Cash/Clearing (`source_type = 'payment_refund', source_id = refund.id`). Replays are strictly idempotent.

### 18.5 Operational Expense Domain

- Tracks operational expenses per outlet or business-wide across standard categories: `utilities`, `supplies`, `rent`, `salary`, `maintenance`, `marketing`, `other`.
- Posted expenses atomically create a balanced double-entry journal entry (`Dr 6100 Operating Expense`, `Cr 1110 Cash / 1120 Bank`).
- Reversals require an audit reason, transition the expense to `reversed`, and generate an inverse compensating journal entry.

### 18.6 Cash Flow vs P&L Integrity & CashMovement Reconciliation

- **Profit & Loss (P&L)**:
  $$\text{Net Revenue} = \text{Gross Revenue} - \text{Sales Returns}$$
  $$\text{Gross Profit} = \text{Net Revenue} - \text{COGS}$$
  $$\text{Net Profit} = \text{Gross Profit} - \text{Operating Expenses}$$
- **Cash Flow (Physical Drawer Cash)**:
  - Strictly derives from the physical `Cash` account (`1110`).
  - Digital gateway / clearing payments are explicitly excluded from physical cash flow.
  $$\text{Net Cashflow} = \text{Cash In} - \text{Cash Out}$$
- **CashMovement ↔ Finance Cash Reconciliation**:
  - `CashMovement::TYPE_CASH_IN` generates `Dr 1110 (Cash)` / `Cr 3100 (Owner Equity)`. It increases drawer cash and finance cashflow (`other_cash_in_minor`) without inflating P&L sales revenue.
  - `CashMovement::TYPE_CASH_OUT` generates `Dr 3100 (Owner Equity)` / `Cr 1110 (Cash)`. It decreases drawer cash and finance cashflow (`other_cash_out_minor`) without inflating P&L operating expenses.
  - `CashMovement::TYPE_OPENING` & `TYPE_CLOSING` represent administrative drawer bounds and do not post to the general ledger.
  - Invariant: `Shift Closing Cash == Shift Opening Float + Finance Net Cashflow`.

### 18.7 Security & Isolation

- **PostgreSQL RLS**: Forced Row-Level Security on `accounts`, `journal_entries`, `journal_lines`, and `expenses`.
- **RBAC**: Finance operations require `finance.expense.*` and `finance.report.view`. Cashiers are restricted from financial ledgers and P&L reports.
- **Idempotency**: All expense mutation endpoints mandate `Idempotency-Key`.

---

## 19. Dashboard & Notifications Domain (Phase 1H)

Phase 1H establishes the operational visibility layer for merchants, comprising a role-aware read-only Dashboard and an idempotent Notification and Operational Alert engine.

### 19.1 Dashboard Architecture — Read-Model Only Principle

```text
Transactional Aggregates
(Sales, Payments, Inventory, Shift, Finance)
                 ↓
    Dashboard Query / Read Model
        (Authoritative Queries)
                 ↓
       GET /api/v1/dashboard
                 ↓
      Flutter Dashboard Screen
```

- **Read-Model Only**: The Dashboard is strictly an aggregation view and never acts as a business source of truth. It never mutates transactions, never finalizes sales, never alters payments, never posts journal entries, and never modifies inventory or shifts.
- **Authoritative Data Sources**:
  - `gross_sales_minor`, `net_sales_minor`, `transaction_count`: Derived from finalized, non-cancelled sales.
  - `average_order_value_minor`: Integer-safe division (`net_sales / transaction_count`), returning 0 if `transaction_count == 0`. Zero floating-point arithmetic.
  - `gross_profit_minor` & `cogs_minor`: Consumes authoritative finance P&L computation (`FinanceService::getProfitLoss`). The dashboard never recalculates COGS independently.
  - `net_cashflow_minor`: Consumes authoritative finance Cash Flow computation (`FinanceService::getCashFlow`). Distinguishes physical cash from digital clearing.
  - `top_products`: Aggregates finalized sale items sorted by total sold quantities and revenue.
  - `low_stock`: Directly reads active `inventory_item_locations` where `on_hand <= reorder_level` and `reorder_level > 0`.
  - `top_customers`: Ranks registered customers by frequency and spend from finalized sales; walk-in sales are excluded.
  - `payment_summary`: Groups operational payments by state machine status (`succeeded`, `processing`, `pending`, `failed`, `refunded`, `partially_refunded`).
  - `outlet_summary`: Provides per-outlet breakdown for multi-outlet users.

### 19.2 Role-Aware Visibility & Authorization

- **Owner**: Complete business-wide operational and financial visibility across all authorized outlets (`dashboard.view`, `dashboard.view.finance`).
- **Manager**: Operational metrics for their assigned outlet(s). Finance metrics visible only if granted `dashboard.view.finance`. Unauthorized outlet scopes fail closed with HTTP 403.
- **Accountant**: Authorized financial views based on finance permissions.
- **Cashier**: Restricted POS-first role. Attempting to view the general business dashboard without `dashboard.view` returns HTTP 403 Forbidden.

### 19.3 Tenant Isolation & Outlet Scope

- Tenant isolation is strictly enforced via `TenantContext` and PostgreSQL Row-Level Security (RLS).
- Outlet parameters (`outlet_id`) are validated against the user's assigned outlet list (`AuthorizeOutletAccess`). A user cannot query metrics for an outlet they are not authorized to access.
- Cross-tenant dashboard or notification reads fail closed with HTTP 403.

### 19.4 Business Timezone Determinism

- Period boundaries (`today`, `yesterday`, `7_days`, `30_days`, `custom`) are computed using the tenant's configured timezone (`business.timezone`, default `Asia/Jakarta`).
- "Hari Ini" deterministically represents `00:00:00` to `23:59:59` in the merchant's business timezone, avoiding server or client device timezone skew.

### 19.5 Cash-In / Cash-Out Semantic Safety

- Following Phase 1G.1 accounting reconciliation, `cash_in` and `cash_out` are Owner Equity movements.
- The dashboard preserves neutral operational labeling ("Arus Kas Bersih", "Penerimaan Kas", "Pengeluaran Kas"), never conflating Owner Equity cash injections with sales revenue, or owner drawings with operating expenses.

### 19.6 Notifications & Operational Alerts Domain (Phase 1H Hardened)

- **Model & Schema (`notifications`)**:
  - Columns: `id UUIDv7`, `tenant_id`, `recipient_user_id` (nullable for tenant-wide alerts), `type`, `title`, `message`, `severity` (`info`, `warning`, `critical`), `dedup_key` (nullable), `data` (JSONB), `read_at`, `resolved_at`, `created_at`, `updated_at`.
  - Enforces PostgreSQL Row-Level Security (RLS).
- **Core Notification Types**:
  - `LOW_STOCK` (`warning`): Emitted when inventory drops to or below `reorder_level` at a specific authoritative location.
  - `SHIFT_VARIANCE` (`warning`): Emitted when a shift closes with a non-zero cash variance. Uses neutral phrasing ("Perbedaan kas perlu ditinjau").
  - `PAYMENT_STATUS` (`critical` / `warning` / `info`): Server-authoritative status notifications (`failed`, `refunded`, `processing`, `pending`) with structured JSON data payload.
- **Notification Lifecycle & Active Deduplication Invariant**:
  - Active alerts are defined by `resolved_at IS NULL`.
  - Enforced at database level via partial unique index:
    ```sql
    CREATE UNIQUE INDEX uq_notifications_tenant_dedup_active
    ON notifications (tenant_id, dedup_key)
    WHERE dedup_key IS NOT NULL AND resolved_at IS NULL;
    ```
  - Format: `low_stock:{item_id}:{outlet_id}`, `shift_variance:{shift_id}`, `payment_status:{payment_id}:{status}`.
  - Dedup applies strictly to active notifications. When inventory recovers above reorder level, the notification is marked `resolved_at = NOW()`. If stock subsequently drops again, a new active notification is created with full historical auditability.
  - Worker retries or replayed domain events (`x3`) execute idempotently without producing duplicate notifications.
- **API Endpoints**:
  - `GET /api/v1/notifications`: Paginated notification list with filters (`unread_only`, `type`).
  - `GET /api/v1/notifications/unread-count`: Fast index-backed count of unread alerts.
  - `POST /api/v1/notifications/{id}/read`: Mark specific notification as read.
  - `POST /api/v1/notifications/read-all`: Bulk mark all unread notifications as read.

---

## 20. Phase 1I — Offline POS & Synchronization Engine

### 20.1 Architectural Overview

```text
ONLINE / CONNECTED
       ↓
Fetch & Cache POS Catalog / Prices / Customers
(Drift SQLite local snapshot tables)
       ↓
NETWORK DISCONNECTED (OFFLINE)
       ↓
POS Cart Selection → Cashier Finalizes Sale (Cash/Manual Only)
       ↓
Local Atomic SQLite Transaction:
  1. Insert LocalSale (Client UUIDv7)
  2. Insert LocalSaleItems (Client UUIDv7)
  3. Insert SyncOutboxTable operation (Unique Operation UUIDv7 & Idempotency-Key)
       ↓
User Continues Selling Uninterrupted
       ↓
NETWORK RESTORED / MANUAL SYNC
       ↓
SyncWorker reads pending outbox commands (FIFO, batch up to 20)
       ↓
POST /api/v1/sync (Header: X-Tenant-ID, Bearer token)
       ↓
Server SyncService (Atomic Ingestion Pipeline):
  1. Idempotency check via idempotency_records
  2. Causal dependency check (causation_id)
  3. Tenant & outlet authorization validation
  4. Server-opened shift validity check
  5. Offline payment method restriction (cash/manual only)
  6. Atomic PosService finalization (Server authoritative ledger)
  7. Payment & Shift Cash Movement recording
  8. Official Receipt generation & Numbering
  9. Double-entry Finance posting (Sales revenue, Cash, Inventory, COGS)
       ↓
HTTP 200 Response with deterministic result array:
  [ { operation_id, status: 'accepted'|'already_processed'|'blocked'|'retryable', code, message } ]
       ↓
Flutter Client Processes Results:
  - 'accepted' / 'already_processed': Mark outbox synced, update local sale to 'synced' with synced_at.
  - 'blocked': Mark outbox 'requires_action', update local sale to 'needsReview' with error code (Sale is NEVER deleted).
  - Network 5xx / timeout: Mark outbox 'failed' with exponential retry backoff.
```

### 20.2 Key Invariants & Architectural Rules

1. **Transactional Outbox Guarantee**:
   - The creation of `LocalSale`, `LocalSaleItems`, and `SyncOutboxTable` operation occurs within a single local SQLite transaction.
   - An offline sale never exists without its corresponding outbox record, and an outbox record never exists without its corresponding local sale.
2. **Identifier & Idempotency Strategy**:
   - Client generates UUIDv7 for offline entities (`sale.id != outbox_operation.id != idempotency_key`).
   - Server respects client-generated UUIDv7 `sale.id` as primary key while generating authoritative `reference_number` and `receipt_number`.
   - Idempotency records prevent double finalization across network retries, timeouts, and replays (`x2`, `x3`).
3. **Offline Payment Boundaries**:
   - Only cash and manual settlement are permitted while offline.
   - Digital payment gateways (Midtrans, Duitku, QRIS, Virtual Accounts) are strictly prohibited and blocked offline. No mock or fake online payment successes are permitted.
4. **Authoritative Server Invariants**:
   - **Inventory**: Local stock is for UI guidance only. Server inventory ledger evaluates on-hand availability during sync. Insufficient stock returns `blocked` with code `INSUFFICIENT_STOCK` without mutating inventory or deleting the local sale.
   - **Shift**: Offline sales must reference a valid server-opened shift snapshot. Sales against closed shifts return `blocked` with code `SHIFT_NOT_AVAILABLE`.
   - **Finance**: Synced sales generate double-entry financial journals (Revenue, Cash, Inventory, COGS) using existing authoritative `FinanceService`. Retries produce zero duplicate journals or cash drawer movements.
   - **Receipt**: Local sales hold provisional local reference numbers. Upon successful sync, authoritative server receipt numbers are persisted.
5. **Conflict & Error UX States**:
   - Local transactions are presented with explicit synchronization badges:
     - `Tersimpan Lokal` (`localSaved` / `syncPending`)
     - `Sedang Sinkronisasi` (`syncing`)
     - `Tersinkronisasi` (`synced`)
     - `Perlu Ditinjau` (`needsReview`)
     - `Gagal Sinkronisasi` (`failed`)
   - Failed or blocked transactions are never silently pruned or overwritten. The cashier/manager is informed of the explicit reason (e.g., `INSUFFICIENT_STOCK`, `SHIFT_NOT_AVAILABLE`).

---

## 21. Subscription Foundation, Entitlements & Quotas (Phase 1J)

Per `docs/adr/0012-subscription-and-entitlement.md` and PRD Sections 35 & 61:

### 21.1 Plan Catalog Architecture
- **Canonical Tiers**:
  - `Free / Trial` (`free`): Micro-business and 14-day evaluation tier with multi-branch evaluation (quota: 5 outlets, 5 users, 100 products, 500 monthly transactions).
  - `Starter` (`starter`): Single-outlet retail tier (quota: 1 outlet, 5 users, 500 products, 2000 monthly transactions).
  - `Business` (`business`): Full-featured single-outlet tier with advanced reports, WhatsApp, AI copilot, unlimited catalog and transactions.
  - `Multi-Outlet` (`multi_outlet`): Enterprise multi-branch tier with 10+ outlets, 50 users, unlimited catalog and transactions.
  - `Add-ons` (`add_ons`): Declarative add-on modules (`addon.whatsapp`, `addon.ai_copilot`, `addon.additional_outlet`, `addon.additional_staff`, `addon.advanced_reports`).
- **Platform Reference Isolation**: `subscription_plans`, `subscription_plan_entitlements`, and `subscription_add_ons` are platform-scoped reference tables without tenant RLS.
- **Tenant Isolation**: `subscriptions`, `subscription_events`, and `subscription_add_on_assignments` are tenant-scoped with forced PostgreSQL Row-Level Security (RLS) policies.

### 21.2 Separation of Platform Billing from Tenant Gateways
- **Boundary Contract**: Platform subscription billing (`SubscriptionBillingProviderInterface`) is strictly decoupled from merchant payment gateways (`TenantPaymentConnection` Midtrans/Duitku). Merchant credentials are never touched for platform charges.
- **Provider Abstraction**: Default implementation uses `ManualSubscriptionBillingProvider`, extensible for future automated recurring billing engines without impacting merchant gateway adapters.

### 21.3 Centralized Entitlement & Quota Resolution
- **Resolution Services**:
  - `SubscriptionEntitlementService`: Centralized resolver for `can()`, `limit()`, `usage()`, `remaining()`, `assertCan()`, `assertQuotaAvailable()`.
  - `SubscriptionUsageService`: Computes authoritative counts for active outlets, users, products, and monthly finalized sales.
- **Concurrency Protection**: Quota evaluations during resource creation (e.g., outlets and products) execute inside database transactions protected by pessimistic row locks (`SELECT ... FOR UPDATE` on `tenants`), preventing race conditions when scarce quota slots remain.
- **Non-Destructive Downgrades**: Downgrading a subscription never mutates or soft-deletes existing business records. Over-quota tenants retain existing resources while new creations are blocked until resolved.
- **Offline Clients**: Flutter clients consume server-authoritative subscription metadata and quota telemetry for UI display. Offline mutations cannot alter subscription authority or bypass server-side quota verification upon sync.

---

## 22. Table Management, Booking Domain, and QR Table Ordering (V1)

Per `docs/adr/0013-booking-table-and-qr-ordering.md` and PRD Sections 30 & 31:

### 22.1 Operational Domain Boundaries & Anti-Corruption
- **Transaction Authority Invariant**: The `Booking` and `Order` domains are strictly operational ordering layers above the established POS and Financial cores. Neither domain is a financial source of truth or revenue register.
- **No Direct Financial/Inventory Mutations**: Booking creation, cancellation, order submission, or preparation do NOT generate double-entry journal vouchers or deduct raw inventory.
- **Authoritative Conversion Pipeline**:
  ```text
  Table / Booking ──> Reservation / Table Session ──> QR Order Submission ──> Staff Acceptance ──> PosService::createSale ──> PaymentService::settlePayment ──> FinanceService (Double-Entry Journal) + Inventory Deduction + ReceiptService
  ```

### 22.2 Table Lifecycle & Physical Occupancy Sessions
- **Normalized Tables**: `tables`, `bookings`, `table_sessions`, `orders`, and `order_items`.
- **Table States**: `available`, `occupied`, `reserved`, `inactive`. Managed strictly by server-side state machines.
- **Table Session**: Dedicated `table_sessions` track physical dwell time and table occupancy (`opened_at`, `closed_at`, `guest_count`) without hijacking or mutating `Sale.status`. Multiple operational orders across a dining visit aggregate into the active session.

### 22.3 Booking Lifecycle & Concurrency Overlap Protection
- **Booking States**: `pending`, `confirmed`, `checked_in`, `cancelled`, `completed`, `no_show`.
- **Concurrency & Overlap Guard**: Invariant enforced via transaction-scoped pessimistic locking (`SELECT ... FOR UPDATE` on `tables`) and transactional overlap validation ($S_{req} < E_{exist} \land E_{req} > S_{exist}$). Overlapping reservations are deterministically rejected with HTTP 422 (`BOOKING_OVERLAP`). Adjacent bookings are permitted.

### 22.4 Public QR Ordering Security, Token Hashing & Server Authority
- **One-Way Token Hashing**: Tables store strictly `qr_token_hash = sha256(raw_token)` under unique index `tables_qr_token_hash_unique`. Plaintext bearer tokens are transient in memory and never persisted or exposed via model serialization (`$hidden = ['qr_token_hash']`).
- **Zero Client Price Authority**: QR order requests provide only variant IDs, quantities, and modifier IDs. All unit prices, tax, and order totals are authoritatively calculated server-side using active outlet price lists. Client financial amounts are strictly discarded.
- **Dual-Layer Multi-Tenant Isolation**: Tables, bookings, sessions, orders, and items are guarded by Layer 1 (Eloquent `TenantScope`) and Layer 2 (PostgreSQL RLS). Public endpoints resolve tenant context via token hash lookup and immediately execute within `TenantManager::runForTenant()`.
- **Customer Web Surface & Real-Time Tracking**: Served via mobile-first web interface (`/order/{token}`) with live status polling (`GET /api/v1/public/tables/{token}/orders/{orderNumber}`) exposing customer-safe status labels (`Diterima`, `Sedang Disiapkan`, `Siap Disajikan`, `Selesai`, `Dibatalkan`) without leaking internal identifiers.
- **Rate Limiting & Abuse Prevention**: Public routes apply granular rate limiters (`throttle:60,1` on reads and status tracking, `throttle:30,1` on order creation) alongside client-side UUID idempotency keys to protect against double-submission and abuse.

---

## 23. Loyalty Program & Immutable Point Ledger Foundation (Phase 1K / V1 Loyalty)

Per `docs/adr/0014-loyalty-program-and-point-ledger.md` and PRD Sections 15 & 16:

### 23.1 Domain Invariant: Immutable Point Ledger
- **Append-Only Source of Truth**: Point balances are strictly backed by immutable ledger rows in `loyalty_point_ledgers`. Overwriting `current_balance` directly without a corresponding ledger entry is strictly prohibited.
- **ORM-Level Immutability**: `LoyaltyPointLedger` throws `RuntimeException` on `updating` and `deleting` model lifecycle events. Compensating adjustment entries (`type = 'adjustment'`, `'reversal'`) are mandatory for balance corrections.
- **Reconciliation Invariant**: `account.current_balance === SUM(posted ledger points)`. Mismatches are audited via `LoyaltyService::reconcileAccountBalance()`.

### 23.2 Server-Authoritative Point Earning & Idempotency
- **Downstream Earning Event**: Points are earned downstream when a Sale transitions to `finalized` AND payment settlement reaches `paid`.
- **Server Price Calculation**: Client cannot supply earned points. Points are calculated server-side using `paid_amount_minor`, `earn_unit_amount_minor`, and active tier multipliers (`multiplier_bps`).
- **Composite Idempotency**: Database partial unique index `uq_loyalty_point_ledgers_sale_earn` on `(tenant_id, reference_type, reference_id, type)` guarantees that duplicate event deliveries or sync replays cannot award duplicate points.

### 23.3 Atomic Redemption & Concurrency Safety
- **Atomic Balance Deduction**: Redemptions lock `CustomerLoyaltyAccount` using `lockForUpdate()`. If `current_balance < reward.points_cost`, the transaction aborts with HTTP 422 `INSUFFICIENT_LOYALTY_POINTS`.
- **Voucher Minting**: Atomic debit inserts a negative ledger row (`type = 'redemption'`) and issues a unique `LoyaltyVoucher` with unguessable code.
- **POS Discount Authority**: POS requests apply voucher codes via `POST /api/v1/pos/sales/{id}/apply-voucher`. The server validates voucher status and computes the discount amount, preventing client tampering with sale totals.

### 23.4 Subscription Entitlement & Tenancy Isolation
- **Feature Key**: Enforced via `loyalty` entitlement check in `LoyaltyService::assertLoyaltyEntitled()`.
- **Tenancy**: All tables (`loyalty_programs`, `loyalty_tiers`, `customer_loyalty_accounts`, `loyalty_point_ledgers`, `loyalty_rewards`, `loyalty_vouchers`) enforce PostgreSQL Row-Level Security (RLS) and Eloquent `TenantScope`.
- **Customer 360 Extension**: Existing `Customer` aggregate extends summary telemetry with loyalty tier name, points balance, lifetime earnings, and active voucher count without duplicating customer data.

### 23.5 Canonical Voucher State Lifecycle & Payment Safety
- **State Model**: `active` (issued) $\rightarrow$ `reserved` (attached to open sale) $\rightarrow$ `used` (consumed strictly upon verified settlement).
- **Payment Failure Safety**: Voucher finalization does not mark vouchers as permanently consumed unless the transaction is settled. When a payment fails or sale is cancelled, reservation is released (`status` reverts to `active`, `used_sale_id = null`), preserving customer assets.
- **Voucher Removal**: POS supports `DELETE /api/v1/pos/sales/{id}/voucher` to detach the discount, recalculate subtotal, and release reservation atomically.
- **Refund Invariant**: When a settled sale is refunded, the financial amount is returned, but the promotional discount voucher remains consumed (`used`), preventing infinite redemption exploitation.

### 23.6 V1 Loyalty Core Scope Boundaries
- **Implemented**: Points calculation and ledger immutability, tiers with basis point multipliers, rewards catalog, concurrency-safe redemption, canonical voucher lifecycle, and full Customer 360 Flutter UI.
- **Deferred**: Scheduled expiration background worker (configuration supported, worker deferred), campaign engine, coupon codes, birthday/anniversary automation, referrals, and cashback-like promotions.

---

## 24. Payment Reconciliation & Settlement Verification Architecture

### 24.1 Separation of Authorities & Domain Hierarchy
Reconciliation is strictly a verification and exception management subsystem; it is never a primary domain authority for payments, sales, or financial postings:
```text
Sale (POS Authority)
   ↓
Payment / PaymentAttempt (Payment Gateway Authority)
   ↓
Settlement (Provider Webhook / Callback Verification)
   ↓
Reconciliation (Audit, Verification & Exception Matching)
   ↓
Finance / Double-Entry Journal (Accounting Truth)
```
- **Sale**: Canonical authority for basket composition, discounts, and total amount due.
- **Payment**: Canonical authority for payment lifecycle (`pending`, `settled`, `failed`, `refunded`). Reconciling an evidence record never directly marks a payment as `paid`; state transitions remain governed strictly by `PaymentService`.
- **Reconciliation**: Verifies parity between external payment gateway reports (statements, delayed webhooks, batch files) and internal payment attempts.
- **Finance**: Double-entry journal entries (`journal_entries`, `journal_lines`) represent immutable accounting truth. Reconciliation never duplicates journal postings or silently alters posted historical ledgers.

### 24.2 Normalized Provider Evidence (`ProviderEvidence` DTO)
Reconciliation operates exclusively on a provider-agnostic representation:
- **Properties**: `provider`, `providerReference`, `internalReference`, `amountMinor`, `feeMinor`, `currency` (ISO-3), `channel`, `settlementStatus`, `occurredAt`, `rawMetadata`.
- Core reconciliation logic is free from provider-specific branching (`if ($provider === 'midtrans')`). Provider adapters perform normalization before evidence is ingested.

### 24.3 Deterministic Matching Cascade
Matching external settlement evidence against internal payments follows an unambiguous priority sequence:
1. **Direct Gateway Reference**: Direct match on `PaymentAttempt.provider_payment_id` or `Payment.provider_payment_id`.
2. **Correlation Reference**: Match on `PaymentAttempt.provider_reference` or `Payment.reference`.
3. **Internal UUID Match**: Correlates against `Payment.id` or `PaymentRefund.id`.
4. **Amount & Currency Strict Validation**: Exact match between expected and provider-reported minor amounts and ISO currency code. Currency mismatch flags `CURRENCY_MISMATCH`; amount variance flags `AMOUNT_MISMATCH`.
5. **Tenant & Outlet Boundaries**: Verification that matching records belong strictly to the authenticated tenant and assigned outlet scope.
6. **Lifecycle Consistency**: Inconsistencies between external settlement and internal status flag `STATUS_CONFLICT`.

### 24.4 Reconciliation State Machine & Discrepancy Resolution
- **States**: `unmatched`, `matched`, `mismatched`, `needs_review`, `resolved`.
- **Resolution**: Administrative resolution of `mismatched` or `needs_review` records requires explicit resolution notes, recorded timestamp, authenticated resolver user ID, and an immutable entry in `audit_logs`.

### 24.5 Idempotency & Tenant Scoping
- **Unique Constraint**: `UNIQUE (tenant_id, provider, provider_reference)` guarantees that duplicate webhook callbacks or repeated batch statement uploads execute idempotently without duplicating records.
- **PostgreSQL Row Level Security (RLS)**: Enforced on `payment_reconciliations` with composite foreign keys enforcing tenant and outlet boundaries.
- **Server Authority**: Authority resolution is strictly server-authoritative (`TenantContext`); client headers (`X-Tenant-ID`, `outlet_id`) are never trusted without server validation.

### 24.6 Offline Boundary & Sync Invariant
- **Cash / Manual Only**: Offline POS terminals are restricted to offline payment methods (cash, manual).
- **No Spoofed Digital Settlements**: Sync operations cannot create settled external digital transactions or bypass payment gateway reconciliation. All external gateway reconciliations are executed purely on the backend against authoritative provider evidence.

---

## 25. V1 Advanced Financial Reporting & Canonical Double-Entry Read Model

### 25.1 Canonical Financial Source of Truth
Financial reporting in SeWaroeng is strictly derived from canonical double-entry accounting records (`journal_entries`, `journal_lines`). Reports never calculate financial totals directly from raw `sales`, `payments`, or `inventory_movements` tables independently.
- **Posting Pipeline**:
  ```text
  POS / Payment / Inventory / Expense / Refund
                  ↓
         Canonical Finance Posting
                  ↓
         Double-Entry Journal (Balanced Dr === Cr)
                  ↓
         Financial Read Model & Queries
                  ↓
         Authoritative Financial Reports
  ```
- **Chart of Accounts (COA)**:
  - `1110` Kas / Cash (Asset, Normal Balance: Debit)
  - `1120` Kliring Pembayaran / Bank (Asset, Normal Balance: Debit)
  - `1200` Persediaan Barang / Inventory (Asset, Normal Balance: Debit)
  - `2110` Utang Pajak / Tax Payable PB1 (Liability, Normal Balance: Credit)
  - `3100` Modal Pemilik / Owner Equity (Equity, Normal Balance: Credit)
  - `4100` Pendapatan Penjualan / Sales Revenue (Revenue, Normal Balance: Credit)
  - `4110` Retur Penjualan / Contra Revenue (Revenue Contra, Normal Balance: Debit)
  - `5100` Beban Pokok Pendapatan / COGS (Expense, Normal Balance: Debit)
  - `6100` Beban Operasional / Operating Expense (Expense, Normal Balance: Debit)

### 25.2 Authoritative Report Semantics & Mathematical Formulas
1. **Profit & Loss (P&L)**:
   - **Gross Revenue**: `SUM(4100 Credit - Debit)`
   - **Sales Returns / Contra Revenue**: `SUM(4110 Debit - Credit)`
   - **Net Revenue**: `Gross Revenue - Sales Returns`
   - **COGS**: `SUM(5100 Debit - Credit)` (captured historically at the moment of sale/inventory movement; never recalculated from current average cost)
   - **Gross Profit**: `Net Revenue - COGS`
   - **Gross Margin %**: `Net Revenue > 0 ? (Gross Profit / Net Revenue) * 100 : 0.0`
   - **Operating Expense**: `SUM(6100 Debit - Credit)` from posted expenses
   - **Net Profit**: `Gross Profit - Operating Expense`
   - **Net Margin %**: `Net Revenue > 0 ? (Net Profit / Net Revenue) * 100 : 0.0`
   - **Period Comparison**: When `compare=true`, computes equivalent prior period boundaries and calculates deterministic `revenue_growth_pct` and `net_profit_growth_pct`.

2. **Balance Sheet (Neraca Keuangan)**:
   - Evaluated as of a deterministic date (`as_of_date`):
     - **Total Assets**: Evaluates all accounts where `type = 'asset'` (`SUM(Debit - Credit)`). Includes standard accounts (Cash 1110, Bank Clearing 1120, Inventory 1200) and any custom tenant asset accounts.
     - **Total Liabilities**: Evaluates all accounts where `type = 'liability'` (`SUM(Credit - Debit)`). Includes Tax Payable 2200, Accounts Payable 2100, and any custom liability accounts.
     - **Total Equity**: `Owner Equity (type = 'equity') + Retained Earnings`.
     - **Retained Earnings / Cumulative Net Earnings**: Independently computed from posted income statement movements as `Cumulative Revenue (Net) - Cumulative Expense (Net)`. It is **strictly NOT an algebraic balancing plug** (`Assets - Liabilities - Equity`).
   - **Accounting Invariant**: `Total Assets === Total Liabilities + Total Equity`. Discrepancy is guaranteed to be 0 minor across all posted double-entry journals.

3. **Trial Balance (Neraca Saldo)**:
   - Evaluates all active system accounts up to `as_of_date`.
   - Normal balance classification assigns net ending balances:
     - Assets & Expenses: Net Debit = `Debit - Credit >= 0 ? (Debit - Credit) : 0`, Net Credit = `Debit - Credit < 0 ? abs(Debit - Credit) : 0`
     - Liabilities, Equity, Revenue: Net Credit = `Credit - Debit >= 0 ? (Credit - Debit) : 0`, Net Debit = `Credit - Debit < 0 ? abs(Credit - Debit) : 0`
   - Unposted draft entries are strictly excluded from trial balance aggregations.
   - **Invariant**: `SUM(Ending Debit) === SUM(Ending Credit)`.

4. **Account Ledger (Buku Besar)**:
   - Chronological running balance per account:
     - **Opening Balance**: Accumulated normal balance prior to `start_date`.
     - **Period Movements**: Ordered by `entry_date ASC, created_at ASC, id ASC`.
     - **Running Balance**: Cumulatively updated per line according to account normal balance type.
     - **Closing Balance**: `Opening Balance + Net Period Movements`.

5. **Cash Flow Semantics (Arus Kas)**:
   - Strictly evaluates physical cash (`1110 Kas Fisik`) ledger movements.
   - PRD and ADR 0011 specify that digital payment settlements (QRIS, Debit, Transfer) land in `1120 Kliring Pembayaran / Bank` and do not constitute physical cash float in drawer.
   - Features opening cash float (`opening_cash_minor`), breakdown of inflows and outflows by domain source, net cash flow, and closing cash float (`closing_cash_minor`).
   - Invariant: `Opening Cash + Net Cashflow === Closing Cash`.

6. **Payment Clearing & Reconciliation Overlay**:
   - Integrates Account `1120` ledger reality (non-cash inflows debited, settlement refunds credited) with the external verification state machine from `PaymentReconciliation` (`matched`, `mismatched`, `unresolved`, `settled`, `pending`).
   - Ensures strict separation: external payment reconciliation is an independent verification layer that never alters historical double-entry ledger truth. Reconciliation mismatches or duplicate webhooks never create duplicate or corrupting journal entries.

### 25.3 Timezone Semantics & Deterministic Date Boundaries
- Date filtering respects tenant timezone (default `Asia/Jakarta`).
- Date ranges (`today`, `this_week`, `this_month`, custom range) compute deterministic start-of-day and end-of-day bounds in local tenant timezone before querying UTC timestamps or comparing against `journal_entries.entry_date` (which is stored as date `YYYY-MM-DD`).
- Day boundary: 23:59:59 local is classified into date D; 00:00:00 next day is classified into D+1, immune to server UTC date crossing.

### 25.4 Multi-Outlet Scoping & Tenant Security
- **Owner Role**: Authorized to query tenant-wide aggregations or filter by specific outlet. Forged outlet IDs (belonging to another tenant or non-existent) are rejected with `403 OUTLET_ACCESS_DENIED`.
- **Manager Role**: Restricted strictly to assigned outlet IDs (`membership.assigned_outlet_ids`). Queries for unassigned or cross-tenant outlets are rejected with `403 OUTLET_ACCESS_DENIED`.
- **Cashier Role**: Strictly denied access to financial reporting endpoints (`finance.report.view` permission check).
- **PostgreSQL RLS**: All queries enforce `tenant_id` at the database session level. Client-supplied tenant IDs or outlet IDs are never trusted without server-authoritative validation.

### 25.5 Immutability & Reversal Semantics
- **Immutability Enforcement**:
  - Posted journal entries reject Eloquent `update` and `delete` hooks.
  - Journal lines belonging to posted or reversed entries reject all updates and deletions.
  - Status mutation is restricted exclusively from `posted` to `reversed`. Attempting to transition to `draft` or any other status throws `DomainException`.
- **Reversal Semantics**:
  - `FinanceService::reverseJournalEntry` executes within a database transaction with pessimistic locking (`lockForUpdate()`) to prevent race conditions or concurrent duplicate reversals.
  - Reversal lines are strictly inverted: original Debits become Credits, original Credits become Debits.
  - Bidirectional audit linkage: original entry sets `reversal_of_id` pointing to the reversal entry; reversal entry sets `reversal_of_id` pointing to the original entry.
  - Reversing a compensating reversal entry is strictly forbidden to preserve an unambiguous audit trail.

### 25.6 Performance Benchmark & Query Plan Audit
- **Development Benchmark**:
  - Representative dataset: 100 posted journal entries with 200 journal lines across multiple outlets.
  - Benchmark Results (local environment):
    - P&L Query Execution: ~2.1 ms (target: < 500 ms) — **PASS**
    - Balance Sheet Query Execution: ~2.8 ms (target: < 500 ms) — **PASS**
    - Trial Balance Query Execution: ~3.2 ms (target: < 500 ms) — **PASS**
    - Account Ledger Query Execution: ~2.4 ms (target: < 500 ms) — **PASS**
    - Cash Flow Query Execution: ~1.9 ms (target: < 500 ms) — **PASS**
    - Payment Clearing Summary Execution: ~2.2 ms (target: < 500 ms) — **PASS**
    - Memory delta across all report queries: < 1.2 MB.
- **Query Plan (`EXPLAIN`)**:
  - Core aggregate query `SUM(journal_lines.debit_minor - journal_lines.credit_minor)` successfully utilizes composite indexes `idx_journal_lines_entry` on `(tenant_id, journal_entry_id)` and `idx_journal_entries_tenant_outlet_date` on `(tenant_id, outlet_id, entry_date)`.
  - Account lookups utilize `uq_accounts_tenant_code` and `idx_accounts_tenant_type`.
  - Zero N+1 queries detected during report generation.

---

## 26. V1 Financial Export Layer (CSV / XLSX / PDF)

### 26.1 Architectural Mandate — Single Source of Truth
The Financial Export Layer is strictly a **consumer** of the canonical financial reporting layer:

```text
Accounting / General Ledger (`journal_entries`, `journal_lines`)
                      ↓
          FinanceService Report Engine
(getProfitAndLoss, getBalanceSheet, getTrialBalance, getAccountLedger, etc.)
                      ↓
         Canonical Report Data Array
                      ↓
       FinancialReportNormalizer (`FinancialExportData`)
                      ↓
       ---------------------------------
       |               |               |
CsvFinancialExporter XlsxFinancialExporter PdfFinancialExporter
(UTF-8 RFC4180 BOM) (OpenSpout Streaming) (DomPDF Blade Template)
```

- **Strictly Read-Only Guarantee**: Export generation causes zero mutations:
  - 0 journal entries created/updated/reversed.
  - 0 account balances altered.
  - 0 payments, reconciliations, or sales changed.
- **Zero Formula Recalculation**: The export layer strictly forbids defining independent accounting formulas. It does not compute revenue, COGS, expenses, profit, balances, or cash flows.
- **Report / Data Consistency**:
  $$\text{JSON API value} \equiv \text{CSV value} \equiv \text{XLSX value} \equiv \text{PDF visible value}$$
  Values are preserved as exact integer minor units internally, accompanied by standard Indonesian Rupiah presentation formatting (`Rp 10.000.000`) without rounding or float inaccuracies.

### 26.2 Normalized Export Abstraction (`FinancialExportData`)
To decouple file-format formatting from domain entities:
- `FinancialExportData`: Root DTO holding metadata (`title`, `tenantName`, `outletName`, `period`, `generatedAt`), KPI summary cards, and an ordered list of `FinancialExportSheet`.
- `FinancialExportSheet`: Named spreadsheet tab / document section containing ordered `FinancialExportSection`.
- `FinancialExportSection`: Section model supporting tabular `headers`, row records, and optional bold `totals` footers.
- `FinancialReportNormalizer`: Canonical mapper converting raw array outputs from `FinanceService` into `FinancialExportData` for:
  - Profit & Loss (Revenue, COGS, Gross Profit, Expenses, Net Profit, Operating Margin)
  - Balance Sheet (Assets, Liabilities, Equity, Balance Verification Invariant)
  - Trial Balance (Account Codes, Account Names, Debit Balances, Credit Balances, Balancing Totals)
  - Account Ledger (Complete unpaginated chronological transaction history with Running Balances)
  - Cash Flow (Operating, Investing, Financing, Cash Inflows/Outflows, Opening & Closing Cash)
  - Payment Clearing Summary (Clearing debits/credits, matched/unresolved reconciliation status breakdown)
  - Journal Entries (Full audit trail with balanced debit/credit lines)

### 26.3 File Format Implementations & Performance Profiles

#### 1. CSV (`CsvFinancialExporter`)
- Complies strictly with RFC 4180.
- UTF-8 with Byte Order Mark (`\xEF\xBB\xBF`) for seamless Microsoft Excel compatibility.
- Escapes all commas, quotes, and multiline values safely.
- Output: Corporate metadata header block, KPI metrics overview, followed by tabular data sections with totals.

#### 2. XLSX (`XlsxFinancialExporter`)
- Employs `openspout/openspout: ^5.12` lightweight streaming XLSX generator.
- Multi-sheet workbook architecture:
  - Separate structured sheets for multi-dimensional data (e.g., Summary, Detail, Reconciliation).
  - Explicit styling tokens: navy header bars (`#2B6CB0`), bold section titles, shaded KPI blocks, clean alternating numeric cells.
  - Sheet names sanitized and capped to Excel's 31-character limit.
- Low-memory streaming: Memory overhead remains near $0\text{ MB}$ delta even when generating workbooks with thousands of rows.

#### 3. PDF (`PdfFinancialExporter`)
- Powered by `barryvdh/laravel-dompdf: ^3.1` using server-side Blade template rendering (`resources/views/finance/reports/pdf-template.blade.php`).
- A4 portrait layout with SeWaroeng corporate branding, responsive KPI cards, striped table rows, accounting alignment (text left, numeric amounts right), and dynamic page numbering footers.

### 26.4 Multi-Outlet Scoping & Multi-Tenant Authorization
Export endpoints mirror identical authorization rules as the reporting APIs:
- `permission:finance.report.view` required.
- **Tenant Isolation**: PostgreSQL RLS and server-enforced tenant context ensure users cannot export data across tenant boundaries. Client-supplied tenant IDs are ignored.
- **Multi-Outlet Authorization**:
  - `Owner`: Can export tenant-wide (all outlets aggregated) or filter by any owned outlet.
  - `Manager`: Restricted strictly to assigned outlets. Requests with unassigned or cross-tenant outlet IDs fail closed with `403 OUTLET_ACCESS_DENIED`.
  - `Cashier`: Strictly forbidden (`403 FORBIDDEN`).
- **Filename Sanitization**: Content-Disposition filenames follow deterministic pattern `sewaroeng-{report}-{slug}.{ext}` with regex whitelist `[^a-zA-Z0-9\-_.]` to completely neutralize path traversal (`../`) and HTTP header injection.

### 26.5 Synchronous vs. Asynchronous Decision & Benchmark Findings
- **Synchronous Generation**:
  - P&L, Balance Sheet, Trial Balance, Cash Flow, and Clearing Summary generate within $< 100\text{ ms}$ and are served synchronously via HTTP stream with appropriate `Content-Type` and `Content-Disposition`.
- **Large Dataset Scalability Benchmark (`FinancialExportBenchmarkTest`)**:
  - Representative dataset: 1,000 posted journal entries / 2,000 journal lines.
  - Benchmark Results:
    - **CSV Generation**: $0.298\text{ s}$, size: $263\text{ KB}$, peak memory delta: $6.0\text{ MB}$.
    - **XLSX Generation**: $0.500\text{ s}$, size: $86\text{ KB}$, peak memory delta: $\sim 0.0\text{ MB}$ (OpenSpout streaming).
    - **Unpaginated Account Ledger CSV**: $0.293\text{ s}$, size: $103\text{ KB}$ (complete dataset, not limited to first page).
- **Practical Limitations**:
  - PDF generation for $> 500$ journal entries in DomPDF may consume significant CPU and memory due to DOM layout tree computation; for large ledger datasets ($> 5,000$ lines), CSV or streaming XLSX is the recommended format.

---

## 27. V1 WhatsApp Integration & Customer Engagement Architecture (ADR-0016)

### 27.1 Domain Authority Chain & Strict Failure Isolation
WhatsApp integration serves strictly as an asynchronous notification channel for digital receipts and order alerts. It is **never** a source of truth for transactions, payments, customers, or accounting:

```text
Sale Finalized (POS Authority)
      ↓
Payment Settled (Payment Gateway Domain)
      ↓
Finance Double-Entry Journal / Stock Ledger (Canonical Truth)
      ↓ (Post-Commit Domain Event)
WhatsApp Notification Dispatcher (Async Outbox / Event Listener)
      ↓ (After-Commit Durable Queue Job)
SendWhatsAppReceiptJob (afterCommit = true, bounded retry budget)
      ↓
WhatsApp Channel Adapter (Meta Cloud API / Mock Adapter)
      ↓
Customer Delivery Status (Auditable Notification Log)
```

**Non-Negotiable Isolation Invariants**:
- $\text{WhatsApp failure} \ne \text{Sale failure}$
- $\text{WhatsApp failure} \ne \text{Payment failure}$
- $\text{WhatsApp failure} \ne \text{Inventory failure}$
- $\text{WhatsApp failure} \ne \text{Finance failure}$
A provider timeout, rate limit, authentication refusal, or transient outage **never** rolls back or aborts a committed sale, payment settlement, inventory reduction, or financial journal entry. Critical POS and payment requests never wait for provider HTTP responses.

### 27.2 Asynchronous Delivery, Commit Boundary & Durable Outbox Recovery (`WhatsAppOutboxRecoveryService`)
- **After-Commit Delivery**: Event listeners create a durable pending outbox record (`WhatsAppMessage` with status `pending`) and dispatch `SendWhatsAppReceiptJob` configured with `$this->afterCommit = true`.
- **Database Transaction Protection**: If a database transaction rolls back prior to commit, the after-commit queue job is completely discarded by Laravel's `DatabaseTransactionsManager`, guaranteeing zero phantom message dispatches.
- **Durable Dispatch & Enqueue Fault Isolation**: If queue broker connection fails during enqueue (e.g. Redis/database queue broker outage), the exception is safely caught without failing POS or payment settlement. The outbox record captures `status = failed`, `error_code = 'ENQUEUE_FAILED'`, and is logged with full observability for re-processing.
- **Durable Outbox Recovery Mechanism**: An independent scheduled recovery dispatcher (`App\Services\WhatsApp\WhatsAppOutboxRecoveryService`, command `whatsapp:outbox-recover`) runs on a recurring schedule (every 5 minutes via `routes/console.php` with `withoutOverlapping()`).
  - **Recovery Scope**: Detects stranded records (`status = 'pending'` older than 2 minutes where job dispatch was dropped or application process crashed), failed enqueue records (`status = 'failed'`, `error_code = 'ENQUEUE_FAILED'`), and worker crash in-flight messages (`status = 'sending'`, `last_attempt_at` older than 15 minutes without `provider_message_id`).
  - **Atomic Concurrency & Idempotency**: Atomic state transition (`status = 'sending'`, `attempts = attempts + 1`, guarded by `attempts < 3`) prevents double-dispatch if concurrent workers execute.
  - **Terminal State Invariant**: Terminal statuses (`sent`, `delivered`, `read`) are strictly never retransmitted.
  - **Consent Re-Verification**: Re-evaluates customer consent at recovery time; if recipient opted out after initial persistence, dispatch is safely aborted with `CUSTOMER_OPTED_OUT` without contacting provider.
  - **Domain Zero-Mutation Guarantee**: Outbox recovery strictly operates on notification ledger records; it **never** mutates POS Sales, Payments, double-entry journals, or stock ledgers.
- **Queue Driver & Worker Requirements**: In production, `QUEUE_CONNECTION` must be set to a durable driver (`database` or `redis`) with active queue workers (`php artisan queue:work --queue=default`). `QUEUE_CONNECTION=sync` does not satisfy production asynchronous isolation.
- **Bounded Retries & Timeout**: Bounded retries (`$tries = 3`, `$backoff = [5, 15, 45]`) and timeouts (`$timeout = 30s`) prevent worker thread hangs.

### 27.3 Provider-Agnostic Abstraction Architecture
The core CRM, POS, and Notification subsystems are decoupled from third-party vendor APIs via standard interfaces:
- `WhatsAppProviderInterface`: Universal adapter contract (`sendMessage`, `verifyConnection`, `parseWebhookPayload`).
- `WhatsAppProviderManager`: Dynamic provider registry and resolver supporting `mock`, `meta_cloud`, and `meta` alias.
- `MockWhatsAppProvider`: Deterministic in-memory adapter simulating success, rate limits, 500 server errors, timeouts, and webhook payloads without external networks.
- `MetaCloudWhatsAppProvider`: Official Meta WhatsApp Cloud API client with template parameters, text messages, Bearer authentication, and error classification.

### 27.4 Graph API Version Policy & Approved Template Contracts
- **Server-Side Version Configuration & Official Lifecycle**:
  - Verification Audit Date: October 10, 2026 (acuan: Meta Graph API Changelog & WhatsApp Business Platform docs).
  - `v20.0`: Released May 21, 2024 — **EXPIRED** September 24, 2026. Whitelist support completely removed; requests specifying `v20.0` are rejected immediately.
  - `v21.0`: Released October 2, 2024 — Active until January 21, 2027 (Production Default).
  - `v22.0`: Released January 21, 2025 — Active until May 20, 2027 (Active Supported).
  - `v26.0` (Released July 29, 2026) and intermediate versions (`v23.0`-`v25.0`): Evaluated during audit. Meta introduces breaking architectural changes in newer versions (e.g. Commerce Order Management API deprecation, ad targeting constraints). Newer versions are NOT assumed automatically compatible for WhatsApp Cloud API message contracts without live certified testing and are excluded from the allowlist.
  - Expired versions or unvalidated strings are strictly rejected on connection update and configuration load with `\InvalidArgumentException` / HTTP 422 with zero silent fallback.
- **Approved Template Semantics**: Templates configured on connections are validated prior to dispatch. If a template is marked `REJECTED`, dispatch fails immediately with `TEMPLATE_REJECTED` without executing wasteful network requests.
- **Ambiguous Timeout Classification**: Network timeouts or broken sockets are classified as `AMBIGUOUS_TIMEOUT` and marked retryable, preserving idempotency without assuming definitive delivery failure.

### 27.5 Customer Positive Consent, Opt-Out & Audit Provenance
- **Positive Consent Invariant (Meta Business Messaging Policy)**: Mere presence of a customer's phone number does NOT imply consent. Initiating business messages over WhatsApp requires affirmative, positive consent:
  - `whatsapp_opt_in = true` (or positive entry in `whatsapp_consent_history`).
  - Transactional receipts require positive transactional opt-in.
  - Marketing promos strictly require both `whatsapp_opt_in = true` AND `marketing_opt_in = true`.
- **Tenant-Scoped Consent Isolation**: Customer consent records belong strictly to the tenant (`tenant_id`). Consent granted to Tenant A can never be leveraged or read by Tenant B.
- **Manual Override Protection**: Cashier phone overrides are checked against registered customer records for that tenant. Manual overrides without positive consent on file are rejected (`CONSENT_REQUIRED`).
- **Opt-Out Policy**:
  - Customers can opt out via inbound keyword (`STOP`, `UNSUBSCRIBE`) or manual request (`Customer::optOutWhatsApp()`).
  - Sets `whatsapp_opt_in = false`, `whatsapp_opted_out = true`, `whatsapp_opted_out_at = now()`.
  - Opt-out is strictly enforced across automatic event dispatches, queue retries (even if opted out between enqueue and execution), and manual resends (`RECIPIENT_OPTED_OUT` / `CUSTOMER_OPTED_OUT`).
- **Audit Provenance**: All consent updates and opt-outs are chronologically appended to `whatsapp_consent_history` JSONB array on the customer record with `tenant_id`, `category`, `source`, `timestamp`, `granted`, and `notes`. Historical consent is never erased.

### 27.6 Webhook Security, App Secret Ownership & Isolation
- **Dual Endpoint Architecture**:
  - **Platform-Scoped Endpoint** (`POST /api/v1/webhooks/whatsapp/{provider}`): For single Meta App deployments serving multi-tenant accounts. Authenticates `X-Hub-Signature-256` using platform `services.whatsapp.meta.app_secret` via constant-time `hash_equals` over raw body. Maps verified `phone_number_id` to the active tenant connection.
  - **Tenant-Scoped Endpoint** (`POST /api/v1/webhooks/whatsapp/{tenant}/{provider}`): Authenticates against tenant-specific App Secret.
- **Cross-Tenant Evidence Rejection**: Incoming webhooks on tenant-scoped endpoints verify that payload `phone_number_id` matches the tenant connection. Mismatches are rejected.
- **State Monotonicity**: Delivery callbacks only advance message state forward (`pending → sending → sent → delivered → read`). Out-of-order or late webhooks cannot downgrade state.
- **GET Challenge Handshake**: Verifies `hub.challenge` against server verification token in constant time.

### 27.7 Credential Security & Masking
- **At Rest**: Tenant provider credentials (`access_token`, `app_secret`, `verify_token`) are encrypted with AES-256 via Laravel `encrypted:array` cast on `tenant_whatsapp_connections.credentials`.
- **In Transit**: JSON serialization exposes only `masked_credentials` (e.g. `EAA***`), never plaintext keys.
- **In Logs**: Provider tokens, Authorization headers, and raw secrets are sanitized via recursive redaction (`[REDACTED]`) before recording responses or writing logs.

### 27.8 Entitlement, RBAC & Integration Verification Status
- **Subscription Entitlement**: Guarded by `whatsapp` module entitlement check (`$entitlementService->assertCan($tenant, 'whatsapp')`).
- **RBAC Matrix**:
  - `whatsapp.view`: View connection status and message delivery history.
  - `whatsapp.manage`: Configure provider credentials, test, and verify connections.
  - `whatsapp.send`: Trigger manual receipt sending and resend failed dispatches.
- **Real Meta Integration Status**: **`NOT VERIFIED (Mock verified)`**. Passing mock adapter tests and contract suites does not constitute production verification. Production readiness requires valid Meta Business Manager verification, approved business numbers, Meta-approved message templates, and public webhook domain endpoints.

