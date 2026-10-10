# ADR-0001: Multi-Tenancy and Tenant Isolation Strategy

## Status
Accepted

## Context
SeWaroeng is a multi-tenant SaaS Business Operating System targeting Indonesian MSMEs (starting with F&B). The platform is architected to scale to tens of thousands of tenants (businesses), where each tenant may operate one or multiple physical outlets, warehouses, inventory ledgers, and staff accounts.

According to the SeWaroeng PRD (`docs/SeWaroeng_PRD_v1.0.md`) and Master Instruction:
1. Strict tenant isolation is mandatory: cross-tenant access must be structurally impossible by default.
2. The client is never trusted for `tenant_id`, permissions, prices, or payment status.
3. The platform requires multi-outlet hierarchies within a single tenant.
4. Future platform capabilities include cross-merchant crowd intelligence and anonymized benchmarking, requiring efficient aggregate queries without crossing tenant privacy boundaries.
5. Operational overhead, migration speed, connection pooling efficiency, and infrastructure cost are critical constraints for serving Indonesian MSMEs.

---

## Architectural Options Evaluated

### Option A: Single Database with Application-Level Scoping (`tenant_id` Column)
All tenant-scoped tables include a `tenant_id` foreign key. Laravel models implement an Eloquent Global Scope (e.g., `TenantScope`) that automatically appends `WHERE tenant_id = ?` to queries and assigns `tenant_id` during creation.

* **Pros:** Highly cost-effective; straightforward migrations (single schema); seamless connection pooling (PgBouncer); native Laravel tooling compatibility; simple cross-tenant analytics queries for platform admins.
* **Cons:** Fragile security boundary. A single developer mistake (e.g., using raw queries via `DB::table()`, forgetting the trait on a new model, or disabling global scopes via `withoutGlobalScopes()`) creates a catastrophic cross-tenant data leak.

### Option B: Database-Per-Tenant or Schema-Per-Tenant (PostgreSQL Schemas)
Each tenant receives an isolated PostgreSQL schema or a distinct PostgreSQL database.

* **Pros:** Absolute physical isolation at the database layer; reduced blast radius of schema corruption; simple backup/restore per tenant.
* **Cons:** Catastrophic operational complexity at scale (running migrations across 10,000+ schemas); high connection pool overhead; memory exhaustion on PostgreSQL; cross-tenant benchmarking/crowd intelligence becomes extremely slow or requires ETL; significant delay during tenant onboarding.

### Option C: Single Database + PostgreSQL Native Row-Level Security (RLS) Exclusively
PostgreSQL enforces `USING (tenant_id = current_setting('app.current_tenant_id'))` on every query. The application issues `SET LOCAL app.current_tenant_id = '...'` upon each request.

* **Pros:** Enforced at the engine level; immune to raw query mistakes or missing Laravel scopes.
* **Cons:** If session pooling (e.g., PgBouncer in transaction mode) is improperly configured, session variables can leak across connection reuse if not reset with `SET LOCAL`; debugging raw SQL queries in dev tools requires setting session context; potential performance overhead on complex joins if indexes are not carefully crafted.

### Option D: Defense-in-Depth Hybrid (Single DB + Discriminator Column + Application Scoping + PostgreSQL RLS)
A single PostgreSQL database where all tenant-scoped tables have a non-nullable `tenant_id` column. Isolation is enforced through two mandatory layers:
1. **Layer 1 (Application Layer):** Laravel Eloquent `TenantScope` and strict Model policies that automatically scope all ORM queries and auto-populate `tenant_id`. Static analysis (PHPStan rules) blocks unscoped `DB::table()` queries on tenant tables.
2. **Layer 2 (Database Layer - Defense-in-Depth):** PostgreSQL Row-Level Security (RLS) enabled on all tenant-scoped tables, keyed against `current_setting('app.current_tenant_id', true)`. Even if application code executes a raw query without a `tenant_id` filter, the PostgreSQL database engine refuses to return rows belonging to another tenant.

---

## Decision

We adopt **Option D: Defense-in-Depth Hybrid (Single Database + `tenant_id` Column + Eloquent Scoping + PostgreSQL RLS)** as the authoritative tenancy strategy for SeWaroeng.

