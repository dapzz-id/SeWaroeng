import 'package:flutter/material.dart';
import '../../core/tenant/tenant_service.dart';

class OnboardingScreen extends StatefulWidget {
  final TenantService tenantService;
  final VoidCallback? onSuccess;

  const OnboardingScreen({
    super.key,
    required this.tenantService,
    this.onSuccess,
  });

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _formKey = GlobalKey<FormState>();
  final _businessNameController = TextEditingController();
  final _slugController = TextEditingController();
  final _outletNameController = TextEditingController();
  final _outletCodeController = TextEditingController(text: 'PUSAT');

  bool _isAutoSlug = true;

  @override
  void dispose() {
    _businessNameController.dispose();
    _slugController.dispose();
    _outletNameController.dispose();
    _outletCodeController.dispose();
    super.dispose();
  }

  void _onBusinessNameChanged(String val) {
    if (_isAutoSlug) {
      final generated = val
          .toLowerCase()
          .replaceAll(RegExp(r'[^a-z0-9\s-]'), '')
          .replaceAll(RegExp(r'\s+'), '-');
      _slugController.text = generated;
    }
    if (_outletNameController.text.isEmpty ||
        _outletNameController.text.endsWith(' - Pusat')) {
      _outletNameController.text = val.isNotEmpty ? '$val - Pusat' : '';
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final success = await widget.tenantService.createBusiness(
      name: _businessNameController.text,
      slug: _slugController.text.isNotEmpty ? _slugController.text : null,
      outletName: _outletNameController.text.isNotEmpty
          ? _outletNameController.text
          : null,
      outletCode: _outletCodeController.text.isNotEmpty
          ? _outletCodeController.text
          : null,
    );

    if (success && mounted) {
      widget.onSuccess?.call();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A), // Slate 900
      appBar: AppBar(
        title: const Text(
          'Buat Bisnis Baru',
          style: TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B), // Slate 800
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(
              horizontal: 24.0,
              vertical: 32.0,
            ),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: AnimatedBuilder(
                animation: widget.tenantService,
                builder: (context, _) {
                  final isLoading = widget.tenantService.isLoading;
                  final errorMessage = widget.tenantService.errorMessage;

                  return Card(
                    elevation: 8,
                    color: const Color(0xFF1E293B),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16),
                      side: const BorderSide(color: Color(0xFF334155)),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.all(28.0),
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const Row(
                              children: [
                                Icon(
                                  Icons.storefront,
                                  color: Color(0xFF38BDF8),
                                  size: 32,
                                ),
                                SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        'Selamat Datang di SeWaroeng',
                                        style: TextStyle(
                                          color: Colors.white,
                                          fontSize: 18,
                                          fontWeight: FontWeight.bold,
                                        ),
                                      ),
                                      Text(
                                        'Daftarkan bisnis dan outlet pertama Anda',
                                        style: TextStyle(
                                          color: Color(0xFF94A3B8),
                                          fontSize: 13,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 24),

                            if (errorMessage != null) ...[
                              Container(
                                key: const Key('onboarding_error_banner'),
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

                            // Business Name
                            TextFormField(
                              key: const Key('onboarding_business_name_input'),
                              controller: _businessNameController,
                              style: const TextStyle(color: Colors.white),
                              onChanged: _onBusinessNameChanged,
                              decoration: InputDecoration(
                                labelText: 'Nama Bisnis *',
                                labelStyle: const TextStyle(
                                  color: Color(0xFF94A3B8),
                                ),
                                prefixIcon: const Icon(
                                  Icons.business,
                                  color: Color(0xFF94A3B8),
                                ),
                                filled: true,
                                fillColor: const Color(0xFF0F172A),
                                border: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10),
                                  borderSide: const BorderSide(
                                    color: Color(0xFF334155),
                                  ),
                                ),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10),
                                  borderSide: const BorderSide(
                                    color: Color(0xFF334155),
                                  ),
                                ),
                              ),
                              validator: (val) {
                                if (val == null || val.trim().isEmpty) {
                                  return 'Nama bisnis wajib diisi.';
                                }
                                return null;
                              },
                            ),
                            const SizedBox(height: 16),

                            // Business Slug
                            TextFormField(
                              key: const Key('onboarding_business_slug_input'),
                              controller: _slugController,
                              style: const TextStyle(color: Colors.white),
                              onChanged: (val) {
                                setState(() {
                                  _isAutoSlug = val.isEmpty;
                                });
                              },
                              decoration: InputDecoration(
                                labelText: 'Slug / URL Bisnis *',
                                labelStyle: const TextStyle(
                                  color: Color(0xFF94A3B8),
                                ),
                                prefixIcon: const Icon(
                                  Icons.link,
                                  color: Color(0xFF94A3B8),
                                ),
                                helperText:
                                    'Hanya huruf kecil, angka, dan strip (contoh: warung-kopi)',
                                helperStyle: const TextStyle(
                                  color: Color(0xFF64748B),
                                ),
                                filled: true,
                                fillColor: const Color(0xFF0F172A),
                                border: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10),
                                  borderSide: const BorderSide(
                                    color: Color(0xFF334155),
                                  ),
                                ),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10),
                                  borderSide: const BorderSide(
                                    color: Color(0xFF334155),
                                  ),
                                ),
                              ),
                              validator: (val) {
                                if (val == null || val.trim().isEmpty) {
                                  return 'Slug bisnis wajib diisi.';
                                }
                                if (!RegExp(
                                  r'^[a-z0-9]+(?:-[a-z0-9]+)*$',
                                ).hasMatch(val.trim())) {
                                  return 'Format slug tidak valid.';
                                }
                                return null;
                              },
                            ),
                            const SizedBox(height: 20),

                            const Divider(color: Color(0xFF334155)),
                            const SizedBox(height: 16),

                            // Outlet Name
                            TextFormField(
                              key: const Key('onboarding_outlet_name_input'),
                              controller: _outletNameController,
                              style: const TextStyle(color: Colors.white),
                              decoration: InputDecoration(
                                labelText: 'Nama Outlet Pertama *',
                                labelStyle: const TextStyle(
                                  color: Color(0xFF94A3B8),
                                ),
                                prefixIcon: const Icon(
                                  Icons.store,
                                  color: Color(0xFF94A3B8),
                                ),
                                filled: true,
                                fillColor: const Color(0xFF0F172A),
                                border: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10),
                                  borderSide: const BorderSide(
                                    color: Color(0xFF334155),
                                  ),
                                ),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10),
                                  borderSide: const BorderSide(
                                    color: Color(0xFF334155),
                                  ),
                                ),
                              ),
                              validator: (val) {
                                if (val == null || val.trim().isEmpty) {
                                  return 'Nama outlet wajib diisi.';
                                }
                                return null;
                              },
                            ),
                            const SizedBox(height: 16),

                            // Outlet Code
                            TextFormField(
                              key: const Key('onboarding_outlet_code_input'),
                              controller: _outletCodeController,
                              style: const TextStyle(color: Colors.white),
                              textCapitalization: TextCapitalization.characters,
                              decoration: InputDecoration(
                                labelText: 'Kode Outlet *',
                                labelStyle: const TextStyle(
                                  color: Color(0xFF94A3B8),
                                ),
                                prefixIcon: const Icon(
                                  Icons.qr_code,
                                  color: Color(0xFF94A3B8),
                                ),
                                helperText:
                                    'Singkatan unik outlet (contoh: PUSAT, CAB-1)',
                                helperStyle: const TextStyle(
                                  color: Color(0xFF64748B),
                                ),
                                filled: true,
                                fillColor: const Color(0xFF0F172A),
                                border: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10),
                                  borderSide: const BorderSide(
                                    color: Color(0xFF334155),
                                  ),
                                ),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10),
                                  borderSide: const BorderSide(
                                    color: Color(0xFF334155),
                                  ),
                                ),
                              ),
                              validator: (val) {
                                if (val == null || val.trim().isEmpty) {
                                  return 'Kode outlet wajib diisi.';
                                }
                                return null;
                              },
                            ),
                            const SizedBox(height: 28),

                            // Submit Button
                            ElevatedButton(
                              key: const Key('onboarding_submit_button'),
                              onPressed: isLoading ? null : _submit,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF0284C7),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(
                                  vertical: 16,
                                ),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(10),
                                ),
                                disabledBackgroundColor: const Color(
                                  0xFF0369A1,
                                ).withAlpha(128),
                              ),
                              child: isLoading
                                  ? const SizedBox(
                                      height: 20,
                                      width: 20,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                        valueColor:
                                            AlwaysStoppedAnimation<Color>(
                                              Colors.white,
                                            ),
                                      ),
                                    )
                                  : const Text(
                                      'Mulai Bisnis Saya',
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
                  );
                },
              ),
            ),
          ),
        ),
      ),
    );
  }
}
