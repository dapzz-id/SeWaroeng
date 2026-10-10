import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'category_model.dart';
import 'modifier_group_model.dart';
import 'product_model.dart';

class CatalogService extends ChangeNotifier {
  static final _uuid = const Uuid();

  final ApiClient apiClient;

  List<ProductModel> _products = [];
  List<CategoryModel> _categories = [];
  List<ModifierGroupModel> _modifierGroups = [];

  bool _isLoading = false;
  String? _errorMessage;
  int _totalProducts = 0;
  int _currentPage = 1;
  int _lastPage = 1;

  String? _selectedCategoryId;
  String? _selectedOutletId;
  String? _searchQuery;

  List<ProductModel> get products => _products;
  List<CategoryModel> get categories => _categories;
  List<ModifierGroupModel> get modifierGroups => _modifierGroups;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;
  int get totalProducts => _totalProducts;
  int get currentPage => _currentPage;
  int get lastPage => _lastPage;
  String? get selectedCategoryId => _selectedCategoryId;
  String? get selectedOutletId => _selectedOutletId;
  String? get searchQuery => _searchQuery;

  CatalogService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  void setCategoryFilter(String? categoryId) {
    _selectedCategoryId = categoryId;
    fetchProducts(
      categoryId: categoryId,
      outletId: _selectedOutletId,
      search: _searchQuery,
      page: 1,
    );
  }

  void setOutletContext(String? outletId) {
    _selectedOutletId = outletId;
    fetchProducts(
      categoryId: _selectedCategoryId,
      outletId: outletId,
      search: _searchQuery,
      page: 1,
    );
  }

  void setSearchQuery(String? query) {
    _searchQuery = query;
    fetchProducts(
      categoryId: _selectedCategoryId,
      outletId: _selectedOutletId,
      search: query,
      page: 1,
    );
  }

  // =========================================================================
  // CATEGORIES
  // =========================================================================

  Future<List<CategoryModel>> fetchCategories({
    String? status = 'active',
  }) async {
    try {
      final queryParams = <String, String>{};
      if (status != null) {
        queryParams['status'] = status;
      }

      final uri = queryParams.isNotEmpty
          ? '/api/v1/categories?${Uri(queryParameters: queryParams).query}'
          : '/api/v1/categories';

      final response = await apiClient.get<dynamic>(uri);

      if (response.isSuccess && response.data != null) {
        final rawList = response.data is List
            ? response.data as List<dynamic>
            : (response.data is Map
                  ? (response.data['items'] ?? response.data['data'] ?? [])
                        as List<dynamic>
                  : <dynamic>[]);
        _categories = rawList
            .map((c) => CategoryModel.fromJson(c as Map<String, dynamic>))
            .toList();
        notifyListeners();
        return _categories;
      }
    } catch (e) {
      _setError('Gagal memuat kategori: $e');
    }
    return _categories;
  }

