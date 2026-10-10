# SeWaroeng — Product Requirements Document (PRD)

**Dokumen:** Product Requirements Document  
**Produk:** SeWaroeng  
**Versi:** 1.0  
**Status:** Draft untuk Team Review & AI Coding Agent  
**Tanggal:** 3 Oktober 2026  
**Target:** UMKM Indonesia, dimulai dari F&B (coffee shop, kedai, restaurant, bakery), lalu diperluas ke retail, jasa, dan produsen/home industry.  
**Backend:** Laravel  
**Frontend:** Flutter  
**Platform awal:** Android + Web admin/owner melalui Flutter Web bila disetujui arsitektur; arsitektur API-first wajib.  
**Dokumen ini:** Source of truth untuk product scope. Detail implementasi teknis boleh berubah melalui ADR (Architecture Decision Record), tetapi acceptance criteria produk tidak boleh berubah tanpa review.

---

## 1. Ringkasan Produk

SeWaroeng adalah SaaS Business Operating System untuk UMKM Indonesia. Produk menggabungkan operasional harian bisnis dalam satu platform:

- POS / kasir
- Penjualan offline dan online
- Inventory
- Purchasing & supplier
- Recipe/BOM & HPP
- CRM & Customer 360
- Employee & shift management
- Digital payment integration
- Booking/table management
- Pembukuan dan laporan keuangan
- Business analytics
- AI Business Copilot
- WhatsApp integration
- Multi-outlet
- SeWaroeng Discover / consumer layer
- Crowd intelligence berbasis sinyal agregat dan opt-in

Prinsip produk:

> SeWaroeng bukan sekadar aplikasi kasir. SeWaroeng adalah sistem operasi untuk menjalankan, memahami, dan mengoptimalkan bisnis UMKM.

---

# 2. Problem Statement

UMKM sering menjalankan bisnis melalui banyak alat yang terpisah:

- POS/kasir
- Excel/Google Sheets
- WhatsApp
- Instagram
- marketplace
- aplikasi pembayaran
- catatan manual
- aplikasi accounting
- kalender untuk booking

Akibatnya:

1. Data penjualan tidak selalu sinkron dengan stok.
2. Owner kesulitan mengetahui laba aktual.
3. Data customer tersebar.
4. Pengadaan barang masih reaktif.
5. Karyawan/shift sulit diawasi.
6. Rekonsiliasi pembayaran menghabiskan waktu.
7. Owner harus membuka banyak aplikasi.
8. Data historis belum dimanfaatkan untuk forecasting dan keputusan bisnis.
9. Bisnis multi-outlet sulit dipantau secara terpusat.
10. Customer tidak memiliki pengalaman terpadu untuk order, booking, loyalty, dan menemukan merchant.

---

# 3. Tujuan Produk

## 3.1 Business Goals

### G1 — Menjadi system of record UMKM
Semua transaksi penting bisnis tersimpan di satu platform.

### G2 — Mengurangi pekerjaan administratif
Aktivitas penjualan harus otomatis memicu pembaruan inventory, customer, dan financial records bila konfigurasi memungkinkan.

### G3 — Membantu owner mengambil keputusan
Dashboard dan AI harus menjawab:
- apa yang terjadi,
- mengapa terjadi,
- apa yang perlu diperhatikan,
- tindakan apa yang dapat dipertimbangkan.

### G4 — Mendukung pertumbuhan bisnis
Produk harus mendukung:
- single outlet,
- multi-outlet,
- pertumbuhan jumlah produk,
- pertumbuhan karyawan,
- pertumbuhan transaksi.

### G5 — Membentuk ekosistem merchant + customer
Tahap lanjutan menghubungkan merchant-side SaaS dengan SeWaroeng Discover.

---

# 4. Non-Goals

Hal berikut TIDAK menjadi scope MVP:

1. Menjadi bank atau payment provider sendiri.
2. Menyimpan dana pengguna.
3. Membuat marketplace nasional sejak awal.
4. Menjadi ERP enterprise penuh.
5. Menjadi sistem payroll/tax engine yang menggantikan seluruh software khusus.
6. Mengklaim jumlah pengunjung real-time dari GPS sebagai angka pasti.
7. Melakukan background location tracking tanpa kebutuhan produk yang sah, transparan, dan memenuhi ketentuan platform.
8. Mengembangkan seluruh vertical UMKM sekaligus.
9. Menggunakan AI sebagai pengganti kontrol finansial atau keputusan manusia.
10. Mengambil atau menjual data lokasi individual pelanggan.

---

# 5. Target Customer

## 5.1 ICP Utama — F&B

Target awal:

- coffee shop
- kedai minuman
- restaurant kecil-menengah
- bakery
- dessert shop
- catering dengan pickup/order
- cloud kitchen

Profil ideal:

- 1–5 outlet
- 2–30 karyawan
- menggunakan QRIS atau pembayaran digital
- memiliki POS atau masih mencatat manual
- menggunakan WhatsApp/Instagram
- memiliki inventory bahan
- memiliki repeat customer
- transaksi harian cukup rutin

## 5.2 ICP Tahap berikutnya

### Retail
- fashion
- kosmetik
- toko aksesoris
- pet shop
- electronics kecil
- frozen food
- toko oleh-oleh

### Service business
- laundry
- barbershop
- salon
- bengkel
- percetakan
- studio
- rental
- kursus

### Producer/home industry
- bakery production
- makanan olahan
- frozen food
- konveksi
- craft
- furniture kecil

---

# 6. User Personas & Roles

## 6.1 Platform Super Admin

Tanggung jawab:
- merchant management
- subscription
- system configuration
- feature flags
- support
- audit
- billing
- integration credentials
- abuse/security monitoring

## 6.2 Merchant Owner

Akses penuh terhadap bisnis yang dimiliki.

Kebutuhan:
- melihat omzet
- laba
- stok
- customer
- employee
- multi-outlet
- reports
- AI
- settings

## 6.3 Manager

Akses operasional yang dibatasi owner.

Kebutuhan:
- dashboard outlet
- inventory
- purchasing
- staff
- booking
- reports

## 6.4 Cashier

Kebutuhan:
- POS
- payment
- open/close shift
- refund/void sesuai permission
- order status

Tidak boleh:
- melihat semua financial data
- mengubah harga tanpa permission
- menghapus transaction history

## 6.5 Staff

Akses fitur sesuai job role.

## 6.6 Accountant / Finance

