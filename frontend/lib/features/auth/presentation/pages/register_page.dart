// Neura Cakrawira Solusi | SeWaroeng
// Auth feature - Register Page
import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../../shared/widgets/glass_container.dart';
import '../../../../core/theme/app_colors.dart';

class RegisterPage extends ConsumerStatefulWidget {
  const RegisterPage({super.key});

  @override
  ConsumerState<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends ConsumerState<RegisterPage> {
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();
  bool _isLoading = false;
  bool _obscurePassword = true;
  bool _obscureConfirmPassword = true;
  String? _errorMessage;

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  void _register() async {
    // Basic client-side validation
    final name = _nameController.text.trim();
    final email = _emailController.text.trim();
    final password = _passwordController.text;
    final confirmPassword = _confirmPasswordController.text;

    if (name.isEmpty || email.isEmpty || password.isEmpty) {
      setState(() => _errorMessage = 'Semua field harus diisi');
      return;
    }

    if (password.length < 6) {
      setState(() => _errorMessage = 'Password minimal 6 karakter');
      return;
    }

    if (password != confirmPassword) {
      setState(() => _errorMessage = 'Password tidak cocok');
      return;
    }

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    // Simulate register delay
    // In real app: ref.read(authNotifierProvider.notifier).register(...)
    await Future.delayed(const Duration(seconds: 1));
    setState(() => _isLoading = false);

    // If successful, navigate to login
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Pendaftaran berhasil! Silakan masuk.'),
          backgroundColor: AppColors.success,
        ),
      );
      context.go('/login');
    }
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    final isWide = size.width >= 720;
    final isShort = size.height < 700;

    // --- REUSABLE FORM FIELDS ---
    // Ekstrak bagian form agar bisa dipanggil baik di mode split (desktop)
    // maupun mode stacked (mobile).
    final Widget formFields = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Text(
          'Buat Akun Baru',
          textAlign: TextAlign.center,
          style: Theme.of(context)
              .textTheme
              .titleMedium
              ?.copyWith(
                color: Colors.black87,
                fontWeight: FontWeight.w700,
              ),
        ),

        // Error message
        if (_errorMessage != null) ...[
          const SizedBox(height: 16),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(
              horizontal: 16,
              vertical: 10,
            ),
            decoration: BoxDecoration(
              color: AppColors.error.withOpacity(0.1),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: AppColors.error.withOpacity(0.3),
              ),
            ),
            child: Row(
              children: [
                const Icon(
                  Icons.error_outline,
                  color: AppColors.error,
                  size: 18,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    _errorMessage!,
                    style: const TextStyle(
                      color: AppColors.error,
                      fontSize: 13,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],

        SizedBox(height: isShort ? 16 : 28),

        // Name field
        _GlassTextField(
          controller: _nameController,
          label: 'Nama Lengkap',
          icon: Icons.person_outline,
          keyboardType: TextInputType.name,
          textInputAction: TextInputAction.next,
        ),
        const SizedBox(height: 14),

        // Email field
        _GlassTextField(
          controller: _emailController,
          label: 'Email',
          icon: Icons.email_outlined,
          keyboardType: TextInputType.emailAddress,
          textInputAction: TextInputAction.next,
        ),
        const SizedBox(height: 14),

        // Password field
        _GlassTextField(
          controller: _passwordController,
          label: 'Password',
          icon: Icons.lock_outline,
          obscureText: _obscurePassword,
          textInputAction: TextInputAction.next,
          suffixIcon: IconButton(
            icon: Icon(
              _obscurePassword
                  ? Icons.visibility_off_outlined
                  : Icons.visibility_outlined,
              color: Colors.black45,
              size: 20,
            ),
            onPressed: () => setState(
              () => _obscurePassword = !_obscurePassword,
            ),
          ),
        ),
        const SizedBox(height: 14),

        // Confirm Password field
        _GlassTextField(
          controller: _confirmPasswordController,
          label: 'Konfirmasi Password',
          icon: Icons.lock_reset_outlined,
          obscureText: _obscureConfirmPassword,
          textInputAction: TextInputAction.done,
          suffixIcon: IconButton(
            icon: Icon(
              _obscureConfirmPassword
                  ? Icons.visibility_off_outlined
                  : Icons.visibility_outlined,
              color: Colors.black45,
              size: 20,
            ),
            onPressed: () => setState(
              () => _obscureConfirmPassword = !_obscureConfirmPassword,
            ),
          ),
        ),

        SizedBox(height: isShort ? 20 : 28),

        // Register button
        SizedBox(
          width: double.infinity,
          height: 52,
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              gradient: const LinearGradient(
                colors: [
                  AppColors.primary,
                  Color(0xFFFF7043),
                ],
              ),
              boxShadow: [
                BoxShadow(
                  color: AppColors.primary.withOpacity(0.35),
                  blurRadius: 16,
                  offset: const Offset(0, 8),
                ),
              ],
            ),
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.transparent,
                shadowColor: Colors.transparent,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16),
                ),
              ),
              onPressed: _isLoading ? null : _register,
              child: _isLoading
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const Text(
                      'DAFTAR',
                      style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 1.0,
                      ),
                    ),
            ),
          ),
        ),

        const SizedBox(height: 16),

        // Back to login link
        TextButton(
          onPressed: () => context.go('/login'),
          child: RichText(
            text: TextSpan(
              text: 'Sudah punya akun? ',
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: Colors.black54),
              children: const [
                TextSpan(
                  text: 'Masuk di sini',
                  style: TextStyle(
                    color: AppColors.primary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );

    // --- RESPONSIVE LAYOUT SWITCHER ---
    Widget content;
    if (isWide) {
      // DESKTOP LAYOUT (Split Screen)
      content = Row(
        children: [
          // KIRI: INFORMASI BRAND
          Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 40),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  if (!isShort) ...[
                    _MarketBadge(isWide: true),
                    const SizedBox(height: 32),
                  ],
                  _BrandMark(isShort: isShort),
                  const SizedBox(height: 16),
                  Text(
                    'Bergabung dengan SeWaroeng',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          color: Colors.black54,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 0.2,
                        ),
                  ),
                  const SizedBox(height: 48),
                  if (!isShort)
                    const Text(
                      '#DukungUMKM • Belanja Lokal, Untung Bersama',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.black45,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.2,
                      ),
                    ),
                ],
              ),
            ),
          ),
          // KANAN: FORM REGISTRASI
          Expanded(
            child: Center(
              child: SingleChildScrollView(
                padding: EdgeInsets.symmetric(
                  horizontal: 24,
                  vertical: isShort ? 12 : 28,
                ),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(28),
                    child: BackdropFilter(
                      filter: ImageFilter.blur(sigmaX: 24, sigmaY: 24),
                      child: GlassContainer(
                        padding: EdgeInsets.symmetric(
                          horizontal: 40,
                          vertical: isShort ? 20 : 32,
                        ),
                        width: double.infinity,
                        child: formFields,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      );
    } else {
      // MOBILE LAYOUT (Stacked Screen)
      content = Center(
        child: SingleChildScrollView(
          padding: EdgeInsets.symmetric(
            horizontal: 20,
            vertical: isShort ? 12 : 28,
          ),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (!isShort) ...[
                  _MarketBadge(isWide: false),
                  const SizedBox(height: 20),
                ],
                ClipRRect(
                  borderRadius: BorderRadius.circular(28),
                  child: BackdropFilter(
                    filter: ImageFilter.blur(sigmaX: 24, sigmaY: 24),
                    child: GlassContainer(
                      padding: EdgeInsets.symmetric(
                        horizontal: 28,
                        vertical: isShort ? 20 : 32,
                      ),
                      width: double.infinity,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          _BrandMark(isShort: isShort),
                          const SizedBox(height: 6),
                          Text(
                            'Bergabung dengan SeWaroeng',
                            textAlign: TextAlign.center,
                            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                  color: Colors.black54,
                                  fontWeight: FontWeight.w600,
                                  letterSpacing: 0.2,
                                ),
                          ),
                          SizedBox(height: isShort ? 14 : 20),
                          const Divider(
                            color: Colors.black12,
                            height: 1,
                            thickness: 1,
                          ),
                          SizedBox(height: isShort ? 14 : 20),
                          formFields, // Memanggil formFields yang diekstrak tadi
                        ],
                      ),
                    ),
                  ),
                ),
                SizedBox(height: isShort ? 12 : 20),
                if (!isShort)
                  const Text(
                    '#DukungUMKM • Belanja Lokal, Untung Bersama',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Colors.black45,
                      fontSize: 11.5,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 0.2,
                    ),
                  ),
              ],
            ),
          ),
        ),
      );
    }

    return Scaffold(
      body: Stack(
        fit: StackFit.expand,
        children: [
          // --- Layered warm UMKM-inspired background ---
          Container(
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Color(0xFFFFF3E0), // warm cream
                  Color(0xFFFFE0B2), // soft market orange
                  Color(0xFFFFCCBC), // terracotta blush
                ],
              ),
            ),
          ),

          // Soft decorative "liquid glass" blobs for depth
          Positioned(
            top: -size.width * 0.2,
            right: -size.width * 0.15,
            child: _LiquidBlob(
              diameter: size.width * 0.7,
              colors: [
                AppColors.primary.withOpacity(0.30),
                AppColors.primary.withOpacity(0.04),
              ],
            ),
          ),
          Positioned(
            bottom: -size.width * 0.25,
            left: -size.width * 0.2,
            child: _LiquidBlob(
              diameter: size.width * 0.8,
              colors: [
                const Color(0xFFFF7043).withOpacity(0.28),
                const Color(0xFFFF7043).withOpacity(0.03),
              ],
            ),
          ),
          Positioned(
            top: size.height * 0.35,
            left: -size.width * 0.15,
            child: _LiquidBlob(
              diameter: size.width * 0.45,
              colors: [
                const Color(0xFFF5A623).withOpacity(0.20),
                const Color(0xFFF5A623).withOpacity(0.02),
              ],
            ),
          ),

          // --- Register content (Responsive View) ---
          SafeArea(child: content),
        ],
      ),
    );
  }
}

