## ADDED Requirements

### Requirement: Product Listing with Tenant Scope
Sistem SHALL memungkinkan tenant mengelola katalog produk (fisik dan digital) yang terisolasi per tenant_id. Setiap produk MUST memiliki UUID sebagai PK.

#### Scenario: Tenant creates physical product
- **WHEN** tenant Owner/Staff mengirimkan POST /api/v1/products dengan data produk fisik (nama, deskripsi, harga, stok, kategori, berat)
- **THEN** sistem menyimpan produk dengan tenant_id dari context, mengembalikan 201 Created

#### Scenario: Product catalog cached in Redis
- **WHEN** customer mengakses GET /api/v1/stores/{store_id}/products
- **THEN** sistem memeriksa cache Redis key `catalog:{store_id}:{page}` dulu; jika miss, query DB lalu cache dengan TTL 5 menit

#### Scenario: Cache invalidated on product update
- **WHEN** tenant mengupdate atau menghapus produk
- **THEN** sistem menginvalidasi semua cache key terkait store_id tersebut di Redis

### Requirement: Product Stock Management with ACID
Operasi pemotongan stok produk fisik MUST dilakukan dalam Database Transaction dengan SELECT FOR UPDATE untuk mencegah overselling.

#### Scenario: Stock decremented on order confirmed
- **WHEN** order terkonfirmasi (pembayaran lunas)
- **THEN** sistem membuka transaksi DB, SELECT FOR UPDATE pada row produk, memeriksa stok >= quantity ordered, mengurangi stok, COMMIT; jika stok tidak cukup ROLLBACK dan return error

#### Scenario: Out of stock prevention
- **WHEN** dua customer mencoba membeli produk yang stoknya tinggal 1 secara bersamaan
- **THEN** hanya satu yang berhasil; yang lain mendapatkan error INSUFFICIENT_STOCK

### Requirement: Product Search and Filter
Sistem SHALL menyediakan endpoint pencarian produk berdasarkan nama (ILIKE), kategori, range harga, dan status stok.

#### Scenario: Search by keyword
- **WHEN** customer mengirimkan GET /api/v1/products?q=baju&category=pakaian&min_price=50000
- **THEN** sistem mengembalikan produk yang sesuai filter dengan pagination (limit/offset)
