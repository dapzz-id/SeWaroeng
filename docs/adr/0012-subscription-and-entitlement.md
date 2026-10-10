# ADR 0012: Subscription Foundation, Entitlement Resolution, and Quota Enforcement

## Status
Accepted

## Date
2026-10-06 (Phase 1J)

## Context
As SeWaroeng matures through its MVP lifecycle, a robust subscription and capability foundation is required to govern tenant feature access, operational quotas (e.g. max outlets, users, products, monthly transactions), and billing lifecycles per `docs/SeWaroeng_PRD_v1.0.md` (Sections 35 and 61).

Key business and architectural requirements:
1. **Separation of Platform Billing from Tenant Payment Gateways**: SeWaroeng platform charges tenants for subscriptions (`SubscriptionBillingProviderInterface`). This is strictly decoupled from merchant payment gateways (`TenantPaymentConnection` using Midtrans/Duitku) where tenants receive payments from their own retail customers.
2. **Canonical Plan Catalog**: Per PRD Section 61, the canonical plan catalog is:
   - `Free / Trial` (`free`): Micro-business and evaluation tier (includes POS, Inventory, Customer CRM, Finance, Offline POS, Multi-Outlet evaluation; quotas: 5 outlets, 5 users, 100 products, 500 monthly transactions).
   - `Starter` (`starter`): Single-outlet growing retail/F&B tier (quotas: 1 outlet, 5 users, 500 products, 2000 monthly transactions).
   - `Business` (`business`): Full-featured single-outlet tier with advanced reports, WhatsApp, AI copilot, unlimited products and transactions.
   - `Multi-Outlet` (`multi_outlet`): Enterprise multi-branch tier with full capabilities, 10+ outlets, 50 users, unlimited catalog and transactions.
   - `Add-ons` (`add_ons`): Declarative add-ons (`addon.whatsapp`, `addon.ai_copilot`, `addon.additional_outlet`, `addon.additional_staff`, `addon.advanced_reports`).
3. **Database Normalization & Tenant RLS Isolation**:
   - Platform catalogs (`subscription_plans`, `subscription_plan_entitlements`, `subscription_add_ons`) are platform-scoped reference tables without tenant RLS.
   - Tenant subscription data (`subscriptions`, `subscription_events`, `subscription_add_on_assignments`) are strictly tenant-scoped with forced PostgreSQL Row-Level Security (RLS) policies that permit platform superadmin inspection.
4. **Subscription Lifecycle & Server-Authoritative Trial**: State machine with explicit transitions (`trialing`, `active`, `past_due`, `paused`, `cancelled`, `expired`). Client device clocks are never trusted; trials are bound to tenant accounts and verified server-side.
5. **Centralized Entitlement Resolver**: All capability checks (`can`) and quota evaluations (`limit`, `usage`, `remaining`) pass through `SubscriptionEntitlementService`. RBAC permissions (`hasPermission`) and tenant subscription entitlements are distinct orthogonal gates: both must pass.
6. **Concurrency-Protected Quota Enforcement**: Atomic quota checks with pessimistic row locking (`SELECT ... FOR UPDATE` on `tenants`) prevent race condition bypasses on scarce quota slots.
7. **Non-Destructive Downgrades**: Downgrading a plan never destroys or soft-deletes existing business data (e.g., existing outlets remain intact; creation of new resources is barred until quota is restored or upgraded).
8. **Offline-First POS Interaction**: Offline clients cache read-only subscription metadata for UI display. Offline mutations cannot alter subscription authority, upgrade tiers, or bypass server-side quota verification upon sync.

## Decisions

### 1. Domain Schema & Architectural Boundaries
- Platform Reference Tables (No Tenant RLS):
  - `subscription_plans`: UUIDv7 PK, immutable stable `code`, `name`, `price_minor`, `currency`, `billing_interval`, `is_active`, `sort_order`.
  - `subscription_plan_entitlements`: UUIDv7 PK, `plan_id`, `feature_key`, `is_enabled`, `quota_limit` (nullable; `NULL` = unlimited). Unique on `(plan_id, feature_key)`.
  - `subscription_add_ons`: UUIDv7 PK, `code`, `name`, `price_minor`, `feature_key`, `quota_increment`, `is_active`.