/// Brand wordmark with gradient shader
class _BrandMark extends StatelessWidget {
  final bool isShort;
  const _BrandMark({required this.isShort});

  @override
  Widget build(BuildContext context) {
    return ShaderMask(
      shaderCallback: (bounds) => const LinearGradient(
        colors: [
          AppColors.primary,
          Color(0xFFFF7043),
        ],
      ).createShader(bounds),
      child: Text(
        'SeWaroeng',
        textAlign: TextAlign.center,
        style: (isShort
                ? Theme.of(context).textTheme.headlineSmall
                : Theme.of(context).textTheme.displaySmall)
            ?.copyWith(
          color: Colors.white,
          fontWeight: FontWeight.w800,
          letterSpacing: -0.5,
        ),
      ),
    );
  }
}

/// Small pill badge above the card, echoing a traditional market/UMKM feel.
class _MarketBadge extends StatelessWidget {
  final bool isWide;
  const _MarketBadge({required this.isWide});

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(999),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 16, sigmaY: 16),
        child: Container(
          padding: EdgeInsets.symmetric(
            horizontal: isWide ? 20 : 16,
            vertical: 8,
          ),
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(0.35),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(color: Colors.white.withOpacity(0.5)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.person_add_rounded,
                  size: 16, color: Color(0xFFBF360C)),
              const SizedBox(width: 6),
              Text(
                'Daftar Gratis',
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: const Color(0xFFBF360C),
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.3,
                    ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Soft blurred gradient blob used to simulate a "liquid glass" backdrop.
class _LiquidBlob extends StatelessWidget {
  final double diameter;
  final List<Color> colors;
  const _LiquidBlob({required this.diameter, required this.colors});

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: diameter,
        height: diameter,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(colors: colors),
        ),
      ),
    );
  }
}

