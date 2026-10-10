# ADR 0014: Loyalty Program, Immutable Points Ledger, and Rewards Architecture

## Status
Accepted

## Date
2026-10-07 (V1)

## Context
Following the PRD v1.0 specifications (Section 15, CRM/Customer 360, Loyalty, and Critical Business Invariants), SeWaroeng requires a tenant-scoped loyalty domain to drive customer retention and engagement across dine-in and counter transactions.

### Key Architectural Invariants & Non-Negotiable Boundaries:
1. **Immutable Point Ledger**: Every point balance mutation MUST be represented as an immutable entry in `loyalty_point_ledgers`. Overwriting `current_balance` directly without a corresponding ledger entry is strictly prohibited. Historical ledger rows cannot be modified or deleted.
2. **Server-Authoritative Downstream Earning**: Points are awarded strictly downstream from authoritative transactions (`Sale finalized + Payment succeeded`). Clients cannot supply or dictate earned points.
3. **Idempotent Point Allocation**: Webhook replays, network retries, and offline transaction syncs must not generate duplicate loyalty points. Uniqueness is enforced at the database level (`uq_loyalty_point_ledgers_sale_earn`).
4. **Atomic & Concurrency-Safe Redemption**: Point redemptions atomically debit the customer balance, insert a negative ledger record, and mint a reward voucher inside a database transaction guarded by pessimistic row locks (`SELECT ... FOR UPDATE`), preventing race conditions and negative balances.
5. **Server-Authoritative Voucher Pricing**: POS voucher discounts are strictly calculated and validated server-side against catalog items and min-spend conditions. Clients cannot submit arbitrary discount figures.
6. **Multi-Tenant Isolation & RLS**: All loyalty tables (`loyalty_programs`, `loyalty_tiers`, `customer_loyalty_accounts`, `loyalty_point_ledgers`, `loyalty_rewards`, `loyalty_vouchers`) enforce PostgreSQL Row-Level Security (RLS) and Eloquent `TenantScope`.
7. **Subscription Entitlement**: Access to loyalty features requires the `'loyalty'` entitlement on the active subscription plan.

---

## Decisions

### 1. Domain Entities & Database Schema

Six normalized entities encapsulate the loyalty domain:

1. **`loyalty_programs`**:
   - `id UUIDv7`, `tenant_id`, `name`, `status` (`active`, `inactive`), `points_per_currency`, `earn_unit_amount_minor`, `minimum_earn_amount_minor`, `redemption_value_minor`, `rounding_policy` (`floor`, `ceil`, `round`), `expiration_days`, `is_active`, `metadata`.
   - Unique per tenant: `UNIQUE (tenant_id)`.
2. **`loyalty_tiers`**:
   - `id UUIDv7`, `tenant_id`, `loyalty_program_id`, `name`, `rank`, `min_points_earned`, `multiplier_bps` (basis points, 10000 = 1.0x), `perks` (JSON), `is_active`.
   - Unique rank per program: `UNIQUE (loyalty_program_id, rank)`.
3. **`customer_loyalty_accounts`**:
   - `id UUIDv7`, `tenant_id`, `customer_id`, `loyalty_program_id`, `tier_id (nullable)`, `current_balance`, `lifetime_earned`, `lifetime_redeemed`, `status` (`active`, `suspended`, `closed`), `joined_at`, `metadata`.
   - Unique customer account per tenant: `UNIQUE (tenant_id, customer_id)`.
   - Balance check constraint: `chk_loyalty_accounts_balance_non_negative (current_balance >= 0)`.
4. **`loyalty_point_ledgers`**:
   - `id UUIDv7`, `tenant_id`, `loyalty_account_id`, `customer_id`, `type` (`purchase`, `redemption`, `campaign`, `referral`, `birthday`, `adjustment`, `expiration`, `reversal`), `points` (signed integer), `balance_after`, `reference_type`, `reference_id`, `idempotency_key`, `notes`, `metadata`, `created_at`.
   - Partial unique indexes for idempotency:
     - `uq_loyalty_point_ledgers_sale_earn`: `(tenant_id, reference_type, reference_id, type)` WHERE `reference_type IS NOT NULL AND reference_id IS NOT NULL`.
     - `uq_loyalty_point_ledgers_idemp`: `(tenant_id, idempotency_key)` WHERE `idempotency_key IS NOT NULL`.
5. **`loyalty_rewards`**:
   - `id UUIDv7`, `tenant_id`, `loyalty_program_id`, `name`, `description`, `points_cost`, `reward_type` (`discount_fixed`, `discount_percent`, `free_item`), `discount_amount_minor`, `discount_percent`, `min_purchase_minor`, `stock`, `validity_days`, `is_active`.