  Future<CategoryModel?> createCategory({
    required String name,
    String? slug,
    String? parentId,
    int? sortOrder,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/categories',
        idempotencyKey: key,
        body: {
          'name': name.trim(),
          if (slug != null && slug.isNotEmpty) 'slug': slug.trim(),
          'parent_id': ?parentId,
          'sort_order': ?sortOrder,
        },
      );

      if (response.isSuccess && response.data != null) {
        final rawData = response.data!['data'] is Map<String, dynamic>
            ? response.data!['data'] as Map<String, dynamic>
            : response.data!;
        final created = CategoryModel.fromJson(rawData);
        _categories.add(created);
        _setLoading(false);
        return created;
      } else {
        _setError(response.message ?? 'Gagal membuat kategori.');
      }
    } catch (e) {
      _setError('Error saat membuat kategori: $e');
    } finally {
      _setLoading(false);
    }
    return null;
  }

  Future<bool> updateCategory(
    String id, {
    String? name,
    String? slug,
    String? parentId,
    String? status,
    int? sortOrder,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.patch<Map<String, dynamic>>(
        '/api/v1/categories/$id',
        idempotencyKey: key,
        body: {
          if (name != null) 'name': name.trim(),
          if (slug != null) 'slug': slug.trim(),
          'parent_id': parentId,
          'status': ?status,
          'sort_order': ?sortOrder,
        },
      );

      if (response.isSuccess) {
        await fetchCategories();
        _setLoading(false);
        return true;
      } else {
        _setError(response.message ?? 'Gagal memperbarui kategori.');
      }
    } catch (e) {
      _setError('Error saat memperbarui kategori: $e');
    } finally {
      _setLoading(false);
    }
    return false;
  }

  // =========================================================================
  // PRODUCTS
  // =========================================================================

  Future<List<ProductModel>> fetchProducts({
    String? search,
    String? categoryId,
    String? outletId,
    String? status,
    int page = 1,
    int perPage = 20,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String, String>{
        'page': page.toString(),
        'per_page': perPage.toString(),
      };
      if (search != null && search.isNotEmpty) {
        params['search'] = search;
      }
      if (categoryId != null && categoryId.isNotEmpty) {
        params['category_id'] = categoryId;
      }
      if (outletId != null && outletId.isNotEmpty) {
        params['outlet_id'] = outletId;
      }
      if (status != null && status.isNotEmpty) {
        params['status'] = status;
      }

      final uri = '/api/v1/products?${Uri(queryParameters: params).query}';
      final response = await apiClient.get<dynamic>(uri);

      if (response.isSuccess && response.data != null) {
        final Map<String, dynamic> data =
            response.data is Map && response.data['data'] != null
            ? response.data['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final items = data['items'] as List<dynamic>? ?? [];
        final pagination = data['pagination'] as Map<String, dynamic>? ?? {};

        _products = items
            .map((item) => ProductModel.fromJson(item as Map<String, dynamic>))
            .toList();
        _totalProducts =
            (pagination['total'] as num?)?.toInt() ?? _products.length;
        _currentPage = (pagination['current_page'] as num?)?.toInt() ?? page;
        _lastPage = (pagination['last_page'] as num?)?.toInt() ?? 1;

        _setLoading(false);
        return _products;
      } else {
        _setError(response.message ?? 'Gagal memuat produk.');
      }
    } catch (e) {
      _setError('Error saat memuat produk: $e');
    } finally {
      _setLoading(false);
    }
    return _products;
  }

  Future<ProductModel?> createProduct({
    required String name,
    String? slug,
    String? categoryId,
    String? description,
    String productType = 'product',
    int? price,
    String? sku,
    String? barcode,
    List<Map<String, dynamic>>? variants,
    List<String>? modifierGroupIds,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final body = <String, dynamic>{
        'name': name.trim(),
        if (slug != null && slug.isNotEmpty) 'slug': slug.trim(),
        'category_id': ?categoryId,
        'description': ?description?.trim(),
        'product_type': productType,
        'price': ?price,
        'sku': ?sku?.trim(),
        'barcode': ?barcode?.trim(),
        if (variants != null && variants.isNotEmpty) 'variants': variants,
        'modifier_group_ids': ?modifierGroupIds,
      };

      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/products',
        idempotencyKey: key,
        body: body,
      );

      if (response.isSuccess && response.data != null) {
        final rawData = response.data!['data'] is Map<String, dynamic>
            ? response.data!['data'] as Map<String, dynamic>
            : response.data!;
        final created = ProductModel.fromJson(rawData);
        _products.insert(0, created);
        _totalProducts++;
        _setLoading(false);
        return created;
      } else {
        _setError(response.message ?? 'Gagal membuat produk.');
      }
    } catch (e) {
      _setError('Error saat membuat produk: $e');
    } finally {
      _setLoading(false);
    }
    return null;
  }

  Future<bool> updateProduct(
    String id, {
    String? name,
    String? slug,
    String? categoryId,
    String? description,
    String? status,
    List<String>? modifierGroupIds,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final body = <String, dynamic>{
        if (name != null) 'name': name.trim(),
        if (slug != null) 'slug': slug.trim(),
        'category_id': categoryId,
        'description': ?description?.trim(),
        'status': ?status,
        'modifier_group_ids': ?modifierGroupIds,
      };

      final response = await apiClient.patch<Map<String, dynamic>>(
        '/api/v1/products/$id',
        idempotencyKey: key,
        body: body,
      );

      if (response.isSuccess) {
        await fetchProducts(
          categoryId: _selectedCategoryId,
          outletId: _selectedOutletId,
          search: _searchQuery,
          page: _currentPage,
        );
        _setLoading(false);
        return true;
      } else {
        _setError(response.message ?? 'Gagal memperbarui produk.');
      }
    } catch (e) {
      _setError('Error saat memperbarui produk: $e');
    } finally {
      _setLoading(false);
    }
    return false;
  }

  Future<bool> setProductOutletAvailability({
    required String productId,
    required String outletId,
    required bool isAvailable,
    String? idempotencyKey,
  }) async {
    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/products/$productId/availability',
        idempotencyKey: key,
        body: {'outlet_id': outletId, 'is_available': isAvailable},
      );

      if (response.isSuccess) {
        await fetchProducts(
          categoryId: _selectedCategoryId,
          outletId: _selectedOutletId,
          search: _searchQuery,
          page: _currentPage,
        );
        return true;
      }
    } catch (e) {
      _setError('Gagal mengubah ketersediaan outlet: $e');
    }
    return false;
  }

  // =========================================================================
  // PRICES
  // =========================================================================

  Future<bool> createPrice({
    required String variantId,
    String? outletId,
    required int amount,
    String currency = 'IDR',
    DateTime? effectiveFrom,
    DateTime? effectiveUntil,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final body = <String, dynamic>{
        'variant_id': variantId,
        'outlet_id': outletId,
        'amount': amount,
        'currency': currency,
        if (effectiveFrom != null)
          'effective_from': effectiveFrom.toIso8601String(),
        if (effectiveUntil != null)
          'effective_until': effectiveUntil.toIso8601String(),
      };

      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/prices',
        idempotencyKey: key,
        body: body,
      );

      if (response.isSuccess) {
        _setLoading(false);
        return true;
      } else {
        _setError(response.message ?? 'Gagal mengatur harga.');
      }
    } catch (e) {
      _setError('Error saat mengatur harga: $e');
    } finally {
      _setLoading(false);
    }
    return false;
  }

  // =========================================================================
  // MODIFIER GROUPS
  // =========================================================================

  Future<List<ModifierGroupModel>> fetchModifierGroups() async {
    try {
      final response = await apiClient.get<dynamic>('/api/v1/modifier-groups');

      if (response.isSuccess && response.data != null) {
        final rawList = response.data is List
            ? response.data as List<dynamic>
            : (response.data is Map
                  ? (response.data['items'] ?? response.data['data'] ?? [])
                        as List<dynamic>
                  : <dynamic>[]);
        _modifierGroups = rawList
            .map((m) => ModifierGroupModel.fromJson(m as Map<String, dynamic>))
            .toList();
        notifyListeners();
        return _modifierGroups;
      }
    } catch (e) {
      _setError('Gagal memuat modifier groups: $e');
    }
    return _modifierGroups;
  }

  Future<ModifierGroupModel?> createModifierGroup({
    required String name,
    String selectionMode = 'single',
    int minSelection = 0,
    int maxSelection = 1,
    bool isRequired = false,
    List<Map<String, dynamic>> modifiers = const [],
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/modifier-groups',
        idempotencyKey: key,
        body: {
          'name': name.trim(),
          'selection_mode': selectionMode,
          'min_selection': minSelection,
          'max_selection': maxSelection,
          'is_required': isRequired,
          'modifiers': modifiers,
        },
      );

      if (response.isSuccess && response.data != null) {
        final rawData = response.data!['data'] is Map<String, dynamic>
            ? response.data!['data'] as Map<String, dynamic>
            : response.data!;
        final created = ModifierGroupModel.fromJson(rawData);
        _modifierGroups.add(created);
        _setLoading(false);
        return created;
      } else {
        _setError(response.message ?? 'Gagal membuat grup modifier.');
      }
    } catch (e) {
      _setError('Error saat membuat grup modifier: $e');
    } finally {
      _setLoading(false);
    }
    return null;
  }
}
