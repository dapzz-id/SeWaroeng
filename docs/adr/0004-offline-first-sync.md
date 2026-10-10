# ADR-0004: Offline-First Architecture and Synchronization Engine

## Status
Accepted

## Context
In Indonesian F&B environments, unstable internet connectivity, sudden fiber-optic cuts, or temporary power drops are everyday operational realities. If a POS system freezes or blocks ordering when the internet drops, merchant operations immediately grind to a halt, leading to lost revenue and customer frustration.

According to the SeWaroeng PRD (`docs/SeWaroeng_PRD_v1.0.md`) and Master Instruction:
1. The POS must support offline-first architecture for critical cashier operations (ordering, cash payment, receipt printing, shift management).
2. Synchronization must be idempotent: repeated sync attempts must never create duplicate transactions, double payments, or corrupted inventory ledgers.
3. The server remains the sole authoritative source of truth for financial posting, accounting journals, final payment status, and stock valuation.
4. "Last Write Wins" (LWW) is strictly unacceptable for financial ledgers, inventory balances, and order histories.
5. Dependent operations (e.g., `Create Order` -> `Record Payment`) must execute in strict causal order.

---

## Architectural Options Evaluated

### Option A: Online-Only Architecture with HTTP Retry
The POS requires an active internet connection for every mutation. If disconnected, requests queue in memory and retry.
* **Verdict: Rejected.** Incompatible with Indonesian MSME operational realities. Memory queues are lost on app restart or OS termination, leading to catastrophic transaction loss.

### Option B: State-Based Synchronization (Document / Entity Replicas)
The client synchronizes whole entity states (e.g., uploading the updated `Order` or `Product` object) and attempts two-way merging (CouchDB / WatermelonDB / PouchDB style).
* **Verdict: Rejected.** State-based sync loses business intent. It leads to silent record overwriting, race conditions on shared balances (e.g., inventory quantity overwritten instead of mutated via movement delta), and breaks double-entry accounting integrity.

### Option C: Transactional Outbox with Operation-Based (Command) Synchronization
The client stores local domain entities in a local SQLite database (via Drift ORM) and, in the exact same local ACID transaction, appends an immutable business command to a local `sync_outbox` table. A dedicated background sync worker reads the outbox, sends idempotent commands to the Laravel API, and updates local state upon authoritative server confirmation.
* **Verdict: Accepted.** Preserves business intent, guarantees atomic local persistence, maintains causal ordering, and enables bulletproof idempotency.

---

## Decision

We adopt **Option C: Transactional Outbox with Command-Based Synchronization (Drift/SQLite in Flutter -> Laravel REST API -> PostgreSQL)**.

```
+---------------------------------------------------------------------------------------+
|                                OFFLINE POS SYNC LIFECYCLE                             |
+---------------------------------------------------------------------------------------+

       FLUTTER CLIENT (Offline-Capable POS)                    CENTRAL LARAVEL BACKEND
 +---------------------------------------------+          +--------------------------------+
 | 1. Cashier Action (e.g., Submit Order)     |          |                                |
 +---------------------------------------------+          |                                |
                        |                                 |                                |
                        v                                 |                                |
 +---------------------------------------------+          |                                |
 | 2. Local ACID Transaction (Drift / SQLite)  |          |                                |
 |    - Insert Order & OrderItems              |          |                                |
 |    - Generate Client UUIDv7 for all entities|          |                                |
 |    - Decrement local optimistic stock cache |          |                                |
 |    - Append to `sync_outbox` (state: PENDING)|          |                                |
 +---------------------------------------------+          |                                |
                        |                                 |                                |
                        v                                 |                                |
 +---------------------------------------------+          |                                |
 | 3. Sync Worker (Background Pipeline)        |          |                                |
 |    - Polls or triggered by connectivity     |          |                                |
 |    - Acquires Local Sync Mutex Lock         |          |                                |
 |    - Fetches next PENDING commands (FIFO)   |          |                                |
 |    - State -> UPLOADING                     |          |                                |
 +---------------------------------------------+          |                                |
                        |                                 |                                |
                        | 4. POST /api/v1/sync            |                                |
                        |    Body: { client_id, operations }                              |
                        |    Each op: { id, aggregate_id, |                                |
                        |               idempotency_key } |                                |
                        +-------------------------------->| 5. Laravel Idempotency Layer   |
                                                          |    - Check DB IdempotencyRecord|
                                                          |    - If processed: cached resp |
                                                          +--------------------------------+
                                                                          |
                                                                          v
                                                          +--------------------------------+
                                                          | 6. Domain Execution Pipeline   |
                                                          |    - DB::transaction           |
                                                          |    - Enforce Tenant/Outlet/RLS |
                                                          |    - Validate Server Open Shift|
                                                          |    - Block Online Gateways     |
                                                          |    - Finalize Sale via POS svc |
                                                          |    - Authoritative Stock Ledger|
                                                          |    - Authoritative Finance Post|
                                                          |    - Official Receipt Number   |
                                                          +--------------------------------+
                                                                          |
                        +-------------------------------------------------+
                        | 7. 200 OK Response with Results Array:
                        |    status: accepted | already_processed | blocked | retryable
                        |    code: SALE_SYNCED | INSUFFICIENT_STOCK | SHIFT_NOT_AVAILABLE
                        v
 +---------------------------------------------+
 | 8. Outbox Finalization                      |
 |    - 'accepted' / 'already_processed' ->    |
 |      Outbox state -> SYNCED, local sale     |
 |      marked 'synced' with server timestamp  |
 |    - 'blocked' -> Outbox REQUIRES_ACTION,   |
 |      local sale marked 'needsReview' with   |
 |      explicit error code (never deleted)    |
 +---------------------------------------------+
```

