import 'package:flutter/material.dart';
import '../../core/tenant/tenant_model.dart';
import '../../core/tenant/tenant_service.dart';

class BusinessProfileScreen extends StatefulWidget {
  final TenantService tenantService;

  const BusinessProfileScreen({super.key, required this.tenantService});

  @override
  State<BusinessProfileScreen> createState() => _BusinessProfileScreenState();
}

class _BusinessProfileScreenState extends State<BusinessProfileScreen> {
  final _formKey = GlobalKey<FormState>();

  late TextEditingController _nameController;
  late TextEditingController _slugController;
  late TextEditingController _phoneController;
  late TextEditingController _emailController;
  late TextEditingController _descriptionController;
  late TextEditingController _addressLineController;
  late TextEditingController _cityController;
  late TextEditingController _provinceController;
  late TextEditingController _postalCodeController;
  late TextEditingController _latController;
  late TextEditingController _lngController;

  String _businessType = 'fnb';
  String _currency = 'IDR';
  String _timezone = 'Asia/Jakarta';

  @override
  void initState() {
    super.initState();
    final tenant =
        widget.tenantService.activeTenant ??
        const TenantModel(id: '', name: '', slug: '');

    _nameController = TextEditingController(text: tenant.name);
    _slugController = TextEditingController(text: tenant.slug);
    _phoneController = TextEditingController(text: tenant.phone ?? '');
    _emailController = TextEditingController(text: tenant.email ?? '');
    _descriptionController = TextEditingController(
      text: tenant.description ?? '',
    );
    _addressLineController = TextEditingController(
      text: tenant.addressLine ?? '',
    );
    _cityController = TextEditingController(text: tenant.city ?? '');
    _provinceController = TextEditingController(text: tenant.province ?? '');
    _postalCodeController = TextEditingController(
      text: tenant.postalCode ?? '',
    );
    _latController = TextEditingController(
      text: tenant.latitude?.toString() ?? '',
    );
    _lngController = TextEditingController(
      text: tenant.longitude?.toString() ?? '',
    );

    _businessType = tenant.businessType;
    _currency = tenant.currency;
    _timezone = tenant.timezone;
  }