Kebutuhan:
- journal
- expenses
- receivables
- payables
- reconciliation
- financial reports

## 6.7 Customer

Kebutuhan:
- discover merchant
- menu
- booking
- order
- payment
- loyalty
- receipt
- notification

---

# 7. Product Principles

## P1 — Simple by default

UMKM tidak boleh dipaksa memahami terminology enterprise.

Contoh:
- gunakan "Uang Masuk" sebelum istilah teknis yang tidak diperlukan;
- gunakan wizard;
- gunakan sensible defaults.

## P2 — Offline first untuk operasi kritis

Kasir tetap dapat membuat transaksi ketika internet tidak tersedia.

## P3 — One source of truth

Satu transaction ID menjadi referensi lintas modul.

## P4 — Automation first

Input satu kali, data digunakan lintas modul.

## P5 — Auditability

Data finansial tidak boleh dihapus secara permanen melalui UI biasa.

Gunakan void/reversal/refund dan audit trail.

## P6 — Secure by default

Tenant isolation, least privilege, encryption, secret management, rate limiting, audit logging.

## P7 — AI must be explainable

AI tidak boleh membuat angka bisnis tanpa sumber data.

## P8 — Privacy by design

Khusus data lokasi:
- opt-in,
- transparan,
- minimal,
- agregat,
- retention terbatas,
- tidak menampilkan identitas individu.

---

# 8. Product Scope

## 8.1 Dashboard

### Requirements

Owner dapat melihat:

- omzet hari ini
- transaksi
- average order value
- gross profit
- net cash movement
- produk terlaris
- produk dengan margin rendah
- stok kritis
- top customer
- booking hari ini
- settlement/payment status
- performa outlet
- alert/anomaly

### Filter

- hari ini
- kemarin
- 7 hari
- 30 hari
- custom
- outlet
- channel
- payment method

### Acceptance Criteria

- data dashboard berasal dari source-of-truth transaction/financial records;
- timezone menggunakan timezone merchant/outlet;
- dashboard tidak menghitung total dari UI cache yang dapat stale;
- angka dapat ditelusuri ke detail transaksi.

---

# 9. POS / Cashier

## 9.1 Core

Fitur:

- product catalog
- search
- category
- barcode
- variant
- modifier
- add-on
- quantity
- discount
- tax/service charge
- customer selection
- notes
- hold order
- resume order
- open bill
- split bill
- merge bill bila mode F&B membutuhkan
- table association
- dine-in/takeaway
- refund
- void
- reprint
- digital receipt

## 9.2 Payment

Method abstraction:

- cash
- QRIS/payment gateway
- bank transfer
- card/payment provider
- e-wallet bila tersedia melalui partner

## 9.3 Shift

- open shift
- cash float
- transaction count
- cash in
- cash out
- expected cash
- actual cash
- variance
- close shift

## 9.4 Offline Requirements

Saat offline:

- product cache tersedia;
- cart tetap berfungsi;
- transaction dapat disimpan local;
- local transaction mendapat client-generated UUID;
- transaction masuk sync queue;
- sync dilakukan idempotently;
- conflict tidak boleh menggandakan transaksi;
- cashier melihat status "Menunggu Sinkronisasi".

### Sync protocol minimum

```text
client_transaction_id
device_id
tenant_id
outlet_id
occurred_at
created_at
payload_version
sync_status
```

Server wajib menggunakan unique constraint untuk mencegah duplicate sync.

---

# 10. Catalog / Product

Entity utama:

- Product
- ProductVariant
- Category
- Unit
- PriceList
- ModifierGroup
- Modifier
- Barcode
- ProductImage
- TaxRule

Product support:

- simple product
- variant product
- service item
- bundle
- recipe-based product

---

# 11. Inventory

## 11.1 Stock

Track:

- on hand
- reserved
- available
- damaged
- expired
- incoming
- outgoing

## 11.2 Stock movement

Semua perubahan inventory harus mempunyai reason/type:

```text
sale
sale_return
purchase
purchase_return
adjustment
stock_opname
transfer
production
waste
expired
manual_issue
```

Jangan langsung mengubah "stock" tanpa ledger movement.

## 11.3 Multi-warehouse

Mendukung:

- outlet
- warehouse
- kitchen
- storage

## 11.4 Stock opname

Workflow:

```text
Create Stock Opname
→ Freeze/count policy
→ Input physical count
→ Difference calculation
→ Review
→ Approval
→ Adjustment movement
```

---

# 12. Recipe / HPP / Production

Untuk F&B:

```text
Product
→ Recipe
→ Ingredients
→ Quantity
→ Unit conversion
→ Cost
```

Contoh:

```text
Es Kopi Susu
- Coffee bean 18g
- Milk 120ml
- Syrup 15ml
- Cup 1
- Ice 1
```

Sistem menghitung:

- estimated HPP
- actual HPP
- gross margin
- margin percentage

## Production

Untuk producer:

```text
Raw Material
→ Production Order
→ Consumption
→ Finished Goods
```

---

# 13. Purchasing & Supplier

Fitur:

- supplier
- supplier contact
- supplier product
- purchase request
- purchase order
- goods received
- purchase invoice
- supplier payment record
- purchase return

## Smart Reorder

Sistem menghitung rekomendasi:

```text
Average daily usage
×
lead time
+
safety stock
-
current available
```

Rekomendasi tidak otomatis melakukan pembelian tanpa approval.

---

# 14. CRM & Customer 360

Data customer:

- profile
- contact
- consent
- order history
- booking history
- spend
- frequency
- last activity
- favorite products
- loyalty
- segment

## Segment

System-generated:

- New
- Active
- Loyal
- VIP
- At Risk
- Dormant
- Lost

Segment rules harus configurable dan dapat diaudit.

---

# 15. Loyalty

Fitur:

- points
- tier
- reward
- voucher
- coupon
- cashback-like promotion hanya bila secara legal/teknis sesuai model yang digunakan
- birthday/anniversary campaign bila customer memberikan data tersebut
- referral

Point ledger harus immutable.

Contoh:

```text
+100 purchase
-100 redeemed
+50 campaign
```

Jangan hanya menyimpan `points_balance`.

---

# 16. Employee Management

Fitur:

- employee profile
- role
- permission
- outlet assignment
- shift
- attendance
- overtime record
- commission
- incentive
- activity log
- payroll export

## Permission

Gunakan RBAC + granular permission.

Contoh:

```text
pos.sell
pos.discount
pos.refund
pos.void
inventory.view
inventory.adjust
purchase.create
purchase.approve
finance.view
finance.edit
report.view
employee.manage
```

