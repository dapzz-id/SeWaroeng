import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'inventory_models.dart';

class InventoryService extends ChangeNotifier {
  static const _uuid = Uuid();

  final ApiClient apiClient;

  List<InventoryLocationModel> _locations = [];
  List<InventoryItemModel> _items = [];
  final List<InventoryMovementModel> _recentMovements = [];
  List<StockOpnameModel> _opnames = [];
  final List<StockTransferModel> _transfers = [];

  InventoryLocationModel? _selectedLocation;
  bool _isLoading = false;
  String? _errorMessage;
  int _totalItems = 0;
  int _currentPage = 1;
  int _lastPage = 1;

  List<InventoryLocationModel> get locations => _locations;
  List<InventoryItemModel> get items => _items;
  List<InventoryMovementModel> get recentMovements => _recentMovements;
  List<StockOpnameModel> get opnames => _opnames;
  List<StockTransferModel> get transfers => _transfers;
  InventoryLocationModel? get selectedLocation => _selectedLocation;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;
  int get totalItems => _totalItems;
  int get currentPage => _currentPage;
  int get lastPage => _lastPage;

  int get lowStockCount => _items.where((i) => i.isLowStock).length;
  int get outOfStockCount => _items.where((i) => i.isOutOfStock).length;
  int get negativeStockCount => _items.where((i) => i.isNegative).length;

  InventoryService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  void setSelectedLocation(InventoryLocationModel? location) {
    _selectedLocation = location;
    notifyListeners();
    fetchItems(locationId: location?.id);
  }

  String _buildPath(String basePath, Map<String, dynamic> params) {
    final queryEntries = params.entries
        .where((e) => e.value != null && e.value.toString().isNotEmpty)
        .map(
          (e) =>
              '${Uri.encodeComponent(e.key)}=${Uri.encodeComponent(e.value.toString())}',
        )
        .join('&');
    return queryEntries.isEmpty ? basePath : '$basePath?$queryEntries';
  }

  /// Fetch inventory locations
  Future<void> fetchLocations() async {
    try {
      final response = await apiClient.get<dynamic>(
        '/api/v1/inventory/locations',
      );
      if (response.isSuccess && response.data != null) {
        final rawData = response.data;
        List<dynamic> list = [];
        if (rawData is List) {
          list = rawData;
        } else if (rawData is Map && rawData['data'] is List) {
          list = rawData['data'] as List;
        }
        _locations = list
            .map(
              (l) => InventoryLocationModel.fromJson(l as Map<String, dynamic>),
            )
            .toList();

        if (_locations.isNotEmpty && _selectedLocation == null) {
          _selectedLocation = _locations.first;
        }
        notifyListeners();
      }
    } catch (e) {
      debugPrint('Error fetching inventory locations: $e');
    }
  }