- Tenant Scoped Tables (Forced PostgreSQL RLS):
  - `subscriptions`: UUIDv7 PK, `tenant_id`, `plan_id`, `status`, `starts_at`, `current_period_start`, `current_period_end`, `trial_ends_at`, `cancelled_at`, `ended_at`, `billing_provider`.
  - `subscription_events`: UUIDv7 PK, `tenant_id`, `subscription_id`, `event_type`, `user_id`, `from_plan_id`, `to_plan_id`, `payload`.
  - `subscription_add_on_assignments`: UUIDv7 PK, `tenant_id`, `subscription_id`, `add_on_id`, `quantity`, `status`, `starts_at`, `ends_at`.

### 2. Subscription Lifecycle State Machine
```text
[New Tenant]
    ↓ (Automatic 14-day trial)
 trialing ──────(Trial expires)─────→ expired
    │                                   ↑
 (Upgrade/Pay)                     (Unpaid/End)
    ↓                                   │
 active ────────(Payment fails)────→ past_due
    │                                   │
 (Cancel)                          (Payment fails)
    ↓                                   │
 cancelled ────(Period ends)───────────→ expired
```
- In `trialing`, `isTrial() == true` and operational access is permitted while `now() <= trial_ends_at`.
- In `cancelled`, operational access continues until prepaid `current_period_end`.
- In `expired`, operational access is blocked (`SubscriptionInactiveException` / HTTP 403 `SUBSCRIPTION_INACTIVE`).

### 3. Centralized Entitlement & Usage Resolver
- `SubscriptionEntitlementService`:
  - `can(Tenant $tenant, string $featureKey): bool`: Evaluates plan entitlement + active add-ons.
  - `limit(Tenant $tenant, string $quotaDimension): ?int`: Base quota limit + sum(add-on increments). Returns `NULL` for unlimited.
  - `usage(Tenant $tenant, string $quotaDimension): int`: Authoritatively calculated via `SubscriptionUsageService`.
  - `remaining(Tenant $tenant, string $quotaDimension): ?int`: Returns `max(0, limit - usage)` or `NULL` if unlimited.
  - `assertCan(Tenant $tenant, string $featureKey)`: Throws `SubscriptionFeatureNotIncludedException` (403) or `SubscriptionInactiveException` (403).
  - `assertQuotaAvailable(Tenant $tenant, string $quotaDimension, int $increment = 1)`: Throws `SubscriptionQuotaExceededException` (422).
  - Cache: Cached per tenant (`tenant_subscription_entitlements:{$tenantId}`) for 300 seconds and eagerly invalidated on any subscription lifecycle mutation.

### 4. Authoritative Usage Dimensions
`SubscriptionUsageService` queries authoritative backend relational records:
- `max_outlets`: Active outlets (`status = 'active'`).
- `max_users`: Active tenant memberships (`status = 'active'`).
- `max_products`: Active catalog products (`status = 'active'`).
- `max_monthly_transactions`: Finalized sales within current calendar month (`status = 'finalized'` between month start and end).

### 5. Concurrency & Race Condition Protection
Resource creation (e.g., `OutletController::store` or `ProductController::store`) wraps the quota check and insert in a database transaction with a pessimistic row lock:
```php
Tenant::where('id', $tenantId)->lockForUpdate()->first();
$entitlementService->assertQuotaAvailable($tenant, 'max_outlets', 1);
Outlet::create([...]);
```
This guarantees that two simultaneous requests competing for the last available slot are strictly serialized, resulting in exactly one success and one `SUBSCRIPTION_QUOTA_EXCEEDED`.

### 6. Platform Administration vs Tenant Boundaries
- Platform routes (`/api/v1/platform/*`) require `EnsurePlatformSuperadmin` and establish platform context (`app.is_platform_context = true`), allowing superadmins to manage plan catalog definitions and inspect tenant subscriptions.
- Tenant routes (`/api/v1/subscription/*`) are scoped to the active tenant context with RBAC (`subscription.view`, `subscription.manage`). Ordinary tenant users cannot alter plans, forge quotas, or inspect other tenants.

## Consequences
- Clean separation between tenant customer sales payment infrastructure (Midtrans/Duitku) and platform SaaS billing.
- Production-ready entitlement gates that can be queried centrally across any existing or future domain.
- Client applications receive authoritative quota and entitlement telemetry without relying on insecure client-side enforcement.