---

## Detailed Implementation Mechanics

### 1. The Local Outbox State Machine
Every mutating command in the client outbox transitions through a strict lifecycle:

```text
               +----------> FAILED (Network timeout / Transient 5xx)
               |               |
               |               v (Exponential Backoff Retry)
[PENDING] ---> [UPLOADING] ----+
                   |
                   +----------> SYNCED (200 OK Authoritative Confirmation)
                   |
                   +----------> CONFLICT (Business rule collision on server)
                   |               |
                   |               v
                   +----------> REQUIRES_ACTION (Alerts Manager / Cashier for review)
```

* `pending`: Command committed locally in SQLite; waiting for sync worker pickup.
* `uploading`: Payload in transit; locked to prevent duplicate upload attempts.
* `synced`: Server verified and committed the transaction; local record reconciled.
* `failed`: Transient failure (network drop, HTTP 502/503/504). Automatically scheduled for retry with exponential backoff (e.g., 2s, 5s, 15s, 60s, max 5 min).
* `conflict`: Business validation failed on the server (e.g., closed register, terminal suspended). Requires domain-specific reconciliation.
* `requires_action`: Irreconcilable conflict requiring human intervention (e.g., manager approval for inventory overdraft).

### 2. Client-Generated Identifiers & Payload Structure
1. Every entity created offline (orders, items, payments, cash movements) is assigned a client-generated **UUIDv7** locally.
2. The `sync_outbox` table in SQLite schema:
   ```sql
   CREATE TABLE sync_outbox (
       command_id TEXT PRIMARY KEY,          -- UUIDv7
       idempotency_key TEXT NOT NULL UNIQUE,  -- UUIDv7 transit key
       tenant_id TEXT NOT NULL,
       outlet_id TEXT NOT NULL,
       device_id TEXT NOT NULL,
       command_type TEXT NOT NULL,           -- e.g., 'orders.create', 'payments.record'
       sequence_number INTEGER NOT NULL,     -- Strict sequential counter per device
       parent_command_id TEXT,               -- For causal chaining
       payload TEXT NOT NULL,                -- Encrypted JSON representation of command
       status TEXT NOT NULL,                 -- 'pending','uploading','synced','failed','conflict','requires_action'
       retry_count INTEGER DEFAULT 0,
       error_message TEXT,
       created_at INTEGER NOT NULL,          -- Epoch milliseconds
       synced_at INTEGER
   );
   ```

### 3. Causal Ordering & Dependent Execution
A cashier frequently creates an order and immediately receives cash payment before the network reconnects:
```text
Command A: orders.create (id: 01924b12...)
Command B: payments.record (order_id: 01924b12..., parent_command_id: 01924b12...)
```
* **Ordering Rule:** The sync worker processes outbox items in strict ascending order of `sequence_number`.
* **Causal Dependency:** If Command B references `parent_command_id: Command A`, the sync worker will **never** transmit Command B until Command A has achieved status `synced`.
* **Batching:** The sync engine supports sending dependent command pipelines in a single atomic batch payload (`POST /api/v1/sync/batch`), allowing the backend to execute both inside a single database transaction.

### 4. Conflict Resolution Matrix

Under no circumstances is blind "Last Write Wins" (LWW) permitted. The following rules govern domain conflicts:

| Domain Event | Conflict Scenario | Resolution Policy | Ledger Action |
| :--- | :--- | :--- | :--- |
| **Inventory Stock** | Stock ran out on server while POS was offline selling the item. | **Operational Precedence:** The physical customer already has the goods. The sale is accepted. | Server appends an inventory movement with reason `sale`, allowing negative stock temporarily. Generates a `STOCK_DEFICIT_ALERT` for manager audit and opname. |
| **Catalog Price** | Product price increased on server while POS was offline with old price. | **Customer Transparency:** The price shown to the customer at the time of sale is honored. | Server accepts line item with `sold_price`. Variance is logged in an audit table as `OFFLINE_PRICE_DISCREPANCY` for owner review. |
| **Cash Payment** | Cash payment recorded offline. | **Authoritative Acceptance:** Physical cash is in the drawer. | Server records the payment in the Payment Ledger upon sync. Cash balance reconciles with shift. |
| **Digital Payment** | QRIS / EDC payment. | **Strict Online Requirement:** Digital payments require direct confirmation from payment provider. | POS does NOT finalize digital payment while offline unless external EDC printed a verified physical receipt, requiring manager PIN confirmation. |
| **Shift Closing** | Shift closed offline with discrepancy. | **Acceptance with Audit:** Shift close record stored as submitted. | System generates an un-editable `SHIFT_VARIANCE` journal entry upon sync. |

### 5. Local Database Caching Boundary (Least Privilege Caching)
To protect merchant data on mobile tablets, local SQLite storage is restricted to operational necessities:
* **Cached Locally:**
  * Active product catalog (names, SKUs, barcodes, variants, prices, taxes).
  * Category hierarchies.
  * Active customer directory (name, phone, loyalty points).
  * Current open shift and transactions created on *this device* for the active shift.
  * Cashier staff roster (names and hashed PINs for offline switching).
* **Never Cached Locally:**
  * Historic company-wide accounting journals.
  * Supplier bank account numbers and purchase costs (HPP).
  * Platform-wide analytics or owner profit metrics.
  * Transactions from other outlets or past months.

### 6. Security, Theft, and Session Invalidation

1. **Local Database Encryption:** On Android devices, SQLite is encrypted at rest using SQLCipher, with the key derived securely from the Android KeyStore.
2. **Device Logout / Staff Revocation:**
   * If a device is marked `revoked` by the owner, the very next API communication returns `401 Unauthorized` (`DEVICE_REVOKED`).
   * The Flutter app immediately triggers a secure purge of the local SQLite database and returns to the initial onboarding screen.
3. **Offline Auth Expiration:**
   * An offline POS is permitted to operate without network re-authentication for a maximum grace period of **72 consecutive hours**.
   * If offline for > 72 hours, POS creation is locked until a brief network handshake revalidates the device token.

---

## Recovery Scenarios & Failure Modes

### Scenario 1: App Crashes Mid-Transaction
* **Condition:** Power dies or app process is killed while the cashier hits "Complete Order".
* **Recovery:** SQLite's Write-Ahead Logging (WAL) guarantees atomic commit. Either both the local order and the outbox record were committed, or neither was. Upon app reboot, the state is consistent.

### Scenario 2: Network Disappears During Sync (Response Lost)
* **Condition:** Client uploads `orders.create`. Server processes and commits the transaction, but the network drops before the HTTP 200 response reaches Flutter.
* **Recovery:**
  1. The client marks the command as `failed` due to network timeout.
  2. The sync worker retries the upload with the **exact same `Idempotency-Key`**.
  3. Laravel's `IdempotencyMiddleware` intercepts the key, finds the existing completed transaction in the database, and immediately returns the cached `200 OK` response with the assigned `order_number`.
  4. The client marks the outbox command as `synced`. No duplicate order is created.

### Scenario 3: Concurrent Sync Workers Run Simultaneously
* **Condition:** A background sync timer triggers while a manual "Sync Now" button is pressed.
* **Recovery:** The Flutter sync service implements an in-memory Mutex and an SQLite lock flag (`is_syncing = true`). Only one worker can acquire the outbox lock at any moment.

### Scenario 4: Token Expires While Commands Are Pending in Outbox
* **Condition:** Refresh token fails or Sanctum token expired after prolonged offline status.
* **Recovery:**
  1. API returns `401 Unauthorized`.
  2. The sync worker pauses all outbox uploads.
  3. An application-wide event prompts the manager/cashier: *"Sesi telah berakhir. Masukkan kredensial untuk melanjutkan sinkronisasi."*
  4. The local outbox records remain intact in SQLite. Once the user re-authenticates and a new token is stored, the sync worker resumes from the exact point of pause without data loss.

---

## Consequences

### Positive
* High business resilience: Indonesian merchants can transact without downtime during internet disruptions.
* Guaranteed data integrity: Zero duplicate orders or orphaned payments due to robust client UUIDv7 and server idempotency keys.
* Deterministic financial ledgers: Ledger entries are appended authoritatively on the server, avoiding state corruption.
* Transparent auditability: Every sync conflict or discrepancy is recorded with full operational context.

### Negative / Trade-offs
* Increased client-side architecture complexity: requires maintaining Drift ORM tables, outbox queues, mutexes, and state machines in Flutter.
* Negative inventory balances may temporarily occur when concurrent offline devices sell the last remaining stock (acceptable trade-off in F&B, mitigated via management opname alerts).