  /// Create a new inventory location
  Future<InventoryLocationModel?> createLocation({
    required String name,
    required String code,
    required String type,
    String? outletId,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/inventory/locations',
        body: {
          'name': name,
          'code': code,
          'type': type,
          'outlet_id': ?outletId,
        },
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final rawData =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final newLoc = InventoryLocationModel.fromJson(rawData);
        _locations.add(newLoc);
        notifyListeners();
        return newLoc;
      } else {
        _setError(response.message ?? 'Gagal membuat lokasi.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch paginated inventory items
  Future<void> fetchItems({
    String? locationId,
    String? search,
    bool? lowStock,
    int page = 1,
  }) async {
    _setLoading(true);
    _setError(null);

    final locId = locationId ?? _selectedLocation?.id;
    final path = _buildPath('/api/v1/inventory/items', {
      'page': page,
      'per_page': 20,
      'location_id': ?locId,
      if (search != null && search.isNotEmpty) 'search': search,
      if (lowStock == true) 'low_stock': true,
    });

    try {
      final response = await apiClient.get<dynamic>(path);

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final List<dynamic> itemsList = raw['items'] as List<dynamic>? ?? [];
        _items = itemsList
            .map((i) => InventoryItemModel.fromJson(i as Map<String, dynamic>))
            .toList();

        final pagination = raw['pagination'] as Map<String, dynamic>?;
        if (pagination != null) {
          _totalItems = pagination['total'] as int? ?? _items.length;
          _currentPage = pagination['current_page'] as int? ?? 1;
          _lastPage = pagination['last_page'] as int? ?? 1;
        }
      } else {
        _setError(response.message ?? 'Gagal memuat barang.');
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  /// Create a new inventory item
  Future<InventoryItemModel?> createItem({
    required String name,
    String? sku,
    String? productVariantId,
    String itemType = 'sellable',
    String baseUnit = 'piece',
    bool allowNegativeStock = false,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/inventory/items',
        body: {
          'name': name,
          if (sku != null && sku.isNotEmpty) 'sku': sku,
          'product_variant_id': ?productVariantId,
          'item_type': itemType,
          'base_unit': baseUnit,
          'allow_negative_stock': allowNegativeStock,
        },
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final newItem = InventoryItemModel.fromJson(raw);
        _items.insert(0, newItem);
        notifyListeners();
        return newItem;
      } else {
        _setError(response.message ?? 'Gagal membuat item inventory.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  /// Perform Stock Adjustment (delta)
  Future<bool> adjustStock({
    required String itemId,
    required String locationId,
    required double delta,
    required String reason,
    String? note,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/inventory/adjustments',
        body: {
          'inventory_item_id': itemId,
          'location_id': locationId,
          'quantity_delta': delta,
          'reason': reason,
          if (note != null && note.isNotEmpty) 'note': note,
        },
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess) {
        await fetchItems(locationId: locationId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal melakukan penyesuaian stok.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Set Opening Balance
  Future<bool> setOpeningBalance({
    required String itemId,
    required String locationId,
    required double quantity,
    String? note,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/inventory/opening-balances',
        body: {
          'inventory_item_id': itemId,
          'location_id': locationId,
          'quantity': quantity,
          if (note != null && note.isNotEmpty) 'note': note,
        },
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess) {
        await fetchItems(locationId: locationId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal mencatat saldo awal.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch Item Movement History
  Future<List<InventoryMovementModel>> fetchItemMovements(
    String itemId, {
    String? locationId,
  }) async {
    try {
      final path = _buildPath('/api/v1/inventory/items/$itemId/movements', {
        'location_id': ?locationId,
        'per_page': 30,
      });

      final response = await apiClient.get<dynamic>(path);

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final list = raw['items'] as List<dynamic>? ?? [];
        return list
            .map(
              (m) => InventoryMovementModel.fromJson(m as Map<String, dynamic>),
            )
            .toList();
      }
    } catch (e) {
      debugPrint('Error fetching item movements: $e');
    }
    return [];
  }

  /// Fetch Stock Opnames
  Future<void> fetchOpnames({String? locationId}) async {
    _setLoading(true);
    _setError(null);

    try {
      final path = _buildPath('/api/v1/inventory/opnames', {
        'location_id': ?locationId,
      });

      final response = await apiClient.get<dynamic>(path);

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final list = raw['items'] as List<dynamic>? ?? [];
        _opnames = list
            .map((o) => StockOpnameModel.fromJson(o as Map<String, dynamic>))
            .toList();
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  /// Create Stock Opname
  Future<StockOpnameModel?> createOpname({
    required String locationId,
    required List<Map<String, dynamic>> items,
    String? notes,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/inventory/opnames',
        body: {
          'location_id': locationId,
          'items': items,
          if (notes != null && notes.isNotEmpty) 'notes': notes,
        },
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final opname = StockOpnameModel.fromJson(raw);
        _opnames.insert(0, opname);
        notifyListeners();
        return opname;
      } else {
        _setError(response.message ?? 'Gagal membuat sesi stock opname.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  /// Approve Stock Opname
  Future<bool> approveOpname(String opnameId) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/inventory/opnames/$opnameId/approve',
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess) {
        await fetchOpnames(locationId: _selectedLocation?.id);
        await fetchItems(locationId: _selectedLocation?.id);
        return true;
      } else {
        _setError(response.message ?? 'Gagal menyetujui stock opname.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }
}
