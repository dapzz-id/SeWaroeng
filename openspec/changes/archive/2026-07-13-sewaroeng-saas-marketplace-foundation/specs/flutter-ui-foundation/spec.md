## ADDED Requirements

### Requirement: Glass UI Widget System
Aplikasi Flutter SHALL menggunakan sistem widget GlassContainer yang mengenkapsulasi efek BackdropFilter + gradient semi-transparan. Widget MUST dibungkus dengan RepaintBoundary untuk optimasi render.

#### Scenario: GlassContainer renders with effect on capable device
- **WHEN** DeviceCapabilityService mendeteksi device dengan RAM >= 3GB dan GPU tier medium atau tinggi
- **THEN** GlassContainer merender BackdropFilter dengan sigma blur 10.0 dan gradient overlay transparan (glassmorphism)

#### Scenario: GlassContainer falls back on low-end device
- **WHEN** DeviceCapabilityService mendeteksi device dengan RAM < 3GB atau GPU tier rendah
- **THEN** GlassContainer merender versi fallback: solid color semi-transparan (Colors.white.withOpacity(0.15)) TANPA BackdropFilter

### Requirement: SVG Icon System
Seluruh ikon dalam aplikasi Flutter MUST menggunakan format SVG aset vektor melalui widget SvgIcon yang reusable. Tidak diperbolehkan menggunakan IconData atau PNG untuk ikon.

#### Scenario: Icon rendered from SVG asset
- **WHEN** widget SvgIcon dipanggil dengan path aset dan ukuran
- **THEN** ikon dirender dari file .svg di assets/icons/ menggunakan flutter_svg package

#### Scenario: Icon color themeable
- **WHEN** SvgIcon menerima parameter color
- **THEN** SVG dirender dengan warna yang ditentukan (color filter applied)

### Requirement: Clean Architecture Layer Separation
Aplikasi Flutter MUST mengimplementasikan Clean Architecture dengan tiga lapisan: Presentation (Widgets + StateNotifiers), Domain (Entities + Use Cases + Repository Contracts), Data (Repository Implementations + Data Sources).

#### Scenario: Use case invoked from presentation
- **WHEN** user melakukan aksi di UI (misal: tap tombol "Beli")
- **THEN** StateNotifier memanggil Use Case; Use Case memanggil Repository Contract; Repository Implementation memanggil Data Source (remote atau local)

### Requirement: Liquid Glass Design Language
Aplikasi MUST menerapkan bahasa desain hibrida: Liquid Glass (Apple iOS 26 style), Material Design 3, dan Glassmorphism. Color scheme SHALL menggunakan palet warna brand SeWaroeng yang hangat dan premium.

#### Scenario: App theme applied globally
- **WHEN** aplikasi diinisiasi
- **THEN** ThemeData global diterapkan dengan font Inter/Poppins dari Google Fonts, color scheme warm orange-amber-cream, dan komponen Material 3

### Requirement: State Management with Riverpod
Seluruh state management aplikasi MUST menggunakan Riverpod (StateNotifierProvider untuk mutable state, AsyncNotifierProvider untuk async state). Provider MUST diorganisasi per-feature.

#### Scenario: Feature state isolated
- **WHEN** state di feature produk diupdate
- **THEN** hanya widget yang depend pada provider produk yang rebuild; widget fitur lain tidak terpengaruh
