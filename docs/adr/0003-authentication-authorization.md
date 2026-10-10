# ADR-0003: Authentication, Authorization, and Tenant Membership

## Status
Accepted

## Context
SeWaroeng is an API-first SaaS platform serving merchants through Flutter mobile apps (cashier POS on Android), Flutter Web (owner/manager back-office), and future third-party integrations.

According to the PRD (`docs/SeWaroeng_PRD_v1.0.md`) and Master Instruction:
1. Flutter must never be trusted to determine permissions, roles, or tenant boundaries.
2. Sensitive authorization decisions must never be encoded immutably into client-side tokens.
3. The platform must support multi-tenant users (e.g., an owner with multiple businesses, or a consultant accountant managing multiple tenants).
4. Strict role-based access control (RBAC) and outlet-level isolation are required (e.g., a cashier assigned to Outlet A must be structurally prohibited from viewing or transacting in Outlet B).
5. Fast revocation must occur immediately upon role changes, staff termination, or token theft.

---

## Architectural Options Evaluated

### Option A: Stateless JSON Web Tokens (JWT) via `tymon/jwt-auth` or `php-open-source-saver/jwt-auth`
* **Pros:** Scalable stateless verification without hitting the database on every request.
* **Cons:**
  * **Stale authorization vulnerability:** If a cashier is fired or their permissions are downgraded, their JWT remains valid until expiration unless a centralized token blocklist (Redis) is maintained, defeating the purpose of statelessness.
  * **Token revocation latency:** Immediate lockout across all devices requires stateful tracking anyway.
  * **Complexity:** High key management overhead without tangible benefits for first-party mobile/web clients.

### Option B: OAuth2 Server via Laravel Passport
* **Pros:** Full OAuth2 specification compliance; standard authorization code, client credentials, and refresh token flows.
* **Cons:** Massive architectural overhead. Heavy database schema, high developer friction, and unnecessary complexity for a closed, first-party mobile and web operational system.

### Option C: Database-Backed Personal Access Tokens via Laravel Sanctum
* **Pros:**
  * **Instant server-side revocation:** Tokens are stored as SHA-256 hashes in `personal_access_tokens`. Deleting or updating a record terminates access immediately.
  * **Dual-mode support:** Seamlessly supports Stateful Session Cookies for Flutter Web/SPA and Bearer API tokens for Flutter Mobile/POS.
  * **Device & session visibility:** Allows tracking `name`, `abilities`, `last_used_at`, and client IP per active device.
  * **Extremely lightweight:** Minimal maintenance overhead, high performance, fully integrated into the Laravel ecosystem.
* **Cons:** Requires a database lookup for authentication. (Mitigated via Redis token caching / connection pooling, well within microsecond response times for PostgreSQL).

---

## Decision

We adopt **Laravel Sanctum (Database-Backed Bearer Tokens with Dynamic Server-Side Authorization)** as the foundational authentication mechanism for SeWaroeng.

We strictly decouple:
1. **Authentication (Who are you?):** Verified by Sanctum token -> resolves authoritative `User`.
2. **Tenant Membership (Which business are you operating in?):** Verified by `UserTenantMembership` -> resolves active `TenantContext`.
3. **Authorization & Outlet Scoping (What are you allowed to do here?):** Enforced dynamically by Laravel Policies / Gates -> resolves current `Role`, `Permissions`, and assigned `Outlet` boundaries from authoritative server-side state.