High-risk permission harus default false.

---

# 17. Booking & Table Management

Mode utama:

### Table

Status:

```text
available
reserved
occupied
waiting_payment
cleaning
blocked
```

### Booking

Data:

- customer
- date
- time
- duration
- party size
- table
- deposit
- status
- note
- source

Status:

```text
pending
confirmed
arrived
completed
cancelled
no_show
```

## Table Order

```text
QR meja
→ Menu
→ Order
→ Payment / pay later
→ Kitchen
→ Serve
```

Order dan booking harus menggunakan entity reference yang konsisten.

---

# 18. Online Order

Customer dapat:

- melihat menu
- memilih product
- memilih variant
- memilih modifier
- checkout
- payment
- memilih pickup/delivery/dine-in
- mendapatkan order status

Merchant dapat:

- menerima order
- accept/reject
- set preparation time
- set ready
- complete/cancel

Order channel:

```text
POS
QR
Web/PWA
Consumer app
WhatsApp flow
```

---

# 19. WhatsApp Integration

## Merchant-side

Owner dapat menerima:

- daily sales summary
- low stock
- booking reminder
- payment settlement status
- anomaly alert

Owner dapat meminta informasi menggunakan natural language yang dipetakan ke safe query.

Contoh:

> "Omzet hari ini?"

> "Produk paling laku minggu ini?"

> "Stok susu cukup sampai besok?"

## Customer-side

- order confirmation
- booking confirmation
- reminder
- receipt
- promotion
- status update

## Safety

AI tidak boleh:

- mengirim broadcast massal tanpa consent/configuration;
- membuat refund tanpa permission;
- mengubah harga;
- melakukan pembayaran;
- mengubah accounting entries;
- mengeksekusi action berisiko tanpa confirmation.

---

# 20. Digital Payment & Reconciliation

SeWaroeng menggunakan payment provider/PJP partner sesuai perizinan yang berlaku.

Core abstraction:

```text
PaymentIntent
PaymentAttempt
PaymentTransaction
Settlement
Refund
Fee
Reconciliation
```

Flow:

```text
POS Order
→ Payment Intent
→ Provider
→ Callback/Webhook
→ Verify
→ Mark Paid
→ Settlement tracking
→ Reconciliation
```

## Webhook rules

- signature verification;
- idempotency;
- replay protection;
- raw payload audit;
- provider event ID unique;
- asynchronous processing;
- dead-letter strategy.

Jangan menjadikan frontend sebagai sumber kebenaran status pembayaran.

---

# 21. Finance / Bookkeeping

## Minimum chart/account concept

System harus mempunyai konsep:

- cash
- bank
- sales
- sales return
- expense
- inventory
- COGS
- payable
- receivable
- tax payable
- owner equity

## Automated financial posting

Contoh:

```text
Sale
→ Sales record
→ Payment record
→ Inventory movement
→ COGS
→ Financial journal
```

Tetapi automatic journal hanya boleh dilakukan berdasarkan deterministic business rules.

## Reports

- Profit & Loss
- Cash Flow
- Balance Sheet
- Sales
- Expense
- COGS
- Gross Margin
- Receivable
- Payable
- Tax summary
- Payment reconciliation

Export:
- CSV
- XLSX
- PDF

---

# 22. Analytics

## Core metrics

### Sales

- GMV
- net sales
- transaction count
- AOV
- channel mix
- payment mix

### Product

- units sold
- revenue
- HPP
- gross profit
- margin
- growth
- dead stock

### Customer

- new customers
- returning customers
- frequency
- average spend
- retention/cohort

### Outlet

- revenue
- transaction
- AOV
- gross margin
- table utilization
- booking conversion

---

# 23. AI Business Copilot

AI harus menjadi layer di atas data terstruktur, bukan source-of-truth.

## 23.1 Natural Language Query

Contoh:

> "Kenapa laba turun minggu ini?"

System harus menjawab dengan:

1. kesimpulan berbasis data;
2. metrik yang berubah;
3. kemungkinan driver;
4. data sumber;
5. periode;
6. confidence/limitation;
7. tindakan yang dapat dipertimbangkan.

Contoh format:

```text
Laba kotor turun 9,8% dibanding minggu sebelumnya.

Driver utama:
- penjualan turun 5,4%;
- HPP rata-rata naik 6,2%;
- produk X menyumbang penurunan margin terbesar.

Periode:
23–29 September 2026 vs 16–22 September 2026.

Sumber:
Sales, Inventory, Purchase.

Catatan:
Data belum mencakup transaksi yang masih menunggu sinkronisasi.
```

## 23.2 Forecasting

Forecast:

- demand
- stock depletion
- sales
- staffing demand

Forecast harus memiliki:

- horizon;
- model version;
- data timestamp;
- confidence interval/range;
- fallback bila data kurang.

## 23.3 Anomaly Detection

Contoh:

```text
Refund rate outlet A
normal: 1–3%
today: 8,4%
```

Sistem mengeluarkan alert untuk review.

AI tidak boleh menuduh fraud; gunakan bahasa:
- "anomali";
- "perlu ditinjau";
- "berbeda dari pola historis".

---

# 24. Crowd Intelligence — SeWaroeng Discover

## 24.1 Goal

Memberikan indikasi:

> "Seberapa ramai tempat ini sekarang?"

Output bukan jumlah orang pasti.

Gunakan label:

```text
Sepi
Cukup ramai
Ramai
Sangat ramai
```

Disertai:

- last updated
- confidence
- basis signal

## 24.2 Data signals

Possible signals:

1. POS transaction velocity
2. active booking
3. customer check-in / visit signal
4. opt-in aggregated location signals
5. table occupancy
6. queue length
7. historical pattern

## 24.3 Data architecture

```text
Raw event
→ validation
→ minimization
→ aggregation
→ scoring
→ crowd state
→ public display
```

Jangan expose raw individual location.

## 24.4 Example

```text
Kopi ABC

🟠 Ramai

Estimasi: tinggi
Confidence: 88%
Update: 3 menit lalu
```

## 24.5 MVP decision

Crowd Intelligence bukan MVP.

Phase awal:
- merchant manual status;
- POS transaction velocity;
- booking/occupancy;
- opt-in check-in.

Phase lanjut:
- statistical location signals.

Phase berikutnya:
- ML/forecast crowd.

---

# 25. SeWaroeng Discover

Consumer-facing layer.