6. **`loyalty_vouchers`**:
   - `id UUIDv7`, `tenant_id`, `customer_id`, `loyalty_account_id`, `reward_id`, `sale_id (nullable)`, `code` (e.g. `VCH-XXXXXX`), `status` (`active`, `used`, `expired`, `cancelled`), `discount_amount_minor`, `discount_percent`, `min_purchase_minor`, `issued_at`, `expires_at`, `used_at`.
   - Unique voucher code per tenant: `UNIQUE (tenant_id, code)`.

---

### 2. Immutable Ledger Invariant & Mutex Enforcement

The points ledger is mathematically append-only:
```text
current_balance = SUM(posted ledger points)
```
- In Laravel Eloquent, `LoyaltyPointLedger::booted()` registers event hooks on `updating` and `deleting` that throw `RuntimeException` ("Loyalty point ledger is strictly immutable and cannot be updated or deleted.").
- Corrections to balances cannot modify historical rows; they must issue a compensating ledger entry (`type = 'adjustment'` or `'reversal'`) with an audit reason and authenticated staff actor.
- `LoyaltyService::reconcileAccountBalance(string $accountId)` provides an audit query verifying that `current_balance === SUM(points)`. Discrepancies are flagged without silent artificial adjustment.

---

### 3. Point Earning Lifecycle from POS Sales

Point accumulation is strictly downstream and event-driven:
1. **Triggering Event**: Handled on `SaleFinalized` and `PaymentSucceeded`.
2. **Eligibility Criteria**:
   - The sale must be associated with a valid customer (`customer_id IS NOT NULL`).
   - The sale must be in `finalized` status and fully settled (`settlement_status === 'paid'`).
   - The tenant must have an active `LoyaltyProgram` and `'loyalty'` entitlement.
   - The paid total must satisfy `minimum_earn_amount_minor`.
3. **Calculation Formula**:
   $$\text{base\_points} = \lfloor \frac{\text{paid\_amount\_minor}}{\text{earn\_unit\_amount\_minor}} \rfloor \times \text{points\_per\_currency}$$
   $$\text{earned\_points} = \lfloor \frac{\text{base\_points} \times \text{tier.multiplier\_bps}}{10000} \rfloor$$
4. **Tier Evaluation**: Following earning, `evaluateTierTransition()` compares `account.lifetime_earned` against defined tier thresholds and automatically promotes the customer when eligible, recording a structured audit log.

---

### 4. Concurrency-Safe Reward Redemption & Canonical Voucher Lifecycle

1. **Redemption Atomicity**:
   - When a customer redeems a reward (`POST /api/v1/loyalty/customers/{id}/redeem`), the customer's `CustomerLoyaltyAccount` is locked with `lockForUpdate()`.
   - If `current_balance < reward.points_cost`, redemption is rejected with HTTP 422 (`INSUFFICIENT_LOYALTY_POINTS`).
   - If sufficient, points are atomically deducted, a negative ledger entry (`type = 'redemption', points = -cost`) is inserted, and a `LoyaltyVoucher` with an unguessable unique code is minted in `active` status.

2. **Canonical Voucher State Model**:
   Voucher lifecycle transitions through explicit states:
   ```text
   active (issued)
     │
     ▼ (POS applies voucher to open sale)
   reserved (attached to active sale, used_sale_id = sale.id)
     ├── (Sale cancelled / unapplied / payment fails & sale abandoned)
     │     └──► reverts to active (reservation released, used_sale_id = null)
     │
     ▼ (Payment succeeded / settled)
   used (permanently consumed, used_at = now())
   ```

3. **POS Voucher Application & Removal**:
   - **Apply Voucher** (`POST /api/v1/pos/sales/{id}/apply-voucher`):
     - Validates tenant, customer ownership, voucher status (`active` or already reserved by this exact sale), expiration, reward validity, and minimum purchase.
     - Calculates discount server-side (`discount_amount_minor` or percentage calculated against subtotal).
     - Transitions voucher to `reserved` and attaches `used_sale_id = sale.id`.
     - Invariant: Sale finalization alone DOES NOT mark the voucher as `used` unless payment is already fully settled.
   - **Remove Voucher** (`DELETE /api/v1/pos/sales/{id}/voucher`):
     - Clears discount on open sale, recalculates total, and transitions voucher back to `active`, unsetting `used_sale_id`.