```
+---------------------------------------------------------------------------------------+
|                                    AUTHENTICATION FLOW                                |
+---------------------------------------------------------------------------------------+

 Flutter Client (Mobile/Web)
       |
       | 1. POST /api/v1/auth/login { email, password, device_name, device_id }
       v
 Laravel Auth (Sanctum)
       |
       | 2. Verify credentials + rate limit (5 attempts/min)
       | 3. Generate SHA-256 hashed Bearer Token in `personal_access_tokens`
       | 4. Query active `user_tenant_memberships`
       v
 Response to Client:
       {
         "token": "1|abc...",
         "user": { "id": "019...", "name": "Budi", "email": "..." },
         "tenants": [
           { "id": "019...tenantA", "name": "Kopi Kulo", "role": "owner" },
           { "id": "019...tenantB", "name": "Bakery Enak", "role": "manager" }
         ]
       }

+---------------------------------------------------------------------------------------+
|                                AUTHORIZATION PIPELINE                                 |
+---------------------------------------------------------------------------------------+

 Incoming API Request: GET /api/v1/pos/orders [Header: X-Tenant-ID: 019...tenantA]
       |
       v
 [1. Sanctum Authentication Guard]
       - Validates Bearer token signature against `personal_access_tokens`
       - Resolves authenticated `User` ($request->user())
       |
       v
 [2. Tenant Context Resolution Middleware]
       - Reads `X-Tenant-ID` header (or default single membership)
       - Queries authoritative `user_tenant_memberships` in DB:
         WHERE user_id = :user AND tenant_id = :tenant AND status = 'active'
       - If no active membership: 403 Forbidden ("TENANT_MEMBERSHIP_INACTIVE")
       - Binds validated `TenantContext` to container and executes `SET LOCAL app.current_tenant_id`
       |
       v
 [3. Role & Permission Gate / Policy]
       - Inspects membership's `role` and granular `permissions`
       - Evaluates Policy: OrderPolicy::viewAny($user)
       |
       v
 [4. Outlet-Level Data Isolation]
       - If user is `cashier` or `manager`, inspects `membership.outlet_access_list`
       - Restricts query to: WHERE tenant_id = :tenant_id AND outlet_id IN (:accessible_outlets)
       - Cashier assigned only to Outlet A cannot read/mutate Outlet B
```

---

## Domain Model & Access Taxonomy

### 1. Core Entities

```text
User (Global Identity across SeWaroeng platform)
 └── UserTenantMembership (Pivot linking User to a specific Tenant)
      ├── role: Enum (owner, manager, cashier, staff, finance)
      ├── permissions: JSON / Bitmask (granular capabilities)
      ├── outlet_access: Enum (all, specific)
      ├── assigned_outlets: Array of UUIDs (for specific outlet restrictions)
      └── status: Enum (active, invited, suspended)
```

1. **User:** Represents the human identity. Holds credentials (hashed password, phone number, multi-factor secrets). A user exists globally once.
2. **Tenant (Business):** The legal business entity. Owns catalog, inventory, orders, finance, and settings.
3. **Membership (`user_tenant_memberships`):** Explicitly connects a User to a Tenant. Contains the user's role and assigned permissions **specifically within that tenant**.
4. **Role:** Standardized operational roles:
   * `owner`: Full unrestricted administrative, financial, and destructive capabilities across all outlets.
   * `manager`: Operational management (inventory adjustments, viewing shift reports, managing discounts) across assigned outlets.
   * `cashier`: Operational POS capabilities (open/close shift, create orders, record payments, view own transactions) strictly within their assigned outlet.
   * `finance`: Read/write access to expenses, accounting journals, and financial reports; no POS cashier access.
   * `staff`: General operational duties (e.g., kitchen display view).
5. **Granular Permissions:** Specific strings checked by Laravel Gates (e.g., `pos.order.void`, `inventory.adjust`, `finance.report.view`). Roles define standard bundles, but owners can override capabilities.

---

## Strict Isolation Scenarios

### Scenario 1: Multi-Business User (Owner or External Accountant)
* **Problem:** User Budi owns "Warung Kopi" (Tenant 1) and co-owns "Bakery Jaya" (Tenant 2).
* **Implementation:**
  * Budi logs in once with his credentials and receives a Sanctum token.
  * The login response returns all accessible tenants.
  * When sending requests, the Flutter client sets `X-Tenant-ID: <tenant_1_uuid>`.
  * The `TenantResolutionMiddleware` verifies that Budi has an active membership in Tenant 1. All Eloquent queries and PostgreSQL RLS policies are locked strictly to Tenant 1.
  * If Budi switches to "Bakery Jaya" in the UI, subsequent requests send `X-Tenant-ID: <tenant_2_uuid>`. Context cleanly pivots to Tenant 2.
  * Cross-tenant data leakage is structurally impossible because tenant context is resolved from the database on every HTTP request.

