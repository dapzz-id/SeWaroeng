// Neura Cakrawira Solusi | SeWaroeng
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/network/api_client.dart';
import '../../data/models/order_model.dart';

final ordersProvider = FutureProvider<List<OrderModel>>((ref) async {
  final apiClient = ref.watch(apiClientProvider);
  final response = await apiClient.get('/orders');
  
  if (response.statusCode == 200) {
    final List<dynamic> data = response.data['data'] ?? [];
    return data.map((e) => OrderModel.fromJson(e)).toList();
  } else {
    throw Exception('Failed to load orders');
  }
});
