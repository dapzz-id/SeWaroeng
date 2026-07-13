// Neura Cakrawira Solusi | SeWaroeng
class ProductModel {
  final String id;
  final String name;
  final double price;
  final String? imageUrl;
  final int stock;

  ProductModel({
    required this.id,
    required this.name,
    required this.price,
    this.imageUrl,
    required this.stock,
  });

  factory ProductModel.fromJson(Map<String, dynamic> json) {
    return ProductModel(
      id: json['id'] ?? '',
      name: json['name'] ?? '',
      price: (json['price'] as num?)?.toDouble() ?? 0.0,
      imageUrl: json['image_url'],
      stock: json['stock'] ?? 0,
    );
  }
}