Fitur:

- nearby merchants
- categories
- opening hours
- menu
- price range
- photos
- promotions
- booking
- order
- loyalty
- crowd indicator
- queue estimate
- favorite

Merchant profile harus mempunyai structured data, bukan hanya foto/deskripsi.

---

# 26. Multi-Tenancy

Architecture requirement:

```text
Platform
└── Tenant / Merchant
    ├── Business
    ├── Outlet
    ├── Warehouse
    ├── Users
    ├── Customers
    ├── Products
    ├── Transactions
    └── Finance
```

Setiap business record yang tenant-scoped wajib mempunyai tenant boundary yang konsisten.

## Rules

- API tidak boleh menerima `tenant_id` dari client sebagai trust source;
- tenant harus di-resolve dari authenticated context/membership;
- query wajib tenant-scoped;
- cross-tenant access wajib impossible by default;
- test wajib mencakup tenant isolation.

---

# 27. Backend Requirements — Laravel

## Suggested baseline

- Laravel API-first
- PHP version disetujui engineering
- PostgreSQL
- Redis
- queue workers
- scheduler
- object storage
- REST API
- OpenAPI documentation
- automated tests
- centralized logging

## Laravel responsibilities

- authentication
- authorization
- tenancy
- business rules
- transaction processing
- financial posting
- inventory ledger
- booking
- notifications
- integration orchestration
- AI gateway
- webhook processing
- audit
- reporting

## Domain-oriented modules

Suggested boundaries:

```text
Auth
Tenancy
Users
Catalog
Sales
POS
Inventory
Purchasing
CRM
Loyalty
Employees
Booking
Finance
Payments
Notifications
Integrations
Analytics
AI
Discover
Crowd
Subscriptions
```

Tidak harus menjadi Laravel package terpisah; yang penting boundary/domain responsibility jelas.

---

# 28. API Standards

Base:

```text
/api/v1
```

Response format:

```json
{
  "data": {},
  "meta": {},
  "message": null
}
```

Error:

```json
{
  "message": "Validation failed.",
  "errors": {
    "field": [
      "The field is required."
    ]
  },
  "code": "VALIDATION_ERROR"
}
```

## Rules

- consistent HTTP status;
- API versioning;
- idempotency untuk create operations sensitif;
- pagination;
- filtering;
- sorting;
- request ID;
- correlation ID;
- rate limit;
- audit where needed;
- webhook verification.

---

# 29. Offline Sync Architecture

Flutter POS:

```text
UI
 ↓
Local Repository
 ↓
Local DB
 ↓
Outbox / Sync Queue
 ↓
Network
 ↓
Laravel API
 ↓
Server DB
```

Server:

```text
API
 ↓
Idempotency
 ↓
Domain transaction
 ↓
DB commit
 ↓
Event
 ↓
Queue
```

## Sync states

```text
pending
uploading
synced
failed
conflict
requires_action
```

Client must not silently overwrite server state.

---

# 30. Database Design Principles

General:

- UUID/ULID IDs;
- timestamps;
- soft delete only where appropriate;
- immutable financial/event records;
- foreign keys;
- indexes;
- unique constraints;
- check constraints where useful;
- money stored as integer minor units or explicit decimal strategy;
- timezone-aware timestamps;
- avoid floating point for money.

Critical ledgers:

- inventory movement ledger
- payment ledger
- accounting journal
- loyalty point ledger
- audit log

---

# 31. Core Entities

Minimum entity inventory:

```text
Tenant
Business
Outlet
Warehouse
User
Role
Permission
Membership

Product
ProductVariant
Category
Unit
Barcode
Price
ModifierGroup
Modifier

Customer
CustomerConsent
CustomerSegment
LoyaltyAccount
LoyaltyLedger

Supplier
PurchaseOrder
PurchaseItem
GoodsReceipt

InventoryItem
InventoryMovement
StockOpname
StockTransfer
Recipe
RecipeItem
ProductionOrder
ProductionItem
WasteRecord

Order
OrderItem
OrderPayment
Refund
Void
Shift
ShiftTransaction

Booking
BookingTable
Table
TableSession

Expense
Account
JournalEntry
JournalLine
Receivable
Payable

PaymentIntent
PaymentTransaction
Settlement
Reconciliation
WebhookEvent

Employee
Attendance
ShiftSchedule
Commission

Notification
NotificationTemplate

AIConversation
AIRequest
AIResponse
AIInsight
Forecast
Anomaly

LocationConsent
LocationSignal
CrowdSnapshot

Subscription
Plan
FeatureEntitlement
Invoice

AuditLog
Integration
IntegrationCredential
```

---

# 32. Security Requirements

Minimum:

- password hashing with modern Laravel-supported algorithm;
- session/token rotation where applicable;
- refresh token security;
- RBAC;
- object-level authorization;
- tenant isolation;
- rate limiting;
- brute-force protection;
- secure headers;
- HTTPS only;
- encrypted secrets;
- no secrets in mobile app;
- webhook signature verification;
- audit logs;
- backup;
- restore test;
- dependency scanning;
- SAST/linting;
- production error reporting without sensitive payloads.

## Financial operations

High-risk actions require:

- permission check;
- audit;
- optional confirmation;
- reason when appropriate.

Examples:
- refund;
- manual stock adjustment;
- journal adjustment;
- role escalation;
- payout configuration.

---

# 33. Privacy Requirements

Data classes:

### Basic
- name
- email
- phone

### Business
- transaction
- inventory
- sales
- employee

### Sensitive operational
- payment identifiers
- financial records
- location-related signals

Principles:

- data minimization;
- purpose limitation;
- explicit consent where required;
- retention policy;
- deletion/anonymization workflow;
- access/export workflow;
- consent withdrawal;
- processor/vendor register;
- incident response.

For location:
- collect only when a feature requires it;
- explain why;
- use approximate/aggregated signals when possible;
- avoid continuous background tracking unless genuinely necessary;
- public crowd output must not identify people.

---

# 34. Compliance Checklist

Before production launch, legal/compliance review must cover:

- PSE registration applicability;
- UU PDP obligations;
- privacy policy;
- terms of service;
- merchant agreement;
- data processor/controller roles;
- payment provider agreements;
- WhatsApp/Meta terms;
- Google Play requirements;
- location permission requirements;
- consumer consent;
- tax/accounting assumptions;
- electronic transaction requirements.

References should be re-verified immediately before launch because regulations and platform policies may change.

---

# 35. Subscription & Entitlement

