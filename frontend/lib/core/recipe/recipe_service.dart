import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'recipe_models.dart';

class RecipeService extends ChangeNotifier {
  static final _uuid = const Uuid();

  final ApiClient apiClient;

  List<RecipeModel> _recipes = [];
  bool _isLoading = false;
  String? _errorMessage;

  List<RecipeModel> get recipes => _recipes;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  RecipeService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch recipes list
  Future<void> fetchRecipes({String? search, String? status}) async {
    _setLoading(true);
    _setError(null);

    try {
      final queryParams = <String, String>{};
      if (search != null && search.isNotEmpty) queryParams['search'] = search;
      if (status != null && status.isNotEmpty) queryParams['status'] = status;

      final uri = Uri(
        path: '/api/v1/recipes',
        queryParameters: queryParams.isEmpty ? null : queryParams,
      );

      final response = await apiClient.get<List<dynamic>>(uri.toString());

      if (response.isSuccess && response.data != null) {
        _recipes = response.data!
            .map((e) => RecipeModel.fromJson(e as Map<String, dynamic>))
            .toList();
      } else {
        _setError(response.message ?? 'Gagal memuat resep.');
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  /// Create new recipe
  Future<bool> createRecipe({
    required String name,
    required String code,
    String? productVariantId,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final body = <String, dynamic>{
        'name': name.trim(),
        'code': code.trim(),
      };
      if (productVariantId != null) {
        body['product_variant_id'] = productVariantId;
      }

      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/recipes',
        idempotencyKey: key,
        body: body,
      );

      if (response.isSuccess) {
        await fetchRecipes();
        return true;
      } else {
        _setError(response.message ?? 'Gagal membuat resep.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Calculate recipe cost / HPP
  Future<Map<String, dynamic>?> calculateRecipeCost(String recipeId) async {
    try {
      final response = await apiClient.get<Map<String, dynamic>>(
        '/api/v1/recipes/$recipeId/cost',
      );
      if (response.isSuccess) {
        return response.data;
      }
      return null;
    } catch (_) {
      return null;
    }
  }
}
