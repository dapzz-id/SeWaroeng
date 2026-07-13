// Neura Cakrawira Solusi | SeWaroeng
// AppColors — SeWaroeng brand color palette.
// Warm orange-amber-cream with dark glass backgrounds.
import 'package:flutter/material.dart';

/// SeWaroeng brand color system.
abstract final class AppColors {
  // ── Primary: Warm Orange ───────────────────────────────────────────────────
  static const primary        = Color(0xFFFF6B35);  // Vibrant orange
  static const primaryLight   = Color(0xFFFF8C5A);
  static const primaryDark    = Color(0xFFD94E1F);
  static const onPrimary      = Color(0xFFFFFFFF);

  // ── Secondary: Golden Amber ────────────────────────────────────────────────
  static const secondary      = Color(0xFFF5A623);  // Warm amber
  static const secondaryLight = Color(0xFFFFC04D);
  static const secondaryDark  = Color(0xFFCC8800);
  static const onSecondary    = Color(0xFF1A1A1A);

  // ── Tertiary: Cream / Sand ─────────────────────────────────────────────────
  static const tertiary       = Color(0xFFF5E6D3);  // Warm cream
  static const onTertiary     = Color(0xFF3D2B1A);

  // ── Background ─────────────────────────────────────────────────────────────
  static const background     = Color(0xFF0F0A06);  // Deep warm black
  static const surface        = Color(0xFF1A1208);  // Dark warm surface
  static const surfaceVariant = Color(0xFF2A1E12);  // Card/elevated surface
  static const onBackground   = Color(0xFFF5E6D3);  // Warm white text
  static const onSurface      = Color(0xFFEDD9C5);

  // ── Glass ──────────────────────────────────────────────────────────────────
  static const glassWhite     = Color(0x1AFFFFFF);  // 10% white
  static const glassBorder    = Color(0x33FFFFFF);  // 20% white border
  static const glassOrange    = Color(0x1AFF6B35);  // 10% orange glass
  static const glassDark      = Color(0x99000000);  // 60% black glass

  // ── Status ─────────────────────────────────────────────────────────────────
  static const success        = Color(0xFF4CAF50);
  static const successLight   = Color(0xFFE8F5E9);
  static const warning        = Color(0xFFF5A623);
  static const warningLight   = Color(0xFFFFF8E1);
  static const error          = Color(0xFFEF5350);
  static const errorLight     = Color(0xFFFFEBEE);
  static const info           = Color(0xFF29B6F6);

  // ── Neutrals ───────────────────────────────────────────────────────────────
  static const grey50         = Color(0xFFFAF5F0);
  static const grey100        = Color(0xFFF0E8DE);
  static const grey200        = Color(0xFFD9CEC3);
  static const grey400        = Color(0xFFB0A090);
  static const grey600        = Color(0xFF7A6A5A);
  static const grey800        = Color(0xFF3D2B1A);
  static const grey900        = Color(0xFF1A0F06);

  // ── Gradient presets ───────────────────────────────────────────────────────
  static const LinearGradient primaryGradient = LinearGradient(
    colors: [primary, secondaryDark],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient backgroundGradient = LinearGradient(
    colors: [Color(0xFF0F0A06), Color(0xFF1E1208), Color(0xFF0A0806)],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient glassGradient = LinearGradient(
    colors: [Color(0x26FFFFFF), Color(0x0DFFFFFF)],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );
}
