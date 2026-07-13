// Neura Cakrawira Solusi | SeWaroeng
// Auth feature - Presentation Layer
import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:flutter_svg/flutter_svg.dart';
import '../../../../shared/widgets/glass_container.dart';
import '../../../../core/theme/app_colors.dart';
// Note: In real app, import providers for login use case and state.

class LoginPage extends ConsumerStatefulWidget {
  const LoginPage({super.key});

  @override
  ConsumerState<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends ConsumerState<LoginPage>
    with SingleTickerProviderStateMixin {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isLoading = false;
  bool _isGoogleLoading = false;
  bool _obscurePassword = true;

  late final AnimationController _blobController;

  @override
  void initState() {
    super.initState();
    // Slow, ambient animation to give the "liquid glass" blobs a subtle
    // sense of motion without being distracting.
    _blobController = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 18),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    _blobController.dispose();
    super.dispose();
  }

  void _login() async {
    HapticFeedback.selectionClick();
    setState(() => _isLoading = true);
    // Simulate login delay
    await Future.delayed(const Duration(seconds: 1));
    if (!mounted) return;
    setState(() => _isLoading = false);

    // In real app, call ref.read(authNotifierProvider.notifier).login(...)
    // If successful, update the global auth state:
    // ref.read(authStateProvider.notifier).state = true;
    if (mounted) {
      context.go('/home');
    }
  }

