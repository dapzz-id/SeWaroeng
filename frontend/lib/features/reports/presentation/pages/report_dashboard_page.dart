// Neura Cakrawira Solusi | SeWaroeng
// Seller Report Dashboard with fl_chart & AI Insights

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:fl_chart/fl_chart.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';
import '../../../../shared/widgets/glass_skeleton_loader.dart';

import '../../../../core/network/api_client.dart';

final aiInsightsProvider = FutureProvider<String>((ref) async {
  final apiClient = ref.watch(apiClientProvider);
  final response = await apiClient.get('/reports/ai-insights');
  if (response.statusCode == 200) {
    return response.data['data']['insights'] as String;
  }
  throw Exception('Failed to load AI Insights');
});

class ReportDashboardPage extends ConsumerWidget {
  const ReportDashboardPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final aiInsightsAsync = ref.watch(aiInsightsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Laporan & AI Insights'),
        backgroundColor: AppColors.surface,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // KPI Cards
            Row(
              children: [
                Expanded(
                  child: GlassContainer(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Total Pendapatan', style: TextStyle(color: AppColors.grey400)),
                        const SizedBox(height: 8),
                        Text('Rp 15.450.000', style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold, color: AppColors.primary)),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: GlassContainer(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Laba Bersih', style: TextStyle(color: AppColors.grey400)),
                        const SizedBox(height: 8),
                        Text('Rp 4.200.000', style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold, color: Colors.greenAccent)),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 24),
            
            // Chart
            const Text('Tren Penjualan 7 Hari Terakhir', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 16),
            GlassContainer(
              height: 250,
              padding: const EdgeInsets.all(16),
              child: LineChart(
                LineChartData(
                  gridData: const FlGridData(show: false),
                  titlesData: const FlTitlesData(
                    rightTitles: AxisTitles(sideTitles: SideTitles(showTitles: false)),
                    topTitles: AxisTitles(sideTitles: SideTitles(showTitles: false)),
                  ),
                  borderData: FlBorderData(show: false),
                  lineBarsData: [
                    LineChartBarData(
                      spots: const [
                        FlSpot(0, 3),
                        FlSpot(1, 1),
                        FlSpot(2, 4),
                        FlSpot(3, 2),
                        FlSpot(4, 5),
                        FlSpot(5, 3),
                        FlSpot(6, 6),
                      ],
                      isCurved: true,
                      color: AppColors.primary,
                      barWidth: 4,
                      isStrokeCapRound: true,
                      dotData: const FlDotData(show: false),
                      belowBarData: BarAreaData(
                        show: true,
                        color: AppColors.primary.withValues(alpha: 0.2),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),

            // AI Insights
            const Row(
              children: [
                Icon(Icons.auto_awesome, color: Colors.purpleAccent),
                SizedBox(width: 8),
                Text('Saran AI Gemini', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              ],
            ),
            const SizedBox(height: 16),
            aiInsightsAsync.when(
              data: (insights) => GlassContainer(
                padding: const EdgeInsets.all(16),
                child: Text(
                  insights,
                  style: const TextStyle(fontSize: 15, height: 1.5),
                ),
              ),
              loading: () => const GlassSkeletonLoader(height: 120),
              error: (e, st) => GlassContainer(
                padding: const EdgeInsets.all(16),
                child: Text('Gagal memuat AI Insights: $e', style: const TextStyle(color: Colors.red)),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
