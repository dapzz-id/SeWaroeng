# ADR-0002: Identifier and Primary Key Strategy

## Status
Accepted

## Context
SeWaroeng requires a unified identifier strategy across its distributed architecture, which consists of:
1. A central Laravel + PostgreSQL backend serving tens of thousands of tenants.
2. Distributed, offline-capable Flutter POS clients running on Android devices with local SQLite databases.
3. Multiple operational ledgers (Inventory Movement, Payment Ledger, Accounting Journal) requiring high insert throughput and strict chronological integrity.
4. Offline transaction creation where POS devices create orders, line items, and payments without immediate connectivity to the central server.
5. Idempotent synchronization to guarantee network retries never produce duplicate transactions or unbalanced ledger entries.

---

## Architectural Options Evaluated

### Option A: 64-bit Auto-Incrementing Integers (BIGINT)
* **Pros:** Highly compact (8 bytes); maximum B-tree index density and locality in PostgreSQL; optimal join performance.
* **Cons:**
  * **Zero offline feasibility:** Distributed clients cannot generate unique IDs independently without central coordination or pre-allocated ranges, which fail during prolonged offline periods.
  * **Business intelligence leakage:** Sequential IDs (e.g., `/api/v1/orders/1054`) expose company transaction velocity to competitors and malicious actors.
  * **Sharding/federation barrier:** Merging or syncing distributed databases with overlapping auto-increments causes primary key collisions.

### Option B: Universally Unique Identifier Version 4 (UUIDv4)
* **Pros:** 128-bit global uniqueness; generated completely offline by clients; native `uuid` type in PostgreSQL (16 bytes); RFC-standardized.
* **Cons:**
  * **Complete lack of index locality:** Completely random 128-bit values cause massive B-tree page splits, cache thrashing, and high write amplification in PostgreSQL as tables grow beyond available RAM.
  * **Non-sortable:** Cannot be naturally ordered by creation time; requires an additional indexed timestamp column for chronological queries.

### Option C: Universally Unique Lexicographically Sortable Identifier (ULID)
* **Pros:** 128-bit uniqueness; millisecond-level time-ordered prefix (48 bits); Crockford's Base32 string representation (26 characters); URL-safe and human-readable; case-insensitive.
* **Cons:**
  * **Database storage mismatch:** PostgreSQL does not have a native `ulid` data type. ULID strings take 26 bytes of text storage (or require binary/UUID casting functions on every query).
  * **Ecosystem divergence:** Storing ULID as text increases index size by ~62% compared to native 16-byte UUID columns. Standard tooling expects 36-character hyphenated UUIDs.

### Option D: Universally Unique Identifier Version 7 (UUIDv7 - RFC 9562)
* **Pros:**
  * **Native 16-byte storage:** Uses PostgreSQL's built-in `UUID` column type, maximizing index efficiency and caching.
  * **Time-ordered prefix:** First 48 bits encode a Unix millisecond timestamp, providing sequential index insertion and preventing B-tree page fragmentation.
  * **Distributed & offline-safe:** 74 bits of entropy provide collision-free offline generation across unlimited POS devices.
  * **Native framework support:** Out-of-the-box support in Laravel 11 (`Str::uuid7()`, `HasUuids` trait) and modern Dart/Flutter (`uuid` package v4.3+).
  * **Standard formatting:** Standard 36-character hyphenated hex representation (`018f...-....-7...-....-............`).

---

## Decision

We adopt **UUIDv7 (RFC 9562)** as the standard primary key and entity identifier across the entire SeWaroeng platform, combined with an **explicit, separate Idempotency Key strategy** and **human-readable business reference codes**.

```
+---------------------------------------------------------------------------------------+
|                                    IDENTIFIER TAXONOMY                                |
+---------------------------------------------------------------------------------------+
|                                                                                       |
|  1. Primary Key / Entity ID (UUIDv7)                                                  |
|     - Type: Native PostgreSQL UUID (16 bytes) / 36-char string in APIs & Flutter      |
|     - Format: 01924b12-9c3a-7f28-8d32-28e75a9b1c04                                   |
|     - Generated: Server-side for online entities; Client-side for offline POS entities|
|                                                                                       |
|  2. Human-Readable Reference Code (Authoritative Business Number)                     |
|     - Format: {PREFIX}-{DATE}-{COUNTER} (e.g., SW-20261003-000123)                    |
|     - Generated: Authoritatively by Laravel backend upon sync/commit                   |
|     - Used for: Physical receipts, invoice lookups, cashier communication             |
|                                                                                       |
|  3. Idempotency Key (UUIDv4 or UUIDv7)                                                |
|     - Location: HTTP Header `Idempotency-Key`                                         |
|     - Lifetime: 24 hours in Redis / PostgreSQL Idempotency Ledger                     |
|     - Used for: Guaranteeing exactly-once API command execution on retries            |
|                                                                                       |
+---------------------------------------------------------------------------------------+
```

---

## Detailed Implementation Mechanics