Feature access must use entitlement flags, not hardcoded UI checks.

Example:

```text
POS
INVENTORY_BASIC
INVENTORY_ADVANCED
MULTI_OUTLET
ACCOUNTING
BOOKING
WHATSAPP
AI_COPILOT
FORECAST
DISCOVER
CROWD
```

Subscription architecture:

```text
Plan
→ Entitlements
→ Tenant subscription
→ Usage limits
```

Usage limits can include:

- outlets
- users
- transactions
- products
- customers
- AI requests
- WhatsApp messages
- storage

---

# 36. Notifications

Channels:

- in-app
- push notification
- email
- WhatsApp where configured

Event examples:

```text
low_stock
booking_created
booking_reminder
order_received
payment_success
payment_failed
settlement_completed
shift_closed
anomaly_detected
forecast_alert
subscription_expiring
```

Notification must be:
- idempotent;
- preference-aware;
- retryable;
- observable.

---

# 37. Observability

Must have:

- application logs
- audit logs
- error monitoring
- queue monitoring
- API latency
- database latency
- webhook processing metrics
- sync failure metrics
- offline pending count
- payment failure rate
- crash-free sessions
- notification delivery metrics

Recommended business metrics:

```text
DAU merchant
WAU merchant
transactions/day
GMV
MRR
active outlets
retention
churn
offline sync failure
payment success
```

---

# 38. Analytics Event Taxonomy

Example:

```text
merchant_created
onboarding_completed
product_created
first_sale
order_created
payment_success
payment_failed
stock_adjusted
booking_created
booking_completed
whatsapp_connected
ai_query_sent
ai_insight_opened
subscription_started
subscription_cancelled
```

Every event:

```json
{
  "event_name": "order_created",
  "tenant_id": "...",
  "outlet_id": "...",
  "user_id": "...",
  "occurred_at": "...",
  "properties": {}
}
```

Do not place sensitive raw data in analytics event payloads.

---

# 39. Onboarding

Goal:

> Merchant can complete first sale as quickly as possible.

Flow:

```text
Register
→ Create business
→ Choose business type
→ Create outlet
→ Import products / AI-assisted import
→ Configure payment
→ Add staff
→ Open POS
→ First transaction
```

Import options:

- CSV/XLSX
- manual
- product template
- guided migration

Potential AI onboarding:

```text
Upload menu photo
→ OCR
→ Product suggestions
→ Price extraction
→ Merchant review
→ Save
```

AI output MUST require user review before committing critical business data.

---

# 40. UX Requirements

## Owner

Dashboard-first.

## Cashier

POS-first.

## Manager

Outlet operations-first.

## Accountant

Finance-first.

## Customer

Discover/order/booking-first.

The navigation must be role-aware.

Do not show 20 modules to every user.

---

# 41. Accessibility & Localization

Initial:

- Indonesian (`id-ID`)
- Indonesian currency format (`IDR`)
- Asia/Jakarta timezone default
- date/time formatting localized

Future:
- English
- additional Indonesian locale requirements as needed

Accessibility:
- readable text
- touch target adequate
- color must not be the sole status indicator
- screen reader labels where practical
- error messages understandable.

---

# 42. Performance Requirements

Initial production targets:

### API
- p95 read API < 500 ms for normal queries
- p95 write API < 800 ms excluding external provider latency
- pagination mandatory for large collections

### POS
- product search locally available < 100 ms target
- transaction commit local < 300 ms target
- sync retries in background

### Dashboard
- cached/pre-aggregated reports for expensive queries
- no full transaction-table scan on normal dashboard requests

### Mobile
- graceful degraded mode
- no blocking UI on network call for critical cashier workflows

Targets are engineering SLO candidates and should be benchmarked under realistic load.

---

# 43. Reliability

Target:

- no duplicate payment state transition;
- no duplicate POS transaction;
- no negative stock unless product policy explicitly permits it;
- no orphan payment;
- no unbalanced accounting journal;
- no cross-tenant read/write;
- no silent data loss during offline sync.

Every critical transaction should have an invariant.

---

# 44. Audit Log

Audit log fields:

```text
id
tenant_id
actor_user_id
action
entity_type
entity_id
before
after
ip_address
user_agent
request_id
created_at
```

Do not record passwords, tokens, raw payment secrets, or unnecessary personal data.

---

# 45. Reporting Strategy

Reports must distinguish:

- operational real-time data;
- accounting data;
- settlement data;
- estimated/forecast data.

UI should label:

```text
Actual
Estimated
Forecast
Pending Sync
Pending Settlement
```

Never mix forecast with actual without visible distinction.

---

# 46. MVP Definition

## MVP must include

### Merchant
- registration/login
- business
- outlet
- staff
- roles/permissions

### Catalog
- product
- category
- price
- variant

### POS
- cart
- sale
- customer
- payment
- shift
- receipt

### Inventory
- stock
- stock movement
- stock adjustment
- stock opname
- low-stock alert

### CRM
- customer
- order history
- basic segmentation

### Finance
- sales summary
- expense
- basic cashflow
- basic P&L

### Dashboard
- sales
- transactions
- top product
- low stock
- outlet overview

### Platform
- tenant isolation
- audit
- notifications
- subscription foundation

---

# 47. V1 Scope

Setelah MVP tervalidasi:

- recipe
- HPP
- supplier
- purchase order
- goods receipt
- QR table order
- booking
- loyalty
- WhatsApp
- multi-outlet
- payment reconciliation
- advanced financial reports
- CSV/XLSX/PDF exports

---

# 48. V2 Scope

- AI Business Copilot
- demand forecasting
- anomaly detection
- smart reorder
- AI onboarding
- staff optimization
- advanced CRM campaigns
- integrations

---

# 49. V3 / Vision

- SeWaroeng Discover
- customer app/PWA
- marketplace-like discovery
- live crowd indicator
- queue prediction
- location-based aggregated signals
- merchant demand intelligence
- cross-merchant consumer loyalty
- partner ecosystem

---

# 50. Feature Priority Matrix