4. **Payment Success Invariant**:
   - Handled via `PaymentSucceeded` and full-settlement listener in `LoyaltyService::consumeVoucherForSale(Sale $sale)`.
   - Under pessimistic row lock (`lockForUpdate()`), transitions voucher from `reserved` to `used` and records `used_at = now()`.
   - **Idempotency**: Repeated `PaymentSucceeded` events on the same sale execute exactly once; already consumed vouchers are unaffected.

5. **Payment Failure & Sale Cancellation Safety**:
   - **Payment Failure**: If payment fails, the voucher remains `reserved` for retry on the current sale. If the sale is subsequently cancelled (`SaleCancelled` event) or the cashier unapplies the voucher, `LoyaltyService::releaseVoucherReservation(Sale $sale)` automatically reverts the voucher back to `active` (`used_sale_id = null`).
   - Invariant: A customer never loses an earned voucher due to an aborted or failed payment.

6. **Payment Refund Policy**:
   - **Case A (Payment never succeeded)**: Voucher remains usable; reservation is released.
   - **Case B (Payment succeeded, voucher consumed)**: When a financial refund occurs (`PaymentRefunded`), the promotional discount voucher remains `used`. Financial money is returned to the customer according to refund rules, but promotional loyalty discount vouchers are not reinstated.

---

### 5. Milestone Scope Boundaries: V1 Loyalty Core

This milestone is strictly designated as **V1 Loyalty Core**.

#### Implemented & Hardened:
- **Points**: Point ledger immutability, downstream earning on finalized & paid sales, balance reconciliation audit, and manual point adjustment with mandatory audit reasons.
- **Tiers**: Multi-tier hierarchy, basis point multipliers (`multiplier_bps`), automatic progression based on lifetime earned points.
- **Rewards**: Reward catalog with fixed minor discount, percentage discount, min purchase validation, and stock control.
- **Vouchers**: Issuance, atomic redemption, POS server-authoritative discount, reservation lifecycle, payment failure safety, and unapply endpoints.
- **Customer Integration**: Customer 360 loyalty profile, Flutter mobile UX (points, tier, rewards, vouchers, ledger, redeem modal).

#### Explicitly Deferred (Future Milestones):
- **Scheduled Expiration Worker**: Expiration days are configurable in program settings; actual background cron worker for automatic expiration remains deferred (no silent drops).
- **Campaign Engine / Coupons**: Advanced promotional coupon codes and automated campaigns remain deferred.
- **Birthday & Anniversary Campaigns**: Automated trigger-based campaigns remain deferred.
- **Referrals**: Referral code generation and tracking remain deferred.
- **Cashback-like Promotions**: Advanced rebate engines remain deferred.

---

### 6. Offline Sync Behavior

Offline mobile clients cannot calculate, mint, or adjust loyalty balances locally:
- Offline POS syncs normal offline sales to the server through the existing transactional outbox (`/api/v1/sync`).
- The server validates stock, creates the finalized `Sale`, settles payment, and downstream triggers `LoyaltyService::awardPointsForSale()`.
- Idempotency keys derived from the authoritative sale UUID guarantee that repeated offline batch syncs do not award duplicate points.

---

### 7. RBAC Permissions Matrix

- `loyalty.view`: View program configs, tiers, rewards, customer accounts, and ledgers (Cashier, Manager, Owner).
- `loyalty.manage`: Configure program settings, create/edit tiers, and create/edit rewards (Manager, Owner).
- `loyalty.adjust`: Perform manual point adjustments with mandatory audit reason (Manager, Owner only; Cashier default denied).
- `loyalty.redeem`: Redeem rewards on behalf of a customer at POS counter (Cashier, Manager, Owner).
- `loyalty.report`: Access loyalty reconciliation and audit metrics (Manager, Owner).

---

## Consequences

### Positive
- Fully satisfies PRD v1.0 Section 15 and Critical Business Invariants.
- Guaranteed balance integrity through immutable ledger append-only design.
- Zero race condition vulnerabilities during redemption under high concurrency.
- Voucher is never permanently consumed if payment fails or sale is cancelled.
- Full offline resilience without creating complex offline ledger divergence.
- Clean Customer 360 integration displaying tier, balance, lifetime metrics, and vouchers.

### Trade-offs / Limitations
- Direct SQL manual mutations bypassing Eloquent require database triggers or DB-level read-only permissions for absolute immutability.
- Program configurations are currently tenant-wide rather than outlet-specific, matching the single-program V1 scope.
- Automated point expiration worker is deferred; expiration policy is documented and awaiting cron scheduling.