### 1. Primary Key & Storage Specifications
* In PostgreSQL migrations:
  ```php
  $table->uuid('id')->primary();
  $table->uuid('tenant_id')->index();
  ```
* In Eloquent Models:
  ```php
  use Illuminate\Database\Eloquent\Concerns\HasUuids;
  use Illuminate\Support\Str;

  abstract class BaseModel extends Model {
      use HasUuids;

      public function newUniqueId(): string {
          return (string) Str::uuid7();
      }
  }
  ```
* In Flutter / Dart:
  ```dart
  import 'package:uuid/uuid.dart';

  final uuid = Uuid();
  final String entityId = uuid.v7(); // Standard RFC 9562 UUIDv7
  ```

### 2. Client-Side Generation for Offline POS Entities
Distributed POS devices create business entities while offline (e.g., `orders`, `order_items`, `payments`, `shifts`, `inventory_movements`).
1. The Flutter POS generates a UUIDv7 locally and stores it as the primary key `id` in the local SQLite (Drift) database.
2. During synchronization, the client pushes the entity with its client-generated `id`.
3. The Laravel backend verifies that the ID is a valid UUIDv7 format.
4. If the record with that `id` does not exist for this tenant, the server inserts it using the client's `id`.
5. This eliminates ID mapping tables, avoids re-keying child records (e.g., `order_items` referencing `order_id`), and guarantees that the client's local reference matches the server's record exactly.

### 3. Separation of Entity ID vs. Idempotency Key
An Entity ID and an Idempotency Key serve distinct architectural roles and **must not be equated**:

| Concept | Entity ID (`id`) | Idempotency Key (`Idempotency-Key`) |
| :--- | :--- | :--- |
| **Scope** | Persistent database identity of a business entity | Ephemeral transit token for an HTTP mutation request |
| **Lifecycle** | Permanent (lifetime of the record) | Ephemeral (typically 24 hours in Redis/DB) |
| **Semantics** | "What is the noun in the database?" | "Has this specific verb execution already occurred?" |
| **Example** | `order.id = 01924b12-...` | `Idempotency-Key: 7b31d8e2-...` |

#### Idempotency Key Handling:
1. Every mutating API request (POST/PUT/PATCH) originating from Flutter or web hooks includes the `Idempotency-Key: <UUID>` header.
2. An `IdempotencyMiddleware` intercepts the request:
   * Hashes `tenant_id + user_id + request_method + route + Idempotency-Key`.
   * Acquires an atomic lock in Redis.
   * If an entry exists with status `completed`, returns the cached HTTP response code and payload immediately without re-executing domain logic.
   * If an entry is currently `in_flight`, returns `409 Conflict` (`REQUEST_IN_PROGRESS`) with a `Retry-After: 2` header.
   * If the entry does not exist, registers the key, allows the request to process within a database transaction, stores the serialized response, and unlocks.

### 4. Human-Readable Operational Reference Codes
UUIDv7 strings are unwieldy for human speech (e.g., a cashier calling out an order to a barista or customer reading a receipt).
* Authoritative business reference codes are generated server-side:
  * Orders: `ORD-{OUTLET_CODE}-{YYYYMMDD}-{DAILY_SEQUENCE}` (e.g., `ORD-KMY-20261003-0042`)
  * Invoices: `INV/{YYYYMMDD}/{TENANT_CODE}/{SEQUENCE}`
  * Purchase Orders: `PO-{YYYYMM}-{SEQUENCE}`
* When an order is created offline in Flutter, the client displays a temporary local receipt code: `LOCAL-{TIMESTAMP_HEX}`. Upon sync, the server assigns and returns the authoritative `order_number`, which the POS updates locally.

---

## Database Migration & Indexing Implications

1. **B-Tree Locality:** Because UUIDv7 contains a millisecond timestamp in the leading 48 bits, new inserts are sequentially appended to the rightmost leaf of the B-Tree index. This matches the write efficiency of `BIGINT` auto-increments and avoids random I/O page splits.
2. **Compound Indexes:** In multi-tenant queries, compound indexes must place `tenant_id` first, followed by the entity's UUIDv7:
   ```sql
   CREATE INDEX idx_orders_tenant_created ON orders (tenant_id, id);
   ```
   Since `id` is time-ordered, queries such as `WHERE tenant_id = ? ORDER BY id DESC LIMIT 20` execute as high-performance index-range scans without requiring an auxiliary sort step.

---

## Consequences

### Positive
* Universal identifier compatibility across Flutter (Dart), Laravel (PHP), and PostgreSQL.
* High-performance PostgreSQL indexing without write amplification or page fragmentation.
* Distributed offline creation without ID conflicts or post-sync ID reconciliation.
* Natural chronological sorting capability directly from the primary key.
* Strict idempotency protection preventing duplicate financial mutations.

### Negative / Trade-offs
* Larger storage footprint than 8-byte `BIGINT` (16 bytes per UUID; compound indexes are slightly larger).
* Developers must remember to use `uuid` columns instead of auto-incrementing integers in all migrations.