  @override
  void dispose() {
    _nameController.dispose();
    _slugController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    _descriptionController.dispose();
    _addressLineController.dispose();
    _cityController.dispose();
    _provinceController.dispose();
    _postalCodeController.dispose();
    _latController.dispose();
    _lngController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    final tenant = widget.tenantService.activeTenant;
    if (tenant == null) return;

    final data = <String, dynamic>{
      'name': _nameController.text.trim(),
      'slug': _slugController.text.trim(),
      'business_type': _businessType,
      'phone': _phoneController.text.trim().isNotEmpty
          ? _phoneController.text.trim()
          : null,
      'email': _emailController.text.trim().isNotEmpty
          ? _emailController.text.trim()
          : null,
      'description': _descriptionController.text.trim().isNotEmpty
          ? _descriptionController.text.trim()
          : null,
      'address_line': _addressLineController.text.trim().isNotEmpty
          ? _addressLineController.text.trim()
          : null,
      'city': _cityController.text.trim().isNotEmpty
          ? _cityController.text.trim()
          : null,
      'province': _provinceController.text.trim().isNotEmpty
          ? _provinceController.text.trim()
          : null,
      'postal_code': _postalCodeController.text.trim().isNotEmpty
          ? _postalCodeController.text.trim()
          : null,
      'currency': _currency,
      'timezone': _timezone,
      if (_latController.text.isNotEmpty)
        'latitude': double.tryParse(_latController.text),
      if (_lngController.text.isNotEmpty)
        'longitude': double.tryParse(_lngController.text),
    };

    final success = await widget.tenantService.updateBusiness(
      tenantId: tenant.id,
      data: data,
    );

    if (success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Profil bisnis berhasil diperbarui.'),
          backgroundColor: Color(0xFF059669),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: const Text(
          'Profil Bisnis',
          style: TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B),
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: SafeArea(
        child: AnimatedBuilder(
          animation: widget.tenantService,
          builder: (context, _) {
            final isLoading = widget.tenantService.isLoading;
            final errorMessage = widget.tenantService.errorMessage;
            final tenant = widget.tenantService.activeTenant;

            return Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(
                  horizontal: 24.0,
                  vertical: 24.0,
                ),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 600),
                  child: Form(
                    key: _formKey,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // Error Banner
                        if (errorMessage != null) ...[
                          Container(
                            key: const Key('business_profile_error_banner'),
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: const Color(0xFF7F1D1D),
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(
                                color: const Color(0xFFDC2626),
                              ),
                            ),
                            child: Row(
                              children: [
                                const Icon(
                                  Icons.error_outline,
                                  color: Colors.white,
                                  size: 20,
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: Text(
                                    errorMessage,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: 13,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 20),
                        ],

                        // Status Card
                        Card(
                          color: const Color(0xFF1E293B),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                            side: const BorderSide(color: Color(0xFF334155)),
                          ),
                          child: Padding(
                            padding: const EdgeInsets.all(16.0),
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text(
                                      'Status Bisnis',
                                      style: TextStyle(
                                        color: Color(0xFF94A3B8),
                                        fontSize: 12,
                                      ),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      tenant?.status == 'active'
                                          ? 'AKTIF'
                                          : (tenant?.status == 'suspended'
                                                ? 'DITANGGUHKAN'
                                                : 'DIARSIPKAN'),
                                      key: const Key('business_status_badge'),
                                      style: TextStyle(
                                        color: tenant?.status == 'active'
                                            ? const Color(0xFF34D399)
                                            : const Color(0xFFF87171),
                                        fontSize: 16,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ],
                                ),
                                Chip(
                                  label: Text(
                                    tenant?.role?.toUpperCase() ?? 'OWNER',
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: 12,
                                    ),
                                  ),
                                  backgroundColor: const Color(0xFF0284C7),
                                ),
                              ],
                            ),
                          ),
                        ),
                        const SizedBox(height: 20),

                        // Section 1: Business Identity
                        _buildSectionHeader('Identitas Bisnis'),
                        TextFormField(
                          key: const Key('profile_business_name_input'),
                          controller: _nameController,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Nama Bisnis *',
                            Icons.business,
                          ),
                          validator: (val) => val == null || val.trim().isEmpty
                              ? 'Nama bisnis wajib diisi.'
                              : null,
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          key: const Key('profile_business_slug_input'),
                          controller: _slugController,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Slug / URL Bisnis *',
                            Icons.link,
                          ),
                          validator: (val) => val == null || val.trim().isEmpty
                              ? 'Slug wajib diisi.'
                              : null,
                        ),
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          key: const Key('profile_business_type_dropdown'),
                          initialValue: _businessType,
                          dropdownColor: const Color(0xFF1E293B),
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Jenis Usaha',
                            Icons.category,
                          ),
                          items: const [
                            DropdownMenuItem(
                              value: 'fnb',
                              child: Text('F&B (Makanan & Minuman)'),
                            ),
                            DropdownMenuItem(
                              value: 'retail',
                              child: Text('Retail & Toko'),
                            ),
                            DropdownMenuItem(
                              value: 'service',
                              child: Text('Jasa & Layanan'),
                            ),
                            DropdownMenuItem(
                              value: 'production',
                              child: Text('Produksi & Manufaktur'),
                            ),
                            DropdownMenuItem(
                              value: 'online_seller',
                              child: Text('Penjual Online'),
                            ),
                          ],
                          onChanged: (val) {
                            if (val != null) {
                              setState(() => _businessType = val);
                            }
                          },
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          key: const Key('profile_business_description_input'),
                          controller: _descriptionController,
                          maxLines: 3,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Deskripsi Singkat',
                            Icons.description,
                          ),
                        ),
                        const SizedBox(height: 20),

                        // Section 2: Contact
                        _buildSectionHeader('Kontak Bisnis'),
                        TextFormField(
                          key: const Key('profile_business_phone_input'),
                          controller: _phoneController,
                          keyboardType: TextInputType.phone,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Nomor Telepon / WhatsApp',
                            Icons.phone,
                          ),
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          key: const Key('profile_business_email_input'),
                          controller: _emailController,
                          keyboardType: TextInputType.emailAddress,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Email Bisnis',
                            Icons.email,
                          ),
                        ),
                        const SizedBox(height: 20),

                        // Section 3: Address
                        _buildSectionHeader('Alamat Operasional'),
                        TextFormField(
                          key: const Key('profile_address_line_input'),
                          controller: _addressLineController,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Alamat Lengkap',
                            Icons.place,
                          ),
                        ),
                        const SizedBox(height: 12),
                        Row(
                          children: [
                            Expanded(
                              child: TextFormField(
                                key: const Key('profile_city_input'),
                                controller: _cityController,
                                style: const TextStyle(color: Colors.white),
                                decoration: _inputDecoration(
                                  'Kota / Kab',
                                  Icons.location_city,
                                ),
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: TextFormField(
                                key: const Key('profile_province_input'),
                                controller: _provinceController,
                                style: const TextStyle(color: Colors.white),
                                decoration: _inputDecoration(
                                  'Provinsi',
                                  Icons.map,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          key: const Key('profile_postal_code_input'),
                          controller: _postalCodeController,
                          keyboardType: TextInputType.number,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Kode Pos',
                            Icons.markunread_mailbox,
                          ),
                        ),
                        const SizedBox(height: 20),

                        // Section 4: Localization
                        _buildSectionHeader('Lokalisasi & Zona Waktu'),
                        DropdownButtonFormField<String>(
                          key: const Key('profile_currency_dropdown'),
                          initialValue: _currency,
                          isExpanded: true,
                          dropdownColor: const Color(0xFF1E293B),
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Mata Uang',
                            Icons.attach_money,
                          ),
                          items: const [
                            DropdownMenuItem(
                              value: 'IDR',
                              child: Text('IDR (Rupiah)'),
                            ),
                            DropdownMenuItem(
                              value: 'USD',
                              child: Text('USD (US Dollar)'),
                            ),
                            DropdownMenuItem(
                              value: 'SGD',
                              child: Text('SGD (Singapore Dollar)'),
                            ),
                          ],
                          onChanged: (val) {
                            if (val != null) setState(() => _currency = val);
                          },
                        ),
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          key: const Key('profile_timezone_dropdown'),
                          initialValue: _timezone,
                          isExpanded: true,
                          dropdownColor: const Color(0xFF1E293B),
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDecoration(
                            'Zona Waktu',
                            Icons.access_time,
                          ),
                          items: const [
                            DropdownMenuItem(
                              value: 'Asia/Jakarta',
                              child: Text('WIB (Asia/Jakarta)'),
                            ),
                            DropdownMenuItem(
                              value: 'Asia/Makassar',
                              child: Text('WITA (Asia/Makassar)'),
                            ),
                            DropdownMenuItem(
                              value: 'Asia/Jayapura',
                              child: Text('WIT (Asia/Jayapura)'),
                            ),
                          ],
                          onChanged: (val) {
                            if (val != null) setState(() => _timezone = val);
                          },
                        ),
                        const SizedBox(height: 28),

                        // Save Button
                        ElevatedButton(
                          key: const Key('profile_save_button'),
                          onPressed: isLoading ? null : _save,
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF0284C7),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 16),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10),
                            ),
                          ),
                          child: isLoading
                              ? const SizedBox(
                                  height: 20,
                                  width: 20,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    valueColor: AlwaysStoppedAnimation<Color>(
                                      Colors.white,
                                    ),
                                  ),
                                )
                              : const Text(
                                  'Simpan Perubahan',
                                  style: TextStyle(
                                    fontSize: 16,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _buildSectionHeader(String title) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8.0, top: 4.0),
      child: Text(
        title,
        style: const TextStyle(
          color: Color(0xFF38BDF8),
          fontSize: 14,
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }

  InputDecoration _inputDecoration(String label, IconData icon) {
    return InputDecoration(
      labelText: label,
      labelStyle: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
      prefixIcon: Icon(icon, color: const Color(0xFF94A3B8), size: 20),
      filled: true,
      fillColor: const Color(0xFF1E293B),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFF334155)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFF334155)),
      ),
    );
  }
}
