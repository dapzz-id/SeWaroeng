## ADDED Requirements

### Requirement: Kurir Toko Registration
Tenant SHALL dapat mendaftarkan Kurir Toko mereka sendiri dengan data: nama kurir, jangkauan radius (km), tarif per km (IDR), dan lokasi asal pengiriman (koordinat GPS).

#### Scenario: Tenant registers store courier
- **WHEN** tenant Owner mengirimkan POST /api/v1/store/couriers dengan data kurir dan koordinat lokasi
- **THEN** sistem menyimpan data kurir dengan kolom location bertipe GEOGRAPHY(POINT, 4326) dan GIST index

### Requirement: Shipping Cost Calculation with PostGIS
Saat checkout, sistem SHALL menghitung ongkos kirim Kurir Toko menggunakan fungsi ST_DistanceSphere antara koordinat toko dan koordinat alamat customer.

#### Scenario: Shipping cost calculated
- **WHEN** customer melakukan checkout dan memilih Kurir Toko
- **THEN** sistem menjalankan query PostGIS: ST_DistanceSphere(store.location, customer_address.location) / 1000 * courier.rate_per_km, mengembalikan estimasi biaya

#### Scenario: Customer outside delivery radius
- **WHEN** jarak antara toko dan alamat customer melebihi jangkauan kurir
- **THEN** sistem mengembalikan error OUTSIDE_DELIVERY_RADIUS dengan pesan yang jelas

### Requirement: Spatial Index on Location Columns
Semua kolom bertipe GEOGRAPHY atau GEOMETRY MUST memiliki indeks spasial GIST untuk performa query optimal.

#### Scenario: GIST index exists on courier location
- **WHEN** database migration dijalankan
- **THEN** tabel store_couriers memiliki GIST index pada kolom location

#### Scenario: GIST index exists on store location
- **WHEN** database migration dijalankan
- **THEN** tabel stores memiliki GIST index pada kolom location
