// Neura Cakrawira Solusi | SeWaroeng
// CrowdLevelIndicator — visual widget for store busyness (Green/Yellow/Red)

import 'package:flutter/material.dart';

enum CrowdLevel { low, medium, high }

extension CrowdLevelExt on CrowdLevel {
  Color get color => switch (this) {
        CrowdLevel.low => Colors.greenAccent,
        CrowdLevel.medium => Colors.orangeAccent,
        CrowdLevel.high => Colors.redAccent,
      };

  String get label => switch (this) {
        CrowdLevel.low => 'Sepi',
        CrowdLevel.medium => 'Ramai',
        CrowdLevel.high => 'Sangat Ramai',
      };

  IconData get icon => switch (this) {
        CrowdLevel.low => Icons.sentiment_satisfied_alt,
        CrowdLevel.medium => Icons.sentiment_neutral,
        CrowdLevel.high => Icons.sentiment_very_dissatisfied,
      };
}

CrowdLevel crowdLevelFromPercent(double percent) {
  if (percent < 40) return CrowdLevel.low;
  if (percent < 75) return CrowdLevel.medium;
  return CrowdLevel.high;
}

class CrowdLevelIndicator extends StatelessWidget {
  final double? percent; // 0-100, null = loading
  final bool compact; // Show compact badge vs full card

  const CrowdLevelIndicator({super.key, this.percent, this.compact = false});

  @override
  Widget build(BuildContext context) {
    if (percent == null) {
      return _buildLoading();
    }
    final level = crowdLevelFromPercent(percent!);
    return compact ? _buildBadge(level) : _buildCard(context, level);
  }

  Widget _buildLoading() {
    if (compact) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(
          color: Colors.grey.withValues(alpha: 0.2),
          borderRadius: BorderRadius.circular(20),
        ),
        child: const SizedBox(width: 40, height: 12, child: LinearProgressIndicator()),
      );
    }
    return const LinearProgressIndicator();
  }

  Widget _buildBadge(CrowdLevel level) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: level.color.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: level.color.withValues(alpha: 0.3)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(level.icon, color: level.color, size: 14),
          const SizedBox(width: 4),
          Text(level.label, style: TextStyle(color: level.color, fontSize: 12, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  Widget _buildCard(BuildContext context, CrowdLevel level) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: level.color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: level.color.withValues(alpha: 0.3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(level.icon, color: level.color),
              const SizedBox(width: 8),
              Text('Keramaian Saat Ini', style: TextStyle(color: level.color, fontWeight: FontWeight.bold)),
              const Spacer(),
              Text(
                '${percent!.toStringAsFixed(0)}%',
                style: TextStyle(color: level.color, fontWeight: FontWeight.bold, fontSize: 18),
              ),
            ],
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: LinearProgressIndicator(
              value: (percent! / 100).clamp(0.0, 1.0),
              backgroundColor: level.color.withValues(alpha: 0.15),
              color: level.color,
              minHeight: 8,
            ),
          ),
          const SizedBox(height: 6),
          Text(level.label, style: TextStyle(color: level.color, fontSize: 13)),
        ],
      ),
    );
  }
}