| Feature | Priority | MVP | Rationale |
|---|---|---|---|
| POS | P0 | Yes | Core transaction engine |
| Inventory | P0 | Yes | Core operational value |
| Customer | P0 | Yes | Builds customer data foundation |
| Employee/RBAC | P0 | Yes | Operational control |
| Basic Finance | P0 | Yes | Owner needs business visibility |
| Payment abstraction | P0 | Yes | Required for transaction completion |
| Dashboard | P0 | Yes | Immediate owner value |
| Offline POS | P0 | Yes | Reliability differentiator |
| Purchasing | P1 | No | Reduces operational work |
| Recipe/HPP | P1 | No | Critical for F&B economics |
| Booking/Table | P1 | No | F&B vertical advantage |
| WhatsApp | P1 | No | Matches local business workflow |
| Loyalty | P1 | No | Customer retention |
| Multi-outlet | P1 | No | Expansion path |
| Reconciliation | P1 | No | Finance automation |
| AI Copilot | P2 | No | Requires quality data |
| Forecasting | P2 | No | Requires historical data |
| Crowd | P3 | No | Requires network/data density |
| Discover | P3 | No | Consumer network layer |

---

# 51. Critical User Journeys

## Journey A — First sale

```text
Register
→ Business
→ Outlet
→ Add/import product
→ Open shift
→ Add customer (optional)
→ Sell
→ Pay
→ Receipt
→ Inventory updated
→ Dashboard updated
```

Success criteria:
- merchant completes first transaction;
- no manual accounting input is needed for basic sale;
- stock is reduced correctly.

---

## Journey B — Stock replenishment

```text
Low stock alert
→ Review consumption
→ Create PO
→ Receive goods
→ Stock increases
→ Cost updated
```

---

## Journey C — Coffee shop booking

```text
Customer
→ Select merchant
→ Select date/time
→ Party size
→ Available table
→ Booking
→ Deposit if configured
→ Confirmation
→ Merchant receives notification
→ Arrival
→ Table session
→ Order
→ Payment
→ Completed
```

---

## Journey D — Owner asks AI

```text
Owner opens AI
→ asks "Kenapa omzet turun?"
→ system retrieves authorized business metrics
→ analytics engine calculates facts
→ AI explains
→ sources/time range shown
→ action suggestions shown
```

AI should not have unrestricted SQL/database access.

---

# 52. AI Agent Coding Rules

AI coding tools must follow these rules.

## R1
Read existing repository architecture before creating new abstractions.

## R2
Do not rewrite unrelated modules.

## R3
Do not modify product requirements silently.

## R4
Do not create duplicate models/services that already exist.

## R5
Every new domain feature must include:
- migration;
- model;
- authorization;
- request validation;
- service/domain logic;
- API resource/transformer;
- route/controller;
- automated tests;
- documentation where relevant.

## R6
Business rules belong outside controllers.

## R7
Controllers should remain thin.

## R8
No direct DB writes from Flutter.

## R9
No secrets embedded in Flutter build.

## R10
All critical write operations must be idempotent where duplicate requests are possible.

## R11
Do not trust tenant_id, user_id, role, price, payment status, stock balance, or permission claims from the client.

## R12
Server is the authority for:
- price validation;
- discounts;
- payment status;
- inventory state;
- authorization;
- financial posting.

## R13
Offline client data must be treated as untrusted until validated by server.

## R14
No `dd()`, debug endpoints, test credentials, or hardcoded secrets in production code.

## R15
No silent exception swallowing.

## R16
Every external integration must have:
- timeout;
- retry strategy;
- idempotency;
- logging;
- failure state;
- fallback.

## R17
Do not call LLMs directly from mobile clients with privileged secrets.

## R18
AI requests must pass through backend authorization and quota.

## R19
AI must not execute financial mutations.

## R20
Any schema-breaking change requires migration plan and compatibility consideration.

---

# 53. Flutter Architecture Guidance

Recommended logical layering:

```text
Presentation
├── screens
├── widgets
└── state management

Application
├── use cases
└── controllers/notifiers

Domain
├── entities
├── value objects
└── contracts

Data
├── repositories
├── local datasource
├── remote datasource
└── sync

Infrastructure
├── network
├── secure storage
├── local DB
├── push
└── device services
```

Requirements:

- typed API models;
- centralized API client;
- centralized error mapping;
- secure token storage;
- local persistence for offline modules;
- repository abstraction;
- no business logic scattered in widgets;
- immutable state preferred;
- dependency injection;
- testable services.

State-management technology may be selected by architecture team, but must be consistent throughout the application.

---

# 54. Laravel Architecture Guidance

Recommended:

```text
HTTP Layer
→ Application Layer
→ Domain/Business Layer
→ Infrastructure
```

Avoid:

```text
Controller
→ 500 lines of business logic
```

Prefer:

```text
Controller
→ Action/UseCase
→ Domain service
→ Repository/query
```

Use Eloquent where appropriate, but do not allow Active Record convenience to bypass critical invariants.

---

# 55. Critical Business Invariants

## Sales

```text
gross_total >= 0
discount_total >= 0
net_total >= 0
```

## Payment

```text
paid_amount <= required_amount
```

subject to partial payment configuration.

## Inventory

For a sale:

```text
stock_before - consumed_quantity = stock_after
```

## Accounting

For every balanced journal:

```text
SUM(debit) = SUM(credit)
```

## Loyalty

Every balance change must have a ledger entry.

## Booking

A table/time slot cannot be double-booked under the same availability policy.

---

# 56. Testing Requirements

## Backend

Minimum:

- unit tests
- feature/API tests
- authorization tests
- tenancy tests
- migration tests
- integration tests
- webhook tests
- idempotency tests
- inventory invariant tests
- accounting invariant tests

## Flutter

- unit tests
- repository tests
- widget tests for critical flows
- offline mode tests
- sync tests
- auth expiry tests
- error-state tests

## End-to-end

At minimum:

1. onboarding → first sale;
2. sale → inventory;
3. sale → financial posting;
4. offline sale → sync;
5. booking → order → payment;
6. payment webhook → paid;
7. tenant A cannot access tenant B.

---

# 57. Acceptance Criteria — MVP

MVP dianggap releasable jika:

### Functional
- merchant dapat membuat bisnis;
- merchant dapat membuat outlet;
- owner dapat menambah staff;
- cashier dapat menjual product;
- payment dapat dicatat;
- receipt dapat dibuat;
- inventory berkurang sesuai transaksi;
- customer history terbentuk;
- owner dapat melihat dashboard;
- expense dapat dicatat;
- basic P&L/cashflow tersedia.

### Reliability
- no duplicate transaction under repeated client request;
- offline transaction can sync;
- failed sync is visible and retryable;
- payment webhook is idempotent.

### Security
- cross-tenant access test passes;
- permission test passes;
- sensitive secrets not included in client;
- audit exists for critical actions.

