// Neura Cakrawira Solusi | SeWaroeng
class StoreModel {
  final String id;
  final String name;
  final String type;
  final double lat;
  final double lng;
  final double crowd;

  StoreModel({
    required this.id,
    required this.name,
    required this.type,
    required this.lat,
    required this.lng,
    required this.crowd,
  });

  factory StoreModel.fromJson(Map<String, dynamic> json) {
    return StoreModel(
      id: json['id'] ?? '',
      name: json['name'] ?? '',
      type: json['type'] ?? 'Warung',
      lat: (json['lat'] as num?)?.toDouble() ?? -6.200000,
      lng: (json['lng'] as num?)?.toDouble() ?? 106.816666,
      crowd: (json['crowd'] as num?)?.toDouble() ?? 0.0,
    );
  }
}
