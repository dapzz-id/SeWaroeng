# SeWaroeng — Phase 0 Security Gate & Architecture Review

**Evaluation Date**: 2026-10-04  
**Evaluator**: Principal Software Architect & Security Engineer  
**Target Milestone**: Phase 0C Foundation Hardening Gate (Pre-Phase 1A)

---

## 1. Executive Summary

This security gate evaluates the technical foundation of SeWaroeng against architectural standards (ADR-0001 through ADR-0004) before any business modules (POS, Inventory, Payment, Ledger) are implemented in Phase 1A.

All foundational controls have been tested with automated test coverage across PostgreSQL RLS, dual-layer idempotency, multi-business tenancy, outlet authorization, and Flutter offline outbox state machines.

---

## 2. Security Gate Matrix

| Category | Gate Status | Verification & Remediation Notes |
|---|---|---|
| **Tenant Isolation** | **PASS** | Evaluated via `TenantScope` and `ResolveTenantContext`. Users cannot forge `X-Tenant-ID` headers without authoritative active membership. Cross-tenant reads return 404 or empty results. Multi-business switching verified. |
| **Row-Level Security (RLS)** | **PASS** | PostgreSQL native RLS enabled and forced on tenant entities. Verified fail-closed behavior on missing or invalid tenant context (`SELECT` returns 0 rows). Cross-tenant update and delete attempts yield 0 affected rows. Application database role `sewaroeng_app` is verified `rolsuper=false` and `rolbypassrls=false`. Context is transaction-scoped (`SET LOCAL`) preventing worker contamination. |
| **Authentication** | **PASS** | Sanctum bearer tokens with UUIDv7 primary keys. Tokens stored exclusively as SHA-256 hashes. Mobile device session metadata tracked. Token revocation immediately rejects requests. Inactive user accounts (`is_active=false`) are strictly blocked from authentication and context resolution. |
| **Authorization (RBAC)** | **PASS** | Permissions decoupled from static controller code. Roles (`owner`, `manager`, `cashier`, `staff`, `accountant`) mapped to granular permission slugs. Verified cashier cannot execute owner or finance actions; accountant cannot execute POS sell actions. |
| **Outlet Access** | **PASS** | Cashier and manager accounts restricted to assigned outlets via `AuthorizeOutletAccess`. Owner retains tenant-wide outlet access. Cross-tenant outlet access rejected with 404/403 without data leakage. |
| **Idempotency** | **PASS** | Dual-layer architecture: L1 atomic Redis lock + L2 durable PostgreSQL `idempotency_records` table. Request payload cryptographic fingerprinting (`sha256(method + path + body)`) prevents key reuse with mismatched payloads (returns `409 Conflict`). In-flight requests reject concurrent attempts (`409 Conflict`). Unhandled exceptions mark records as failed, enabling safe retries. |
| **Audit Logging** | **PASS** | `AuditLog` captures operational actions with actor, tenant, entity, request ID, IP, and user agent. Automatic recursive attribute sanitization redacts sensitive keys (`password`, `pin`, `token`, `secret`, `api_key`, `credit_card`) to `[REDACTED]` prior to JSONB persistence. |
| **Queue Context** | **PASS** | `TenantManager::runForTenant($tenant, $callback)` establishes strict transaction-local context and resets in-memory and database tenant context in an outer `finally` block even when worker jobs throw unhandled exceptions. |
| **Offline Storage** | **PASS** | Generic Drift SQLite storage established. Device ID persists across launches via UUIDv7. Outbox state machine enforces valid state transitions and rejects illegal transitions (e.g. `synced -> uploading`). Causal ordering dependencies enforced via `causation_id`. |
| **Secrets Management** | **PASS** | No credentials or secrets committed to repository. `.env` ignored. `.env.example` contains only template placeholders. Flutter app contains no hardcoded backend credentials. |
| **CI / CD Pipeline** | **PASS** | GitHub Actions workflow provisions containerized PostgreSQL and Redis, configures non-superuser `sewaroeng_app` role privileges, and executes Pint, migrations, backend tests, Flutter analysis, formatting, and Flutter tests. |
| **Error Leakage** | **PASS** | Centralized exception handling in `bootstrap/app.php` maps exceptions to the standard JSON API envelope. Fallback renderer for `api/*` suppresses stack traces, file paths, and SQL statements in production (`app.debug=false`). |
| **Rate Limiting** | **PASS** | Public `/api/v1/auth/login` endpoint protected by rate-limiting middleware (`5 attempts/minute`). Health endpoint `/api/v1/health` returns safe operational status without disclosing hostnames, internal IPs, or environment variables. |
| **Local SQLCipher Encryption** | **DEFERRED** | Full SQLCipher database encryption is deferred until Phase 1B/2A when business ledger and sensitive merchant data are persisted locally. Current local storage is strictly limited to device identity and outbox command queue metadata. |

---

## 3. Explicit Security Controls & Policies

### 3.1 Tenancy & RLS Boundaries
- Platform tables (`users`, `tenants`, `user_tenant_memberships`, `roles`, `permissions`, `personal_access_tokens`) operate outside tenant RLS to avoid circular dependency deadlocks during authentication and tenant context resolution.
- Entity tables (`outlets`, `tenant_test_records`, and all future business entities) enforce RLS using:
  ```sql
  tenant_id = nullif(current_setting('app.current_tenant_id', true), '')::uuid
  ```
- Any query executed without `app.current_tenant_id` fails closed.

### 3.2 Idempotency Invariants
1. **At-Most-Once Mutation Guarantee**: A unique `(tenant_id, idempotency_key)` row is inserted with status `processing` inside an atomic lock.
2. **Payload Verification**: Any subsequent request with the same key but different body returns `409 Conflict` (`IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`).
3. **Fault-Tolerant Replay**: If the mutation succeeds, the HTTP status and body are durably persisted in `idempotency_records` and cached in Redis. If the server crashes or the network drops after mutation commit, client retries receive the exact cached response with `X-Idempotent-Replay: true`.

### 3.3 Audit Sanitization Invariant
All payloads passed to `AuditLog::create(['before' => ..., 'after' => ...])` automatically redact keys containing `password`, `passwd`, `pin`, `pin_hash`, `secret`, `token`, `api_key`, `access_token`, `credit_card`, `cvv`, or `authorization`.

---

## 4. Gate Conclusion

**RESULT: PASS**  
The technical foundation is verified secure, isolated, and resilient. The repository is cleared to proceed to **Phase 1A — Core Identity, Business, Outlet & Membership**.
