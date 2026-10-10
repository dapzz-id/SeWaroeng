import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import '../customer/customer_models.dart';
import '../sync/sync_service.dart';
import 'pos_models.dart';

class PosService extends ChangeNotifier {
  static const _uuid = Uuid();

  final ApiClient apiClient;
  final SyncService? syncService;

  bool _isOffline = false;
  bool get isOffline => _isOffline;

  void setOfflineMode(bool offline) {
    _isOffline = offline;
    notifyListeners();
  }

  // Catalog State
  List<PosCatalogCategory> _categories = [];
  List<PosCatalogProduct> _products = [];
  String? _selectedCategoryId;
  String _searchQuery = '';

  // Customer State
  CustomerModel? _selectedCustomer;

  // Sales History State
  List<PosSale> _sales = [];
  PosSale? _currentSaleDetail;

  // Active Cart State
  String? _currentSaleId;
  String? _currentReferenceNumber;
  final List<PosCartItem> _cartItems = [];
  int _discountMinor = 0;
  int _taxMinor = 0;
  String? _cartNotes;

  // Status & Error
  bool _isLoading = false;
  bool _isFinalizing = false;
  String? _errorMessage;

  // Getters
  List<PosCatalogCategory> get categories => _categories;
  List<PosCatalogProduct> get products => _products;
  String? get selectedCategoryId => _selectedCategoryId;
  String get searchQuery => _searchQuery;

  List<PosSale> get sales => _sales;
  PosSale? get currentSaleDetail => _currentSaleDetail;

  String? get currentSaleId => _currentSaleId;
  String? get currentReferenceNumber => _currentReferenceNumber;
  List<PosCartItem> get cartItems => List.unmodifiable(_cartItems);
  int get discountMinor => _discountMinor;
  int get taxMinor => _taxMinor;
  String? get cartNotes => _cartNotes;

  bool get isLoading => _isLoading;
  bool get isFinalizing => _isFinalizing;
  String? get errorMessage => _errorMessage;

  CustomerModel? get selectedCustomer => _selectedCustomer;

  void setSelectedCustomer(CustomerModel? customer) {
    _selectedCustomer = customer;
    notifyListeners();
  }

  // Filtered Catalog
  List<PosCatalogProduct> get filteredProducts {
    return _products.where((product) {
      if (_selectedCategoryId != null &&
          _selectedCategoryId!.isNotEmpty &&
          product.categoryId != _selectedCategoryId) {
        return false;
      }
      if (_searchQuery.isNotEmpty) {
        final query = _searchQuery.toLowerCase();
        final nameMatch = product.name.toLowerCase().contains(query);
        final descMatch =
            product.description?.toLowerCase().contains(query) ?? false;
        return nameMatch || descMatch;
      }
      return true;
    }).toList();
  }

  // Cart Calculations
  int get cartSubtotalMinor =>
      _cartItems.fold(0, (sum, item) => sum + item.subtotalMinor);

  int get cartTotalMinor {
    final sub = cartSubtotalMinor;
    final afterDiscount = (sub - _discountMinor) > 0 ? (sub - _discountMinor) : 0;
    return afterDiscount + _taxMinor;
  }

  int get cartTotalItemCount =>
      _cartItems.fold(0, (sum, item) => sum + item.quantity);