  void _loginWithGoogle() async {
    HapticFeedback.selectionClick();
    setState(() => _isGoogleLoading = true);
    // Simulate Google sign-in delay
    await Future.delayed(const Duration(seconds: 1));
    if (!mounted) return;
    setState(() => _isGoogleLoading = false);

    // In real app, call ref.read(authNotifierProvider.notifier).loginWithGoogle()
    // If successful, update the global auth state:
    // ref.read(authStateProvider.notifier).state = true;
    if (mounted) {
      context.go('/home');
    }
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.sizeOf(context);
    final isWide = size.width >= 720;
    final isShort = size.height < 640;
    final isTiny = size.height < 560;

    // --- REUSABLE FORM FIELDS ---
    final Widget formFields = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Text(
          'Masuk ke Akun Anda',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                color: Colors.black87,
                fontWeight: FontWeight.w700,
              ),
        ),
        SizedBox(height: isShort ? 18 : 28),
        _GlassTextField(
          controller: _emailController,
          label: 'Email',
          icon: Icons.email_outlined,
          keyboardType: TextInputType.emailAddress,
        ),
        const SizedBox(height: 16),
        _GlassTextField(
          controller: _passwordController,
          label: 'Password',
          icon: Icons.lock_outline,
          obscureText: _obscurePassword,
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
        Align(
          alignment: Alignment.centerRight,
          child: TextButton(
            style: TextButton.styleFrom(
              padding: EdgeInsets.zero,
              minimumSize: const Size(0, 36),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            ),
            onPressed: () {},
            child: Text(
              'Lupa Password?',
              style: TextStyle(
                color: AppColors.primary,
                fontWeight: FontWeight.w600,
                fontSize: 13,
              ),
            ),
          ),
        ),
        SizedBox(height: isShort ? 10 : 16),
        _PrimaryGradientButton(
          isLoading: _isLoading,
          onPressed: _isLoading ? null : _login,
          label: 'MASUK',
        ),
        SizedBox(height: isShort ? 16 : 22),
        Row(
          children: [
            const Expanded(
              child: Divider(color: Colors.black12, thickness: 1),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Text(
                'atau lanjutkan dengan',
                style: TextStyle(
                  color: Colors.black45,
                  fontSize: 12,
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
            const Expanded(
              child: Divider(color: Colors.black12, thickness: 1),
            ),
          ],
        ),
        SizedBox(height: isShort ? 16 : 22),
        _GoogleSignInButton(
          isLoading: _isGoogleLoading,
          onPressed: _isGoogleLoading ? null : _loginWithGoogle,
        ),
        SizedBox(height: isShort ? 16 : 22),
        TextButton(
          onPressed: () => context.go('/register'),
          child: RichText(
            text: TextSpan(
              text: 'Belum punya akun? ',
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: Colors.black54),
              children: [
                TextSpan(
                  text: 'Daftar di sini',
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
                  if (!isTiny) ...[
                    _MarketBadge(isWide: true),
                    const SizedBox(height: 32),
                  ],
                  _BrandMark(isShort: isShort),
                  const SizedBox(height: 16),
                  Text(
                    'Bersama Majukan UMKM Lokal',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          color: Colors.black54,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 0.2,
                        ),
                  ),
                  const SizedBox(height: 48),
                  if (!isTiny)
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
          // KANAN: FORM LOGIN
          Expanded(
            child: Center(
              child: SingleChildScrollView(
                padding: EdgeInsets.symmetric(
                  horizontal: 24,
                  vertical: isShort ? 16 : 32,
                ),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(32),
                    child: BackdropFilter(
                      filter: ImageFilter.blur(sigmaX: 28, sigmaY: 28),
                      child: GlassContainer(
                        padding: EdgeInsets.symmetric(
                          horizontal: 40,
                          vertical: isShort ? 22 : 34,
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
            vertical: isShort ? 16 : 32,
          ),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (!isTiny) ...[
                  _MarketBadge(isWide: false),
                  SizedBox(height: isShort ? 14 : 20),
                ],
                ClipRRect(
                  borderRadius: BorderRadius.circular(32),
                  child: BackdropFilter(
                    filter: ImageFilter.blur(sigmaX: 28, sigmaY: 28),
                    child: GlassContainer(
                      padding: EdgeInsets.symmetric(
                        horizontal: 26,
                        vertical: isShort ? 22 : 34,
                      ),
                      width: double.infinity,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          _BrandMark(isShort: isShort),
                          const SizedBox(height: 6),
                          Text(
                            'Bersama Majukan UMKM Lokal',
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
                          formFields, 
                        ],
                      ),
                    ),
                  ),
                ),
                SizedBox(height: isShort ? 10 : 18),
                if (!isTiny)
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
          // --- Layered warm UMKM-inspired background (Material 3 tone) ---
          const DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Color(0xFFFFF8F0), // warm cream
                  Color(0xFFFFE3C2), // soft market orange
                  Color(0xFFFFD3BE), // terracotta blush
                ],
              ),
            ),
          ),

          // Subtle woven-texture dot pattern
          Positioned.fill(
            child: IgnorePointer(
              child: CustomPaint(
                painter: _WovenPatternPainter(
                  color: AppColors.primary.withValues(alpha: 0.05),
                ),
              ),
            ),
          ),

          // Animated "liquid glass" blobs
          AnimatedBuilder(
            animation: _blobController,
            builder: (context, _) {
              final t = _blobController.value;
              return Stack(
                children: [
                  Positioned(
                    top: -size.width * 0.28 + (t * 24),
                    left: -size.width * 0.22 - (t * 16),
                    child: _LiquidBlob(
                      diameter: size.width * 0.78,
                      colors: [
                        AppColors.primary.withValues(alpha: 0.32),
                        AppColors.primary.withValues(alpha: 0.04),
                      ],
                    ),
                  ),
                  Positioned(
                    bottom: -size.width * 0.32 - (t * 20),
                    right: -size.width * 0.26 + (t * 18),
                    child: _LiquidBlob(
                      diameter: size.width * 0.9,
                      colors: [
                        const Color(0xFFFF7043).withValues(alpha: 0.28),
                        const Color(0xFFFF7043).withValues(alpha: 0.02),
                      ],
                    ),
                  ),
                  Positioned(
                    top: size.height * 0.32 + (t * 14),
                    right: -size.width * 0.18,
                    child: _LiquidBlob(
                      diameter: size.width * 0.4,
                      colors: [
                        const Color(0xFFFFC107).withValues(alpha: 0.22),
                        const Color(0xFFFFC107).withValues(alpha: 0.0),
                      ],
                    ),
                  ),
                ],
              );
            },
          ),

          // --- Login content (Responsive View) ---
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

/// Small pill badge above the card
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
            color: Colors.white.withValues(alpha: 0.4),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(color: Colors.white.withValues(alpha: 0.6)),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.04),
                blurRadius: 8,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.storefront_rounded,
                  size: 16, color: Color(0xFFBF360C)),
              const SizedBox(width: 6),
              Text(
                'Platform Digital UMKM',
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

/// Soft blurred gradient blob
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

/// Faint dotted texture
class _WovenPatternPainter extends CustomPainter {
  final Color color;
  const _WovenPatternPainter({required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = color;
    const spacing = 28.0;
    const dotRadius = 1.6;
    for (double y = 0; y < size.height; y += spacing) {
      for (double x = 0; x < size.width; x += spacing) {
        final offsetX = (y ~/ spacing) % 2 == 0 ? 0.0 : spacing / 2;
        canvas.drawCircle(Offset(x + offsetX, y), dotRadius, paint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant _WovenPatternPainter oldDelegate) =>
      oldDelegate.color != color;
}

/// Glassy, material-inspired text field wrapper
class _GlassTextField extends StatelessWidget {
  final TextEditingController controller;
  final String label;
  final IconData icon;
  final TextInputType? keyboardType;
  final bool obscureText;
  final Widget? suffixIcon;

  const _GlassTextField({
    required this.controller,
    required this.label,
    required this.icon,
    this.keyboardType,
    this.obscureText = false,
    this.suffixIcon,
  });

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(18),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
        child: Container(
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.5),
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: Colors.white.withValues(alpha: 0.65)),
          ),
          child: TextField(
            controller: controller,
            keyboardType: keyboardType,
            obscureText: obscureText,
            style: const TextStyle(color: Colors.black87),
            decoration: InputDecoration(
              labelText: label,
              labelStyle: const TextStyle(color: Colors.black54),
              prefixIcon: Icon(icon, color: AppColors.primary),
              suffixIcon: suffixIcon,
              filled: false,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(18),
                borderSide: BorderSide.none,
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(18),
                borderSide: BorderSide.none,
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(18),
                borderSide: BorderSide(
                  color: AppColors.primary.withValues(alpha: 0.6),
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

/// Primary call-to-action button
class _PrimaryGradientButton extends StatelessWidget {
  final bool isLoading;
  final VoidCallback? onPressed;
  final String label;

  const _PrimaryGradientButton({
    required this.isLoading,
    required this.onPressed,
    required this.label,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 54,
      child: DecoratedBox(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(18),
          gradient: const LinearGradient(
            colors: [
              AppColors.primary,
              Color(0xFFFF7043),
            ],
          ),
          boxShadow: [
            BoxShadow(
              color: AppColors.primary.withValues(alpha: 0.35),
              blurRadius: 18,
              offset: const Offset(0, 8),
            ),
          ],
        ),
        child: FilledButton(
          style: FilledButton.styleFrom(
            backgroundColor: Colors.transparent,
            shadowColor: Colors.transparent,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(18),
            ),
          ),
          onPressed: onPressed,
          child: isLoading
              ? const SizedBox(
                  height: 20,
                  width: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : Text(
                  label,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 1.0,
                  ),
                ),
        ),
      ),
    );
  }
}

/// Frosted-glass "Sign in with Google" button
class _GoogleSignInButton extends StatelessWidget {
  final bool isLoading;
  final VoidCallback? onPressed;

  const _GoogleSignInButton({
    required this.isLoading,
    required this.onPressed,
  });

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(18),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
        child: SizedBox(
          width: double.infinity,
          height: 54,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.55),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: Colors.white.withValues(alpha: 0.7)),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.05),
                  blurRadius: 12,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: OutlinedButton(
              style: OutlinedButton.styleFrom(
                backgroundColor: Colors.transparent,
                side: BorderSide.none,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(18),
                ),
              ),
              onPressed: onPressed,
              child: isLoading
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: AppColors.primary,
                      ),
                    )
                  : Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const _GoogleLogo(size: 20),
                        const SizedBox(width: 10),
                        Text(
                          'Masuk dengan Google',
                          style: TextStyle(
                            color: Colors.black.withValues(alpha: 0.75),
                            fontWeight: FontWeight.w600,
                            fontSize: 15,
                          ),
                        ),
                      ],
                    ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Vector presisi logo Google (Shadcn style dari svgl.app)
class _GoogleLogo extends StatelessWidget {
  final double size;
  const _GoogleLogo({required this.size});

  @override
  Widget build(BuildContext context) {
    const String svgString = '''
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
  <path d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z" fill="currentColor"/>
</svg>
''';

    return SizedBox(
      width: size,
      height: size,
      child: SvgPicture.string(
        svgString,
        colorFilter: ColorFilter.mode(
          Colors.black.withValues(alpha: 0.75),
          BlendMode.srcIn,
        ),
      ),
    );
  }
}