/// Glassy, material-inspired text field wrapper.
class _GlassTextField extends StatelessWidget {
  final TextEditingController controller;
  final String label;
  final IconData icon;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final bool obscureText;
  final Widget? suffixIcon;

  const _GlassTextField({
    required this.controller,
    required this.label,
    required this.icon,
    this.keyboardType,
    this.textInputAction,
    this.obscureText = false,
    this.suffixIcon,
  });

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(16),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
        child: Container(
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(0.45),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: Colors.white.withOpacity(0.6)),
          ),
          child: TextField(
            controller: controller,
            keyboardType: keyboardType,
            textInputAction: textInputAction,
            obscureText: obscureText,
            style: const TextStyle(color: Colors.black87),
            decoration: InputDecoration(
              labelText: label,
              labelStyle: const TextStyle(color: Colors.black54),
              prefixIcon: Icon(icon, color: AppColors.primary),
              suffixIcon: suffixIcon,
              filled: false,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(16),
                borderSide: BorderSide.none,
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(16),
                borderSide: BorderSide.none,
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(16),
                borderSide: BorderSide(
                  color: AppColors.primary.withOpacity(0.6),
                  width: 1.5,
                ),
              ),
              contentPadding:
                  const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
            ),
          ),
        ),
      ),
    );
  }
}