### Scenario 2: Cashier Outlet Isolation
* **Problem:** Cashier Siti is employed at Outlet A. She must be prevented from reading sales figures or creating orders for Outlet B.
* **Implementation:**
  * Siti's `UserTenantMembership` has `outlet_access = 'specific'` and `assigned_outlets = ['<outlet_A_uuid>']`.
  * When Siti creates an order or opens a shift:
    * The request payload includes `outlet_id: <outlet_A_uuid>`.
    * The `OrderPolicy::create` verifies:
      ```php
      public function create(User $user, string $requestedOutletId): bool {
          $membership = app(TenantContext::class)->membership();
          if ($membership->role === Role::OWNER) {
              return true;
          }
          return in_array($requestedOutletId, $membership->assigned_outlet_ids, true);
      }
      ```
    * If Siti tampers with the request payload to specify Outlet B's ID, the request is rejected immediately with `403 Forbidden` (`OUTLET_ACCESS_DENIED`).

---

## Security Lifecycle & Threat Mitigations

### 1. Token Lifecycle & Expiration
* **Mobile (Flutter POS):** Tokens expire after 30 days of inactivity. Every successful request updates `last_used_at`.
* **Web (Flutter Web Admin):** Uses encrypted, HttpOnly, SameSite cookies via Sanctum stateful domain protection with an idle session timeout of 8 hours.
* **Immediate Role Revocation:** Because permissions and tenant memberships are resolved on **every request** from the authoritative database, revoking a user's membership or changing their role from `manager` to `cashier` takes effect instantly without waiting for token expiration.

### 2. Device & Session Management
* During login, the client submits:
  * `device_name`: Human-readable string (e.g., "Samsung Tab A9 - Kasir Depan").
  * `device_id`: Client-generated stable hardware UUID.
  * `platform`: `android` | `ios` | `web`.
* The server stores these attributes in `personal_access_tokens`.
* The Merchant Owner can view all active sessions in the Admin Dashboard and revoke any specific device with a single click (`$token->delete()`).

### 3. Rate Limiting & Brute Force Prevention
* Authentication endpoints (`/api/v1/auth/login`, `/api/v1/auth/forgot-password`) enforce strict IP and credential rate limiting:
  * Maximum 5 attempts per minute per IP + email combination.
  * Exceeding the threshold triggers HTTP `429 Too Many Requests` with exponential backoff and security audit logging.

### 4. PIN-Based Fast Cashier Switching
* In busy F&B environments, multiple cashiers share a single physical tablet.
* Rather than performing full token renegotiation, the primary device maintains the authenticated device session. Fast cashier switching is handled via a **6-digit Cashier Staff PIN**:
  * Cashier enters 6-digit PIN locally.
  * Client sends `POST /api/v1/pos/staff-session` with the PIN.
  * Server verifies the hashed PIN against the tenant's cashier roster, validates outlet assignment, and issues an ephemeral `StaffSessionContext` token (valid for the active shift).
  * Every transaction audit log records both the `device_id` and the specific `cashier_staff_id`.

---

## Consequences

### Positive
* Absolute server-side authority: zero risk of stale permissions or client-side tampering of roles.
* Complete multi-tenant flexibility: single login credential can manage multiple businesses without credential duplication.
* Granular multi-outlet protection: cashiers and managers are physically constrained to their authorized outlets.
* Instant revocation capability for lost, stolen, or compromised devices.

### Negative / Trade-offs
* Every authenticated request performs a fast membership check in the database (mitigated by indexing `(user_id, tenant_id)` on `user_tenant_memberships`).
* Fast cashier switching requires careful handling of local session state in Flutter to ensure transactions are attributed to the correct staff member.