```
+-------------------------------------------------------------------------------+
|                               HTTP / API Request                              |
+-------------------------------------------------------------------------------+
                                        |
                                        v
+-------------------------------------------------------------------------------+
| 1. Tenant Resolution Middleware                                               |
|    - Authenticates token via Sanctum                                          |
|    - Resolves active TenantContext from DB-backed UserTenantMembership         |
|    - Rejects request if user has no authorized access to requested tenant     |
+-------------------------------------------------------------------------------+
                                        |
                                        v
+-------------------------------------------------------------------------------+
| 2. Context Propagation & Database Session Hook                                |
|    - Binds TenantContext to Laravel Service Container (scoped)                |
|    - Executes: DB::statement("SET LOCAL app.current_tenant_id = :id")          |
+-------------------------------------------------------------------------------+
                                        |
                    +-------------------+-------------------+
                    |                                       |
                    v                                       v
+---------------------------------------+   +-----------------------------------+
| 3. Layer 1: Application Scoping      |   | 4. Layer 2: PostgreSQL RLS        |
|    - Eloquent BelongsToTenant Trait   |   |    - Engine-level constraint      |
|    - Auto WHERE tenant_id = :tenant_id|   |    - Policy: tenant_id =          |
|    - Auto-fills tenant_id on create   |   |      current_setting('app...')    |
|    - PHPStan linter enforces scopes   |   |    - Blocks leaks if Layer 1 fails|
+---------------------------------------+   +-----------------------------------+
                                        |
                                        v
+-------------------------------------------------------------------------------+
| 5. Multi-Outlet Hierarchy (within Tenant)                                     |
|    - Tenant owns: Outlets, Warehouses, Users, Products, Ledgers               |
|    - Outlet-scoped resources verify: resource.tenant_id == current_tenant_id  |
|      AND resource.outlet_id == authorized_outlet_id                           |
+-------------------------------------------------------------------------------+
```

---

## Detailed Implementation Mechanics

### 1. Tenant Hierarchy and Context Resolution
The system models tenancy as:
```text
Platform
└── Tenant (Business Entity: PT / CV / Warung / Cafe)
    ├── Outlets (Physical branch / POS location)
    ├── Warehouses (Storage / Central Kitchen)
    ├── Products & Categories
    ├── Inventory Ledgers
    ├── Orders & Transactions
    └── UserTenantMemberships (Role & Outlet Permissions)
```

#### Context Resolution Flow:
1. Every authenticated API request contains a Sanctum bearer token.
2. The `AuthenticateAndResolveTenant` middleware retrieves the authenticated `User`.
3. If the user belongs to exactly one active tenant, that tenant is automatically set as the active `TenantContext`.
4. If the user belongs to multiple tenants (e.g., an owner with 2 separate businesses), the client sends an `X-Tenant-ID` header. The middleware queries the authoritative `user_tenant_memberships` table in PostgreSQL to verify that `user_id` has an active, non-suspended membership in that specific `tenant_id`. If invalid or missing, the request is rejected with `403 Forbidden` (`TENANT_ACCESS_DENIED`).
5. **Security Invariant:** The client-provided `X-Tenant-ID` header is **never** accepted blindly; it is only treated as a tenant switch selector, validated against server-side membership records.

### 2. Context Propagation and PostgreSQL Session Binding
Upon resolving the tenant:
1. The tenant instance is bound to the Laravel application container as a scoped instance: `app()->scoped(TenantContext::class)`.
2. A database connection event listener or middleware triggers:
   ```sql
   SET LOCAL app.current_tenant_id = '01J...';
   ```
   Using `SET LOCAL` guarantees that the variable is scoped strictly to the current database transaction. When the transaction commits or rolls back, or when the connection returns to a pooler (e.g., PgBouncer), the setting is immediately discarded, preventing connection pollution.

### 3. Layer 1: Application-Level Enforcement (Eloquent)
1. All tenant-scoped Eloquent models implement `App\Domain\Shared\Concerns\BelongsToTenant`:
   ```php
   trait BelongsToTenant {
       protected static function bootBelongsToTenant(): void {
           static::addGlobalScope(new TenantScope());
           
           static::creating(function ($model) {
               if (empty($model->tenant_id)) {
                   $model->tenant_id = app(TenantContext::class)->id();
               }
           });
       }
       
       public function tenant(): BelongsTo {
           return $this->belongsTo(Tenant::class);
       }
   }
   ```
2. The `TenantScope` appends `WHERE {table}.tenant_id = ?` to every query.
3. Custom PHPStan / Larastan rules are configured in CI/CD to detect and fail the build if `DB::table()` is called without an explicit `where('tenant_id', ...)` check on registered tenant tables.

