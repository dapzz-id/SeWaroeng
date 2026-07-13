// Neura Cakrawira Solusi | SeWaroeng
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/network/api_client.dart';
import '../../data/models/product_model.dart';

final productsProvider = FutureProvider<List<ProductModel>>((ref) async {
  final apiClient = ref.watch(apiClientProvider);
  final response = await apiClient.get('/products');
  
  if (response.statusCode == 200) {
    final List<dynamic> data = response.data['data'] ?? [];
    return data.map((e) => ProductModel.fromJson(e)).toList();
  } else {
    throw Exception('Failed to load products');
  }
});
