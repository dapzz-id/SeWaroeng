// Neura Cakrawira Solusi | SeWaroeng
// GlassContainer — Reusable Liquid Glass UI component.
import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/theme/app_colors.dart';
import '../../core/services/device_capability_service.dart';

/// A container with glassmorphism effects (blur, semi-transparent background, subtle border).
/// Uses RepaintBoundary to cache the rendering on capable devices, or a fallback flat color
/// on low-end devices for performance.
class GlassContainer extends ConsumerWidget {
  final Widget child;
  final double blur;
  final double opacity;
  final BorderRadiusGeometry borderRadius;
  final EdgeInsetsGeometry? padding;
  final EdgeInsetsGeometry? margin;
  final double? width;
  final double? height;
  final Color color;
  final Border? border;
  final BoxShape shape;
  
  const GlassContainer({
    super.key,
    required this.child,
    this.blur = 10.0,
    this.opacity = 0.1,
    this.borderRadius = const BorderRadius.all(Radius.circular(16)),
    this.padding,
    this.margin,
    this.width,
    this.height,
    this.color = Colors.white,
    this.border,
    this.shape = BoxShape.rectangle,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final capabilities = ref.watch(deviceCapabilityProvider);
    final useGlass = capabilities.supportsGlassEffects;

    final baseBorder = border ?? Border.all(
      color: AppColors.glassBorder,
      width: 1.0,
    );

    if (!useGlass) {
      // Fallback for low-end devices
      return Container(
        width: width,
        height: height,
        margin: margin,
        padding: padding,
        decoration: BoxDecoration(
          color: AppColors.surfaceVariant, // Solid opaque fallback
          borderRadius: shape == BoxShape.circle ? null : borderRadius,
          border: baseBorder,
          shape: shape,
        ),
        child: child,
      );
    }

    // Glass effect for high-end devices
    return RepaintBoundary(
      child: Container(
        width: width,
        height: height,
        margin: margin,
        decoration: BoxDecoration(
          borderRadius: shape == BoxShape.circle ? null : borderRadius,
          shape: shape,
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.1),
              blurRadius: 10,
              spreadRadius: 0,
            ),
          ],
        ),
        child: ClipRRect(
          borderRadius: shape == BoxShape.circle 
              ? BorderRadius.circular(1000) // large enough for circle
              : borderRadius as BorderRadius,
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: blur, sigmaY: blur),
            child: Container(
              padding: padding,
              decoration: BoxDecoration(
                color: color.withOpacity(opacity),
                border: baseBorder,
                shape: shape,
              ),
              child: child,
            ),
          ),
        ),
      ),
    );
  }
}
