import 'package:flutter/material.dart';
import '../../core/auth/auth_service.dart';
import '../../core/tenant/tenant_model.dart';
import '../../core/tenant/tenant_service.dart';
import 'onboarding_screen.dart';

class TenantSelectionScreen extends StatelessWidget {
  final TenantService tenantService;
  final AuthService authService;
  final void Function(TenantModel tenant)? onTenantSelected;

  const TenantSelectionScreen({
    super.key,
    required this.tenantService,
    required this.authService,
    this.onTenantSelected,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: const Text(
          'Pilih Bisnis',
          style: TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B),
        elevation: 0,
        actions: [
          IconButton(
            key: const Key('tenant_selection_logout_button'),
            icon: const Icon(Icons.logout, color: Color(0xFF94A3B8)),
            tooltip: 'Keluar',
            onPressed: () async {
              await tenantService.clearContext();
              await authService.logout();
            },
          ),
        ],
      ),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 540),
            child: AnimatedBuilder(
              animation: tenantService,
              builder: (context, _) {
                final businesses = tenantService.businesses;
                final activeTenant = tenantService.activeTenant;

                if (businesses.isEmpty) {
                  return Padding(
                    padding: const EdgeInsets.all(24.0),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(
                          Icons.storefront_outlined,
                          size: 64,
                          color: Color(0xFF64748B),
                        ),
                        const SizedBox(height: 16),
                        const Text(
                          'Belum Ada Bisnis',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 8),
                        const Text(
                          'Anda belum memiliki bisnis terdaftar. Buat bisnis pertama Anda sekarang.',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: Color(0xFF94A3B8),
                            fontSize: 14,
                          ),
                        ),
                        const SizedBox(height: 24),
                        ElevatedButton.icon(
                          key: const Key('empty_state_create_business_button'),
                          onPressed: () {
                            Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => OnboardingScreen(
                                  tenantService: tenantService,
                                  onSuccess: () => Navigator.of(context).pop(),
                                ),
                              ),
                            );
                          },
                          icon: const Icon(Icons.add),
                          label: const Text('Buat Bisnis Baru'),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF0284C7),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(
                              horizontal: 24,
                              vertical: 14,
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                }

                return ListView(
                  padding: const EdgeInsets.all(24.0),
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Daftar Bisnis Anda',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        TextButton.icon(
                          key: const Key('add_new_business_button'),
                          onPressed: () {
                            Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => OnboardingScreen(
                                  tenantService: tenantService,
                                  onSuccess: () => Navigator.of(context).pop(),
                                ),
                              ),
                            );
                          },
                          icon: const Icon(
                            Icons.add,
                            color: Color(0xFF38BDF8),
                            size: 18,
                          ),
                          label: const Text(
                            'Tambah Bisnis',
                            style: TextStyle(color: Color(0xFF38BDF8)),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    ...businesses.map((b) {
                      final isActive = activeTenant?.id == b.id;

                      return Card(
                        key: Key('tenant_card_${b.id}'),
                        color: isActive
                            ? const Color(0xFF1E3A5F)
                            : const Color(0xFF1E293B),
                        margin: const EdgeInsets.only(bottom: 12),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                          side: BorderSide(
                            color: isActive
                                ? const Color(0xFF0284C7)
                                : const Color(0xFF334155),
                            width: isActive ? 1.5 : 1,
                          ),
                        ),
                        child: ListTile(
                          contentPadding: const EdgeInsets.symmetric(
                            horizontal: 16,
                            vertical: 8,
                          ),
                          leading: CircleAvatar(
                            backgroundColor: isActive
                                ? const Color(0xFF0284C7)
                                : const Color(0xFF334155),
                            child: const Icon(Icons.store, color: Colors.white),
                          ),
                          title: Text(
                            b.name,
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          subtitle: Text(
                            '${b.slug} • ${b.role ?? "Owner"}',
                            style: const TextStyle(
                              color: Color(0xFF94A3B8),
                              fontSize: 12,
                            ),
                          ),
                          trailing: isActive
                              ? const Chip(
                                  label: Text(
                                    'Aktif',
                                    style: TextStyle(
                                      color: Colors.white,
                                      fontSize: 11,
                                    ),
                                  ),
                                  backgroundColor: Color(0xFF0284C7),
                                  padding: EdgeInsets.zero,
                                )
                              : const Icon(
                                  Icons.chevron_right,
                                  color: Color(0xFF64748B),
                                ),
                          onTap: () async {
                            await tenantService.setActiveTenant(b);
                            onTenantSelected?.call(b);
                          },
                        ),
                      );
                    }),
                  ],
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}
