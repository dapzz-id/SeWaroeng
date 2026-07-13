// Neura Cakrawira Solusi | SeWaroeng
// Store Discovery Map (Buyer UI)

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/crowd_level_indicator.dart';
import '../providers/stores_provider.dart';

class StoreMapPage extends ConsumerWidget {
  const StoreMapPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final storesAsync = ref.watch(storesProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Cari Warung & UMKM'),
        backgroundColor: AppColors.surface,
      ),
      body: Stack(
        children: [
          FlutterMap(
            options: const MapOptions(
              initialCenter: LatLng(-6.200000, 106.816666),
              initialZoom: 14.0,
            ),
            children: [
              TileLayer(
                urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                userAgentPackageName: 'com.neuracakrawirasolusi.sewaroeng',
              ),
              storesAsync.when(
                data: (stores) => MarkerLayer(
                  markers: stores.map((store) {
                    return Marker(
                      point: LatLng(store.lat, store.lng),
                      width: 52,
                      height: 64,
                      child: GestureDetector(
                        onTap: () {
                          showModalBottomSheet(
                            context: context,
                            backgroundColor: Colors.transparent,
                            builder: (ctx) => _StoreDetailSheet(
                              name: store.name,
                              type: store.type,
                              crowdPercent: store.crowd,
                            ),
                          );
                        },
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.store, color: Colors.redAccent, size: 36),
                            // Mini crowd level badge on marker
                            CrowdLevelIndicator(
                              percent: store.crowd,
                              compact: true,
                            ),
                          ],
                        ),
                      ),
                    );
                  }).toList(),
                ),
                loading: () => const MarkerLayer(markers: []),
                error: (_, __) => const MarkerLayer(markers: []),
              ),
            ],
          ),

          // Floating Search Bar
          Positioned(
            top: 16,
            left: 16,
            right: 16,
            child: GlassContainer(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              borderRadius: BorderRadius.circular(30),
              child: const Row(
                children: [
                  Icon(Icons.search, color: Colors.grey),
                  SizedBox(width: 8),
                  Expanded(
                    child: TextField(
                      decoration: InputDecoration(
                        hintText: 'Cari nama warung, lokasi...',
                        border: InputBorder.none,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StoreDetailSheet extends StatelessWidget {
  final String name;
  final String type;
  final double crowdPercent;

  const _StoreDetailSheet(
      {required this.name, required this.type, required this.crowdPercent});

  @override
  Widget build(BuildContext context) {
    return GlassContainer(
      padding: const EdgeInsets.all(24),
      borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(name,
              style: Theme.of(context)
                  .textTheme
                  .headlineSmall
                  ?.copyWith(fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          Text('Tipe: $type', style: const TextStyle(color: Colors.grey)),
          const SizedBox(height: 12),
          // Crowd Level Indicator
          CrowdLevelIndicator(percent: crowdPercent),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: () {},
                  icon: const Icon(Icons.chat),
                  label: const Text('Chat'),
                ),
              ),
              if (type == 'Coffee Shop' || type == 'Restaurant') ...[
                const SizedBox(width: 16),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {},
                    icon: const Icon(Icons.book_online),
                    label: const Text('Booking'),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}
