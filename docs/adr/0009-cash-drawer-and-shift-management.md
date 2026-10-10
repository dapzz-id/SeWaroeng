# ADR 0009: Cash Drawer & Shift Management Architecture

## Status
Accepted

## Context
In F&B and retail counter operations, cashiers operate within shifts where physical cash floats are issued, cash sales and refunds occur, operational cash movements (cash-in/cash-out) take place, and shifts are closed with physical cash counts and variance calculation.

Prior to Phase 1F, SeWaroeng had implemented:
- Offline-first POS sales and inventory consumption (Phase 1B & 1C)
- Authoritative Payment foundation, security hardening, and multi-provider gateway (Phase 1D, 1E, 1E.3.1)

However, cash operational impact was untracked, and cashier shift management was absent.

## Decision

### 1. Unified Aggregate Boundary: Shift as the Cash Session Authority
Rather than introducing redundant hardware abstraction layers (separate hardware drawer entities vs shift entities), the **`Shift` aggregate** directly represents the operational session for an outlet's cash drawer.
- **One Open Shift Invariant:** Enforced at the database level by a PostgreSQL partial unique index:
  ```sql
  CREATE UNIQUE INDEX uq_shifts_active_outlet ON shifts (outlet_id) WHERE status = 'open';
  ```
- Application code serializes concurrent operations with pessimistic locking (`lockForUpdate()`), but the partial index guarantees zero race condition escape.
- Database CHECK constraints enforce state invariants (`chk_shifts_state_invariants` ensures `closed` shifts have non-null `closed_at` and `closing_cash_minor`).

### 2. Strict Domain Separation: Payment vs Cash Drawer
The `Payment` domain remains authoritative for sales transaction settlement:
- A cash drawer record never alters or finalizes payment state.
- When `PaymentService` successfully finalizes a payment with `method = 'cash'`, it atomically records a `CashMovement(type = sale, direction = in)` against the outlet's active shift.
- Non-cash payment methods (QRIS, VA, E-Wallet, Card) create **zero** cash movements.
- Cash refunds invoke `CashMovement(type = refund, direction = out)`. Non-cash refunds do not impact the physical drawer.
- Duplicate recording is rejected via composite uniqueness on `(shift_id, reference_type, reference_id)`.

### 3. Immutable Cash Movement Ledger
Financial history in `cash_movements` is strictly append-only:
- No `updated_at` column exists; `UPDATE` and `DELETE` are disallowed.
- Corrections must be recorded as compensating movements (`type = adjustment`), requiring the elevated `cash.adjust` permission.
- All monetary amounts use 64-bit integer minor units (`BIGINT`), with CHECK constraints enforcing non-negative values.

### 4. Online-Only Cash Management vs Offline POS Sales
- **POS Sales:** Retain offline-first capability via the local outbox sync queue.
- **Cash Drawer Operations:** (Open shift, cash in, cash out, adjustment, close shift) are strictly **ONLINE-ONLY**.
- **Rationale:** Allowing offline cash-outs or offline shift closures introduces catastrophic distributed anomalies (e.g. double cash-outs exceeding actual physical float, desynchronized closing variances). Until stronger distributed causal conflict-free types exist for drawer funds, physical cash mutations require real-time server authorization.

### 5. Deterministic Expected Cash Calculation
The server is the authoritative calculator for expected drawer balances:
$$\text{Expected Closing} = \text{Opening} + \text{Cash Sales} + \text{Cash In} - \text{Cash Out} - \text{Cash Refund} \pm \text{Adjustments}$$
$$\text{Variance} = \text{Actual Closing Count} - \text{Expected Closing}$$
Clients only provide physical counts and format numbers; Flutter never calculates financial truth.

## Consequences
- **Positive:** Guarantees zero duplicate open shifts, zero unrecorded cash impacts, and immutable auditability.
- **Positive:** Prevents cash drawer desynchronization by keeping cash movements online-only.
- **Limitation:** Cashiers cannot open/close shifts or perform manual cash in/out when entirely disconnected from the network (POS cash sales can still be queued locally).