### Performance
- critical POS flow remains responsive;
- API meets agreed p95 target under load test.

---

# 58. Definition of Done

A feature dianggap selesai hanya jika:

1. Requirement implemented.
2. API documented.
3. Validation implemented.
4. Authorization implemented.
5. Tenant isolation verified.
6. Migration included.
7. Automated tests included.
8. Error handling included.
9. Logging/observability included where relevant.
10. Flutter UI implemented.
11. Loading/error/empty/offline states handled.
12. QA scenario written.
13. No known critical/high security issue.
14. No unrelated regression.
15. Documentation updated.

---

# 59. Suggested Delivery Sequence

## Sprint/Phase 0 — Foundation

- repository standards
- CI/CD
- environments
- auth
- tenancy
- RBAC
- API conventions
- logging
- error monitoring
- OpenAPI
- test framework
- Flutter architecture
- local DB decision

## Phase 1 — Transaction Core

- catalog
- POS
- payment abstraction
- shift
- receipt
- inventory
- customer

## Phase 2 — Business Operations

- supplier
- purchasing
- recipe/HPP
- employee
- booking/table
- basic finance

## Phase 3 — Growth

- WhatsApp
- loyalty
- multi-outlet
- reconciliation
- advanced reporting

## Phase 4 — Intelligence

- AI Copilot
- anomaly detection
- forecasting
- smart reorder

## Phase 5 — Ecosystem

- Discover
- booking marketplace
- crowd intelligence
- consumer loyalty
- aggregated demand intelligence

---

# 60. Product Metrics

## Activation

Primary:

```text
% merchant completing first sale within 24 hours
```

Secondary:
- onboarding completion
- product import completion
- payment setup completion

## Engagement

- weekly active merchants
- transactions per active merchant
- active days/month
- number of active staff

## Retention

- 7-day
- 30-day
- 90-day merchant retention

## Revenue

- MRR
- ARPU
- paid conversion
- churn
- expansion revenue
- multi-outlet adoption

## Product reliability

- payment success
- offline sync success
- crash-free session
- API p95
- webhook processing latency

## AI

- AI query success
- grounded answer rate
- user feedback
- action click-through
- hallucination/error reports

## Crowd

- coverage
- signal freshness
- confidence calibration
- merchant participation
- customer interaction rate

---

# 61. Business Model Foundation

Recommended initial structure:

```text
Free / Trial
→ Starter
→ Business
→ Multi-outlet
→ Add-ons
```

Potential add-ons:

- WhatsApp
- AI usage
- advanced reports
- additional outlet
- additional staff
- storage
- premium integrations

Important:
- do not force every micro business into paid plan immediately;
- price based on value/capacity;
- usage billing must be transparent;
- provider pass-through charges must be visible.

---

# 62. Competitive Strategy

SeWaroeng should NOT attempt to win by copying competitor feature lists.

Core differentiation:

### 1. Offline-first POS
Operates reliably even when connectivity is unstable.

### 2. WhatsApp-native operations
Owner can ask/receive business information through the channel already used by many Indonesian merchants.

### 3. Vertical workflows
F&B, retail, laundry, service, and production receive different experiences.

### 4. AI grounded in business data
AI explains real merchant metrics, not generic business advice.

### 5. Single business graph

```text
Order
↔ Customer
↔ Product
↔ Inventory
↔ Payment
↔ Finance
↔ Employee
↔ Booking
```

### 6. Merchant-to-consumer ecosystem
Discover/booking/crowd is a long-term differentiator.

---

# 63. Open Technical Decisions (ADR Required)

Before implementation, architecture team must explicitly decide:

1. Sanctum vs JWT strategy.
2. PostgreSQL schema strategy for tenant isolation.
3. UUID vs ULID.
4. Flutter local DB technology.
5. State-management technology.
6. API documentation standard.
7. Search engine requirement.
8. Object storage provider.
9. Queue infrastructure.
10. real-time transport (WebSocket/SSE/polling) per feature.
11. payment provider(s).
12. WhatsApp provider/API strategy.
13. AI provider/model gateway.
14. analytics/event platform.
15. observability stack.
16. backup/restore architecture.
17. data retention schedule.
18. accounting engine complexity.
19. offline conflict resolution rules.
20. location signal architecture for future Crowd Intelligence.

Each decision must have an ADR with:
- context;
- alternatives;
- decision;
- consequences.

---

# 64. AI Agent Task Template

Every AI coding task should start with:

```text
Context:
SeWaroeng SaaS
Backend: Laravel
Frontend: Flutter

Goal:
[one concrete goal]

Scope:
[files/modules allowed]

Out of scope:
[what must not change]

Acceptance criteria:
[verifiable criteria]

Constraints:
- preserve existing architecture
- tenant isolation
- RBAC
- tests required
- no secrets
- no unrelated changes

Expected output:
1. implementation
2. migrations
3. tests
4. API documentation
5. summary of changed files
6. known limitations
```

AI agent must inspect the repository before coding.

---

# 65. Example AI Agent Prompt — Feature: Low Stock Alert

```text
Implement SeWaroeng Low Stock Alert.

Stack:
- Laravel backend
- Flutter frontend
- PostgreSQL
- Redis/queue

Goal:
Notify authorized merchant users when a product's available stock reaches or falls below its configured reorder threshold.

Requirements:
1. Add reorder_level to the appropriate inventory/product entity without duplicating existing fields.
2. Calculate available stock using the existing inventory movement rules.
3. Do not mutate stock in the alert process.
4. Generate alert idempotently.
5. Queue notifications.
6. Support in-app and push notification.
7. Add audit/logging where required.
8. Enforce tenant and outlet authorization.
9. Add API endpoint for alert configuration.
10. Add backend tests.
11. Add Flutter UI for configuration and notification state.
12. Handle loading, empty, error, and offline states.

Do not:
- rewrite inventory architecture;
- create a second stock table;
- place business logic inside controller/widget;
- expose internal tenant_id as a trusted client field.

Acceptance:
- alert triggers exactly once per threshold crossing according to defined policy;
- repeated queue execution does not duplicate notification;
- tenant isolation test passes;
- inventory numbers remain unchanged by alert processing.
```

---

# 66. Example API Resource Shape

## Product

```json
{
  "id": "01K...",
  "name": "Es Kopi Susu",
  "sku": "KOPI-001",
  "category": {
    "id": "01K...",
    "name": "Coffee"
  },
  "price": 18000,
  "stock": {
    "available": 42,
    "reorder_level": 10
  },
  "is_active": true
}
```