  PosService({required this.apiClient, this.syncService});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? msg) {
    _errorMessage = msg;
    notifyListeners();
  }

  void selectCategory(String? categoryId) {
    _selectedCategoryId = categoryId;
    notifyListeners();
  }

  void setSearchQuery(String query) {
    _searchQuery = query;
    notifyListeners();
  }

  // -------------------------------------------------------------
  // CART MANAGEMENT
  // -------------------------------------------------------------

  void addToCart({
    required PosCatalogProduct product,
    required PosCatalogVariant variant,
    int quantity = 1,
    List<PosCatalogModifier>? modifiers,
    String? notes,
  }) {
    // Check if identical item already exists in cart (same variant + same modifiers + same notes)
    final existingIndex = _cartItems.indexWhere((item) {
      if (item.variant.id != variant.id) return false;
      if (item.notes != notes) return false;
      if (item.selectedModifiers.length != (modifiers?.length ?? 0)) return false;
      final existingModIds = item.selectedModifiers.map((m) => m.id).toSet();
      final newModIds = (modifiers ?? []).map((m) => m.id).toSet();
      return existingModIds.containsAll(newModIds);
    });

    if (existingIndex >= 0) {
      _cartItems[existingIndex].quantity += quantity;
    } else {
      _cartItems.add(
        PosCartItem(
          id: _uuid.v7(),
          product: product,
          variant: variant,
          quantity: quantity,
          selectedModifiers: modifiers,
          notes: notes,
        ),
      );
    }
    notifyListeners();
  }

  void updateCartItemQuantity(String cartItemId, int newQty) {
    final index = _cartItems.indexWhere((item) => item.id == cartItemId);
    if (index >= 0) {
      if (newQty <= 0) {
        _cartItems.removeAt(index);
      } else {
        _cartItems[index].quantity = newQty;
      }
      notifyListeners();
    }
  }

  void removeFromCart(String cartItemId) {
    _cartItems.removeWhere((item) => item.id == cartItemId);
    notifyListeners();
  }

  void setDiscount(int discount) {
    _discountMinor = discount < 0 ? 0 : discount;
    notifyListeners();
  }

  void setCartNotes(String? notes) {
    _cartNotes = notes;
    notifyListeners();
  }

  void clearCart({bool keepCustomer = false}) {
    _cartItems.clear();
    _currentSaleId = null;
    _currentReferenceNumber = null;
    _discountMinor = 0;
    _taxMinor = 0;
    _cartNotes = null;
    _errorMessage = null;
    if (!keepCustomer) {
      _selectedCustomer = null;
    }
    notifyListeners();
  }

  // -------------------------------------------------------------
  // API: CATALOG & SALES
  // -------------------------------------------------------------

  Future<void> fetchCatalog({required String outletId}) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.get<dynamic>('/api/v1/pos/catalog?outlet_id=$outletId');
      if (response.isSuccess && response.data != null) {
        final data = response.data as Map<String, dynamic>;

        _categories = (data['categories'] as List<dynamic>?)
                ?.map((c) => PosCatalogCategory.fromJson(c as Map<String, dynamic>))
                .toList() ??
            [];

        _products = (data['products'] as List<dynamic>?)
                ?.map((p) => PosCatalogProduct.fromJson(p as Map<String, dynamic>))
                .toList() ??
            [];

        if (syncService != null && outletId.isNotEmpty) {
          syncService!.saveCatalogSnapshot(
            tenantId: '',
            outletId: outletId,
            products: _products,
            categories: _categories,
          );
        }
      } else {
        _setError(response.message ?? 'Gagal memuat katalog POS.');
        if (_products.isEmpty && syncService != null) {
          await loadOfflineCatalog(outletId: outletId);
        }
      }
    } catch (e) {
      _setError(e.toString());
      if (_products.isEmpty && syncService != null) {
        await loadOfflineCatalog(outletId: outletId);
      }
    } finally {
      _setLoading(false);
    }
  }

  Future<void> loadOfflineCatalog({required String outletId}) async {
    if (syncService == null) return;
    _setLoading(true);
    try {
      final products = await syncService!.getOfflineCatalog(outletId);
      if (products.isNotEmpty) {
        _products = products;
        _setError(null);
      }
    } catch (e) {
      _setError('Gagal memuat katalog offline: $e');
    } finally {
      _setLoading(false);
    }
  }

  Future<void> fetchSales({int page = 1, String? status}) async {
    _setLoading(true);
    _setError(null);

    try {
      final query = status != null ? '?page=$page&status=$status' : '?page=$page';
      final response = await apiClient.get<dynamic>('/api/v1/pos/sales$query');
      if (response.isSuccess && response.data != null) {
        final data = response.data;
        List<dynamic> list = [];
        if (data is List) {
          list = data;
        } else if (data is Map && data['data'] is List) {
          list = data['data'] as List;
        }

        _sales = list
            .map((s) => PosSale.fromJson(s as Map<String, dynamic>))
            .toList();
      } else {
        _setError(response.message ?? 'Gagal memuat riwayat transaksi.');
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  Future<PosSale?> getSaleDetail(String saleId) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.get<dynamic>('/api/v1/pos/sales/$saleId');
      if (response.isSuccess && response.data != null) {
        final sale = PosSale.fromJson(response.data as Map<String, dynamic>);
        _currentSaleDetail = sale;
        return sale;
      } else {
        _setError(response.message ?? 'Gagal memuat detail transaksi.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  // -------------------------------------------------------------
  // API: SALE OPERATIONS
  // -------------------------------------------------------------

  Future<PosSale?> saveOpenSale({required String outletId}) async {
    if (_cartItems.isEmpty) {
      _setError('Cart is empty.');
      return null;
    }

    _setLoading(true);
    _setError(null);

    try {
      final payload = <String, dynamic>{
        'outlet_id': outletId,
        'discount_minor': _discountMinor,
        'tax_minor': _taxMinor,
        'notes': _cartNotes,
        if (_selectedCustomer != null) 'customer_id': _selectedCustomer!.id,
        'items': _cartItems.map((item) => item.toApiItem()).toList(),
      };

      final ApiResponse<dynamic> response;
      if (_currentSaleId != null) {
        response = await apiClient.put<dynamic>(
          '/api/v1/pos/sales/$_currentSaleId',
          idempotencyKey: _uuid.v7(),
          body: payload,
        );
      } else {
        response = await apiClient.post<dynamic>(
          '/api/v1/pos/sales',
          idempotencyKey: _uuid.v7(),
          body: payload,
        );
      }

      if (response.isSuccess && response.data != null) {
        final sale = PosSale.fromJson(response.data as Map<String, dynamic>);
        _currentSaleId = sale.id;
        _currentReferenceNumber = sale.referenceNumber;
        return sale;
      } else {
        _setError(response.message ?? 'Gagal menyimpan draf transaksi.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  Future<PosSale?> finalizeCurrentSale({required String outletId}) async {
    if (_cartItems.isEmpty) {
      _setError('Keranjang kosong.');
      return null;
    }

    _isFinalizing = true;
    _setError(null);
    notifyListeners();

    try {
      // Step 1: Ensure sale exists on server
      String saleId = _currentSaleId ?? '';
      if (saleId.isEmpty) {
        final saved = await saveOpenSale(outletId: outletId);
        if (saved == null) {
          throw Exception(_errorMessage ?? 'Gagal membuat transaksi.');
        }
        saleId = saved.id;
      }

      // Step 2: Finalize atomically
      final response = await apiClient.post<dynamic>(
        '/api/v1/pos/sales/$saleId/finalize',
        idempotencyKey: _uuid.v7(),
      );

      if (response.isSuccess && response.data != null) {
        final finalizedSale =
            PosSale.fromJson(response.data as Map<String, dynamic>);
        clearCart();
        return finalizedSale;
      } else {
        final errorMsg = response.message ??
            (response.code == 'INSUFFICIENT_STOCK'
                ? 'Stok bahan baku tidak mencukupi untuk menyelesaikan pesanan.'
                : response.code == 'PRICE_NOT_CONFIGURED'
                    ? 'Harga jual belum dikonfigurasi untuk salah satu produk.'
                    : 'Gagal menyelesaikan transaksi.');
        _setError(errorMsg);
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _isFinalizing = false;
      notifyListeners();
    }
  }

  /// Finalizes an offline sale atomically into local storage and transactional outbox.
  /// Complies with ADR-0004:
  /// - Restricts payments to Cash/Manual (gateways like Midtrans/Duitku blocked offline).
  /// - Enforces shift awareness (active shift snapshot required).
  Future<PosSale?> finalizeOfflineSale({
    required String tenantId,
    required String outletId,
    required String? shiftId,
    required String cashierUserId,
    String paymentMethod = 'cash',
    int? cashReceivedMinor,
  }) async {
    if (_cartItems.isEmpty) {
      _setError('Keranjang kosong.');
      return null;
    }

    final normalizedMethod = paymentMethod.toLowerCase();
    if (normalizedMethod != 'cash' && normalizedMethod != 'manual') {
      _setError(
        'Metode pembayaran online gateway tidak tersedia dalam mode offline. Gunakan pembayaran Tunai.',
      );
      return null;
    }

    if (shiftId == null || shiftId.isEmpty) {
      _setError(
        'Shift kasir belum aktif. Buka shift terlebih dahulu saat online.',
      );
      return null;
    }

    if (syncService == null) {
      _setError('Sync service tidak tersedia.');
      return null;
    }

    _isFinalizing = true;
    _setError(null);
    notifyListeners();

    try {
      final subtotal = cartSubtotalMinor;
      final total = cartTotalMinor;
      final change =
          cashReceivedMinor != null ? (cashReceivedMinor - total) : 0;

      final offlineSale = await syncService!.recordOfflineSale(
        tenantId: tenantId,
        outletId: outletId,
        shiftId: shiftId,
        cashierUserId: cashierUserId,
        customer: _selectedCustomer,
        items: List.from(_cartItems),
        subtotalMinor: subtotal,
        discountMinor: _discountMinor,
        taxMinor: _taxMinor,
        totalMinor: total,
        paymentMethod: normalizedMethod,
        cashReceivedMinor: cashReceivedMinor ?? total,
        cashChangeMinor: change > 0 ? change : 0,
        notes: _cartNotes,
      );

      _sales.insert(0, offlineSale);
      clearCart();
      return offlineSale;
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _isFinalizing = false;
      notifyListeners();
    }
  }

  Future<bool> cancelSale(String saleId, {String? reason}) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<dynamic>(
        '/api/v1/pos/sales/$saleId/cancel',
        idempotencyKey: _uuid.v7(),
        body: {'reason': reason ?? 'Dibatalkan oleh kasir'},
      );

      if (response.isSuccess) {
        if (_currentSaleId == saleId) {
          clearCart();
        }
        return true;
      } else {
        _setError(response.message ?? 'Gagal membatalkan transaksi.');
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
