// Neura Cakrawira Solusi | SeWaroeng
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/network/api_client.dart';
import '../../data/models/store_model.dart';

final storesProvider = FutureProvider<List<StoreModel>>((ref) async {
  final apiClient = ref.watch(apiClientProvider);
  final response = await apiClient.get('/public/stores');
  
  if (response.statusCode == 200) {
    final List<dynamic> data = response.data['data'] ?? [];
    return data.map((e) => StoreModel.fromJson(e)).toList();
  } else {
    throw Exception('Failed to load stores');
  }
});