### 4. Layer 2: PostgreSQL Row-Level Security (RLS)
1. Every migration creating a tenant-scoped table applies RLS:
   ```sql
   ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
   ALTER TABLE orders FORCE ROW LEVEL SECURITY;
   
   CREATE POLICY tenant_isolation_policy ON orders
       FOR ALL
       USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
       WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
   ```
2. `FORCE ROW LEVEL SECURITY` ensures the table owner/application database user is also bound to the policy.
3. An administrative PostgreSQL bypass role is reserved strictly for platform migrations and system seeders, never used by the web runtime.

### 5. Multi-Outlet Scoping within a Tenant
* Outlets are sub-divisions of a Tenant.
* A user with role `cashier` or `manager` may be constrained to specific `outlet_ids`.
* Outlet isolation is handled through Laravel Authorization Policies (`OrderPolicy`, `ShiftPolicy`):
  1. The policy verifies `model.tenant_id === tenantContext.id`.
  2. The policy verifies that if `user.role === 'cashier'`, `user.accessible_outlet_ids` contains `model.outlet_id`.
  3. Cashiers cannot query or mutate transactions of another outlet even within the same tenant.

### 6. Background Jobs and Queued Events
1. Every queued job operating on tenant data must implement `TenantAwareJob`:
   ```php
   class ProcessOrderSynced implements ShouldQueue {
       use InteractsWithQueue, Queueable, SerializesModels;
       
       public function __construct(
           public readonly string $tenantId,
           public readonly string $orderId
       ) {}
       
       public function handle(TenantManager $tenantManager): void {
           $tenantManager->runForTenant($this->tenantId, function () {
               // app.current_tenant_id is set locally
               // Eloquent scope is active
               // Domain execution...
           });
       }
   }
   ```
2. If `tenantId` is missing, the job worker immediately fails the job to prevent unscoped background execution.

### 7. Scheduled Tasks and System Crons
1. Scheduled jobs (e.g., daily P&L calculation, low stock alert evaluation) never run as an unscoped global query.
2. The scheduler iterates through active tenants:
   ```php
   Tenant::query()->where('is_active', true)->chunkById(100, function ($tenants) {
       foreach ($tenants as $tenant) {
           dispatch(new ProcessDailyTenantReport($tenant->id));
       }
   });
   ```
3. Each tenant report executes within its own isolated `runForTenant` context.

### 8. CLI / Artisan Commands
1. Artisan commands that touch tenant data (e.g., `php artisan sewaroeng:recalculate-stock`) **must** accept a mandatory `--tenant=` argument.
2. Running without `--tenant=` requires explicit interactive confirmation (`--all-tenants` flag) and iterates tenant-by-tenant using `runForTenant()`.

---

## Verification & Testing Strategy

1. **Automated Tenant Isolation Unit & Feature Tests:**
   * A dedicated `TenantTestCase` sets up two distinct tenants (`Tenant A` and `Tenant B`) with their respective users and records.
   * Tests systematically execute:
     * Attempting to query Tenant B's products using Tenant A's token -> Result must be `404 Not Found` or empty collection.
     * Attempting to update Tenant B's order using Tenant A's token -> Result must be `404 Not Found` or `403 Forbidden`.
     * Injecting `tenant_id = Tenant B` in request payload while authenticated as Tenant A -> Result must be ignored; record created under Tenant A.
2. **RLS Bypass Test:**
   * Direct PDO test executing raw SQL: `SELECT * FROM orders` without setting `app.current_tenant_id` -> Returns 0 rows.
   * Direct PDO test executing `SET LOCAL app.current_tenant_id = 'Tenant A'; SELECT * FROM orders;` -> Returns only Tenant A rows.

---

## Consequences

### Positive
* High-assurance data privacy: accidental omission of application-level scopes is caught by the database engine.
* Low infrastructure complexity and minimal cost: single database to maintain, backup, and tune.
* Instant tenant onboarding: no schema creation or DDL locks required.
* Simple cross-tenant analytics for platform intelligence: Super Admins can query aggregate statistical data using a dedicated platform reporting role.

### Negative / Trade-offs
* All developers must understand both Eloquent scoping and PostgreSQL RLS session variables.
* PgBouncer configuration requires strict `SET LOCAL` usage inside transactions to avoid session pollution.
* Schema migrations must remember to include the RLS trigger/policy helper for new tables (enforced via a custom migration test suite).
