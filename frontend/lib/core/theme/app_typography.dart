// Neura Cakrawira Solusi | SeWaroeng
// AppTypography — text theme using Google Fonts (Poppins primary, Inter secondary).
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'app_colors.dart';

/// SeWaroeng typography system.
abstract final class AppTypography {
  static TextTheme get textTheme => TextTheme(
    // ── Display ─────────────────────────────────────────────────────────────
    displayLarge: _poppins(57, FontWeight.w700, -0.25, AppColors.onBackground),
    displayMedium: _poppins(45, FontWeight.w700, 0,    AppColors.onBackground),
    displaySmall: _poppins(36, FontWeight.w600, 0.25,  AppColors.onBackground),

    // ── Headline ─────────────────────────────────────────────────────────────
    headlineLarge:  _poppins(32, FontWeight.w700, 0, AppColors.onBackground),
    headlineMedium: _poppins(28, FontWeight.w600, 0, AppColors.onBackground),
    headlineSmall:  _poppins(24, FontWeight.w600, 0, AppColors.onBackground),

    // ── Title ─────────────────────────────────────────────────────────────────
    titleLarge:  _poppins(22, FontWeight.w600,  0, AppColors.onBackground),
    titleMedium: _poppins(16, FontWeight.w600,  0.15, AppColors.onBackground),
    titleSmall:  _poppins(14, FontWeight.w500,  0.1,  AppColors.onBackground),

    // ── Body (Inter for readability) ──────────────────────────────────────────
    bodyLarge:  _inter(16, FontWeight.w400, 0.5, AppColors.onSurface),
    bodyMedium: _inter(14, FontWeight.w400, 0.25, AppColors.onSurface),
    bodySmall:  _inter(12, FontWeight.w400, 0.4,  AppColors.grey400),

    // ── Label ─────────────────────────────────────────────────────────────────
    labelLarge:  _inter(14, FontWeight.w600, 0.1, AppColors.onSurface),
    labelMedium: _inter(12, FontWeight.w500, 0.5, AppColors.onSurface),
    labelSmall:  _inter(11, FontWeight.w500, 0.5, AppColors.grey400),
  );

  static TextStyle _poppins(double size, FontWeight weight, double spacing, Color color) =>
      GoogleFonts.poppins(fontSize: size, fontWeight: weight, letterSpacing: spacing, color: color);

  static TextStyle _inter(double size, FontWeight weight, double spacing, Color color) =>
      GoogleFonts.inter(fontSize: size, fontWeight: weight, letterSpacing: spacing, color: color);
}