## Order

```json
{
  "id": "01K...",
  "order_number": "SW-20261003-000123",
  "outlet_id": "01K...",
  "channel": "pos",
  "status": "completed",
  "payment_status": "paid",
  "customer_id": "01K...",
  "subtotal": 36000,
  "discount": 2000,
  "tax": 0,
  "total": 34000,
  "items": []
}
```

---

# 67. Data Freshness Rules

Every business metric should be classified:

```text
REALTIME
NEAR_REALTIME
DELAYED
ESTIMATED
FORECAST
```

Examples:

- POS transaction: near-real-time
- payment provider settlement: delayed
- demand forecast: forecast
- crowd indicator: estimated
- accounting report: based on posted journals

UI must not represent estimated/forecast data as actual.

---

# 68. Crowd Intelligence Technical Guardrails

This module requires additional review.

## Do not implement:

```text
Collect every user's GPS continuously
→ calculate exact people count
```

## Preferred:

```text
Opt-in/allowed signals
+
merchant operational signals
+
transaction velocity
+
booking
+
table occupancy
+
historical patterns
→ aggregated score
```

Output:

```text
crowd_level
confidence
freshness
```

Example score object:

```json
{
  "crowd_level": "busy",
  "confidence": 0.88,
  "updated_at": "2026-10-03T15:25:00Z",
  "method": "aggregated_signals_v1"
}
```

No individual identity or raw location should be exposed to consumers.

---

# 69. Release Gates

## Alpha

- internal employees/test merchants
- no public consumer crowd
- real payment only if approved
- crash/error monitoring active

## Beta

- limited merchants
- feature flags
- usage monitoring
- support workflow active
- backup restore verified

## Production

Required:
- security review;
- privacy/compliance review;
- disaster recovery test;
- load test;
- payment integration certification where applicable;
- PSE/legal review where applicable;
- Google Play policy review;
- monitoring/alerting;
- incident runbook.

---

# 70. Success Definition

SeWaroeng is considered to have early product-market signal when:

1. merchants complete onboarding without direct engineering assistance;
2. merchant completes transactions regularly;
3. merchant returns weekly;
4. merchant continues using inventory/customer/reporting features;
5. a meaningful percentage converts from free/trial to paid;
6. churn is understood and addressable;
7. merchants can identify concrete time/cost saved;
8. product remains reliable during operational use;
9. AI outputs are trusted because they are traceable to merchant data.

Do not define success using downloads alone.

---

# 71. Key Product Thesis

The strategic sequence is:

```text
TRANSACTIONS
     ↓
BUSINESS DATA
     ↓
OPERATIONS
     ↓
ANALYTICS
     ↓
AI
     ↓
PREDICTION
     ↓
ACTION
     ↓
CONSUMER ECOSYSTEM
```

POS is therefore not the final product.

POS is the data entry point.

Inventory is the operational layer.

Finance is the control layer.

CRM is the customer layer.

AI is the intelligence layer.

Discover/Crowd is the network layer.

---

# 72. Reference Data / Current External Constraints

The product thesis is consistent with several current facts that should be periodically re-verified:

1. Bank Indonesia reported that by July 2026 QRIS had reached 45.46 million merchants, 96.69% of which were UMKM.  
   Source: Bank Indonesia, 22 September 2026  
   https://www.bi.go.id/id/publikasi/ruang-media/news-release/Pages/sp_2819126.aspx

2. Indonesian personal-data processing is governed by UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi. The law contains requirements concerning high-risk processing, security, confidentiality, and withdrawal of consent.  
   Source: JDIH Komdigi  
   https://jdih.komdigi.go.id/produk_hukum/view/id/832/t/crc32/

3. PSE Lingkup Privat registration criteria include systems used for offering/trading goods or services, financial transaction services, and certain processing of personal data related to electronic transactions.  
   Source: Komdigi PSE  
   https://pse.komdigi.go.id/panduan/kriteria-pendaftaran-pse-lingkup-privat

4. Android's current guidance recommends requesting location only when needed in context and treating background location as a restricted capability requiring a strong core-function justification and appropriate permission handling.  
   Sources:
   https://developer.android.com/develop/sensors-and-location/location/permissions/background
   https://developer.android.com/develop/sensors-and-location/location/permissions

These references are product constraints, not legal advice. Re-check official sources before production release.

---

# 73. Final Product Statement

**SeWaroeng**

> Satu platform untuk menjalankan bisnis UMKM: jualan, stok, pelanggan, karyawan, booking, pembayaran, keuangan, sampai analisis bisnis berbasis data.

Long-term:

> **SeWaroeng membantu merchant menjalankan bisnis hari ini dan memahami apa yang harus dilakukan besok.**

---

# Appendix A — MVP Feature Checklist

```text
[ ] Authentication
[ ] Business onboarding
[ ] Outlet
[ ] User/RBAC
[ ] Product
[ ] Category
[ ] Variant
[ ] POS
[ ] Shift
[ ] Payment recording
[ ] Receipt
[ ] Customer
[ ] Inventory ledger
[ ] Stock adjustment
[ ] Stock opname
[ ] Low stock
[ ] Expense
[ ] Basic cashflow
[ ] Basic P&L
[ ] Dashboard
[ ] Notifications
[ ] Audit log
[ ] Tenant isolation
[ ] Offline POS
[ ] Sync engine
[ ] Automated tests
[ ] CI/CD
[ ] Monitoring
```

# Appendix B — V1 Feature Checklist

```text
[ ] Recipe
[ ] HPP
[ ] Supplier
[ ] Purchase Order
[ ] Goods Receipt
[ ] Booking
[ ] Table
[ ] QR Table Order
[ ] WhatsApp
[ ] Loyalty
[ ] Multi-outlet
[ ] Payment reconciliation
[ ] Advanced finance
[ ] Export
```

# Appendix C — V2 Feature Checklist

```text
[ ] AI Copilot
[ ] AI onboarding
[ ] Forecasting
[ ] Anomaly detection
[ ] Smart reorder
[ ] CRM automation
[ ] Advanced campaign
[ ] Integrations
```

# Appendix D — Vision Checklist

```text
[ ] Consumer Discover
[ ] Consumer account
[ ] Merchant public profile
[ ] Booking discovery
[ ] Live crowd indicator
[ ] Aggregated location signals
[ ] Queue prediction
[ ] Consumer loyalty network
[ ] Merchant demand intelligence
```