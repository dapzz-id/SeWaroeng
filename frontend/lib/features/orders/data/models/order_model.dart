// Neura Cakrawira Solusi | SeWaroeng
class OrderModel {
  final String id;
  final String status;
  final double total;
  final String date;
  final int itemCount;

  OrderModel({
    required this.id,
    required this.status,
    required this.total,
    required this.date,
    required this.itemCount,
  });

  factory OrderModel.fromJson(Map<String, dynamic> json) {
    return OrderModel(
      id: json['id'] ?? '',
      status: json['status'] ?? 'pending',
      total: (json['total_amount'] as num?)?.toDouble() ?? 0.0,
      date: json['created_at'] ?? '',
      itemCount: json['items']?.length ?? 1,
    );
  }
}
