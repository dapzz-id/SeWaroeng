# Database Schema - SeWaroeng

Sistem SeWaroeng dirancang sebagai platform **Multi-Tenant**, di mana mayoritas tabel memiliki kolom `tenant_id` untuk mengisolasi data antar toko/merchant. Seluruh entitas menggunakan **UUID v7** sebagai Primary Key untuk memastikan sorting kronologis (time-ordered).

## Relasi Tabel Utama

- `tenants` (1) ── (M) `users`
- `tenants` (1) ── (M) `products`
- `tenants` (1) ── (M) `orders`
- `users` (1) ── (M) `orders` (sebagai customer atau staff)
- `orders` (1) ── (M) `order_items`
- `orders` (1) ── (1) `payments`
- `users` (1) ── (1) `wallets` ── (M) `wallet_transactions`
- `tenants` (1) ── (M) `pos_transactions` ── (M) `pos_transaction_items`
- `tenants` (1) ── (M) `store_couriers`

## Fitur Geospasial (PostGIS)
Tabel `store_couriers` menggunakan kolom `location` bertipe `GEOGRAPHY(POINT, 4326)` dengan index **GIST**. Ini memungkinkan kalkulasi tarif kurir berdasarkan jarak fisik secara presisi (contoh: fungsi `ST_DistanceSphere` dan pencarian radius menggunakan `ST_DWithin`).

## Fitur Transaksional (ACID)
Integritas data krusial dilindungi menggunakan locking DB (`SELECT FOR UPDATE`):
1. **Products (`stock`)**: Mencegah *overselling* ketika banyak pesanan dibuat pada waktu yang bersamaan.
2. **Wallets (`balance`)**: Mencegah *race-condition* saat debit/credit saldo e-wallet.

## Skema Auth
Sistem autentikasi menerapkan pola 3-Token:
- Tabel `users` menyimpan kredensial.
- Tabel `user_sessions` melacak *refresh token hash*, *IP address*, dan *user agent* untuk pengelolaan sesi (termasuk fitur revoke dari device lain).
