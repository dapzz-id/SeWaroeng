import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'purchasing_models.dart';

class PurchasingService extends ChangeNotifier {
  static const _uuid = Uuid();

  final ApiClient apiClient;

  List<SupplierModel> _suppliers = [];
  List<PurchaseOrderModel> _purchaseOrders = [];
  List<PurchaseReceiptModel> _purchaseReceipts = [];

  bool _isLoading = false;
  String? _errorMessage;

  List<SupplierModel> get suppliers => _suppliers;
  List<PurchaseOrderModel> get purchaseOrders => _purchaseOrders;
  List<PurchaseReceiptModel> get purchaseReceipts => _purchaseReceipts;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  List<SupplierModel> get activeSuppliers =>
      _suppliers.where((s) => s.isActive).toList();

  PurchasingService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
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

  // -------------------------------------------------------------
  // SUPPLIERS
  // -------------------------------------------------------------

  Future<void> fetchSuppliers({String? status, String? search}) async {
    _setLoading(true);
    _setError(null);

    try {
      final path = _buildPath('/api/v1/purchasing/suppliers', {
        'status': status,
        'search': search,
      });

      final response = await apiClient.get<dynamic>(path);

      if (response.isSuccess && response.data != null) {
        final rawData = response.data;
        List<dynamic> list = [];
        if (rawData is List) {
          list = rawData;
        } else if (rawData is Map && rawData['data'] is List) {
          list = rawData['data'] as List;
        }
        _suppliers = list
            .map((s) => SupplierModel.fromJson(s as Map<String, dynamic>))
            .toList();
        notifyListeners();
      } else {
        _setError(response.message ?? 'Gagal memuat daftar supplier');
      }
    } catch (e) {
      _setError('Terjadi kesalahan koneksi: $e');
    } finally {
      _setLoading(false);
    }
  }

  Future<SupplierModel?> createSupplier({
    required String code,
    required String name,
    String? contactName,
    String? phone,
    String? email,
    String? address,
    String? taxIdentifier,
    int? paymentTermsDays,
    String? notes,
    String status = 'active',
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/purchasing/suppliers',
        body: {
          'code': code,
          'name': name,
          'contact_name': contactName,
          'phone': phone,
          'email': email,
          'address': address,
          'tax_identifier': taxIdentifier,
          'payment_terms_days': paymentTermsDays,
          'notes': notes,
          'status': status,
        },
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final newSupplier = SupplierModel.fromJson(raw);
        _suppliers.insert(0, newSupplier);
        notifyListeners();
        return newSupplier;
      } else {
        _setError(response.message ?? 'Gagal membuat supplier');
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      return null;
    } finally {
      _setLoading(false);
    }
  }

  Future<SupplierModel?> updateSupplier({
    required String id,
    String? name,
    String? contactName,
    String? phone,
    String? email,
    String? address,
    String? taxIdentifier,
    int? paymentTermsDays,
    String? notes,
    String? status,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final body = <String, dynamic>{};
      if (name != null) body['name'] = name;
      if (contactName != null) body['contact_name'] = contactName;
      if (phone != null) body['phone'] = phone;
      if (email != null) body['email'] = email;
      if (address != null) body['address'] = address;
      if (taxIdentifier != null) body['tax_identifier'] = taxIdentifier;
      if (paymentTermsDays != null) {
        body['payment_terms_days'] = paymentTermsDays;
      }
      if (notes != null) body['notes'] = notes;
      if (status != null) body['status'] = status;

      final response = await apiClient.put<dynamic>(
        '/api/v1/purchasing/suppliers/$id',
        body: body,
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final updatedSupplier = SupplierModel.fromJson(raw);
        final index = _suppliers.indexWhere((s) => s.id == id);
        if (index != -1) {
          _suppliers[index] = updatedSupplier;
        }
        notifyListeners();
        return updatedSupplier;
      } else {
        _setError(response.message ?? 'Gagal memperbarui supplier');
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      return null;
    } finally {
      _setLoading(false);
    }
  }

  // -------------------------------------------------------------
  // PURCHASE ORDERS
  // -------------------------------------------------------------

  Future<void> fetchPurchaseOrders({String? status, String? supplierId}) async {
    _setLoading(true);
    _setError(null);

    try {
      final path = _buildPath('/api/v1/purchasing/orders', {
        'status': status,
        'supplier_id': supplierId,
      });

      final response = await apiClient.get<dynamic>(path);

      if (response.isSuccess && response.data != null) {
        final rawData = response.data;
        List<dynamic> list = [];
        if (rawData is List) {
          list = rawData;
        } else if (rawData is Map && rawData['data'] is List) {
          list = rawData['data'] as List;
        }
        _purchaseOrders = list
            .map(
              (po) => PurchaseOrderModel.fromJson(po as Map<String, dynamic>),
            )
            .toList();
        notifyListeners();
      } else {
        _setError(response.message ?? 'Gagal memuat Purchase Orders');
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
    } finally {
      _setLoading(false);
    }
  }

  Future<PurchaseOrderModel?> getPurchaseOrder(String id) async {
    try {
      final response = await apiClient.get<dynamic>(
        '/api/v1/purchasing/orders/$id',
      );
      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final po = PurchaseOrderModel.fromJson(raw);
        final index = _purchaseOrders.indexWhere((p) => p.id == id);
        if (index != -1) {
          _purchaseOrders[index] = po;
          notifyListeners();
        }
        return po;
      }
    } catch (e) {
      debugPrint('Error getting purchase order detail: $e');
    }
    return null;
  }

  Future<PurchaseOrderModel?> createPurchaseOrder({
    required String supplierId,
    required String destinationLocationId,
    DateTime? expectedAt,
    String? notes,
    required List<Map<String, dynamic>> items,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/purchasing/orders',
        body: {
          'supplier_id': supplierId,
          'destination_location_id': destinationLocationId,
          'expected_at': expectedAt?.toIso8601String(),
          'notes': notes,
          'items': items,
        },
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final newPo = PurchaseOrderModel.fromJson(raw);
        _purchaseOrders.insert(0, newPo);
        notifyListeners();
        return newPo;
      } else {
        _setError(response.message ?? 'Gagal membuat Purchase Order');
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      return null;
    } finally {
      _setLoading(false);
    }
  }

  Future<bool> submitPurchaseOrder(String id) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/purchasing/orders/$id/submit',
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess) {
        await getPurchaseOrder(id);
        return true;
      } else {
        _setError(response.message ?? 'Gagal submit Purchase Order');
        return false;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  Future<bool> approvePurchaseOrder(String id) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/purchasing/orders/$id/approve',
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess) {
        await getPurchaseOrder(id);
        return true;
      } else {
        _setError(response.message ?? 'Gagal menyetujui Purchase Order');
        return false;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  Future<bool> cancelPurchaseOrder(String id) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/purchasing/orders/$id/cancel',
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess) {
        await getPurchaseOrder(id);
        return true;
      } else {
        _setError(response.message ?? 'Gagal membatalkan Purchase Order');
        return false;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  // -------------------------------------------------------------
  // GOODS RECEIPTS (RECEIVING)
  // -------------------------------------------------------------

  Future<void> fetchPurchaseReceipts({String? purchaseOrderId}) async {
    _setLoading(true);
    _setError(null);

    try {
      final path = _buildPath('/api/v1/purchasing/receipts', {
        'purchase_order_id': purchaseOrderId,
      });

      final response = await apiClient.get<dynamic>(path);

      if (response.isSuccess && response.data != null) {
        final rawData = response.data;
        List<dynamic> list = [];
        if (rawData is List) {
          list = rawData;
        } else if (rawData is Map && rawData['data'] is List) {
          list = rawData['data'] as List;
        }
        _purchaseReceipts = list
            .map(
              (grn) =>
                  PurchaseReceiptModel.fromJson(grn as Map<String, dynamic>),
            )
            .toList();
        notifyListeners();
      } else {
        _setError(response.message ?? 'Gagal memuat bukti penerimaan barang');
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
    } finally {
      _setLoading(false);
    }
  }

  Future<PurchaseReceiptModel?> createPurchaseReceipt({
    required String purchaseOrderId,
    String? destinationLocationId,
    String? notes,
    required List<Map<String, dynamic>> items,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final body = <String, dynamic>{
        'purchase_order_id': purchaseOrderId,
        'items': items,
      };
      if (destinationLocationId != null) {
        body['destination_location_id'] = destinationLocationId;
      }
      if (notes != null) {
        body['notes'] = notes;
      }

      final response = await apiClient.post<dynamic>(
        '/api/v1/purchasing/receipts',
        body: body,
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final newGrn = PurchaseReceiptModel.fromJson(raw);
        _purchaseReceipts.insert(0, newGrn);
        notifyListeners();
        return newGrn;
      } else {
        _setError(response.message ?? 'Gagal membuat draft penerimaan barang');
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      return null;
    } finally {
      _setLoading(false);
    }
  }

  Future<PurchaseReceiptModel?> postPurchaseReceipt(String id) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/purchasing/receipts/$id/post',
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final raw =
            response.data is Map && (response.data as Map).containsKey('data')
            ? (response.data as Map)['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;
        final postedGrn = PurchaseReceiptModel.fromJson(raw);
        final index = _purchaseReceipts.indexWhere((g) => g.id == id);
        if (index != -1) {
          _purchaseReceipts[index] = postedGrn;
        }
        notifyListeners();
        return postedGrn;
      } else {
        _setError(response.message ?? 'Gagal posting penerimaan barang');
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      return null;
    } finally {
      _setLoading(false);
    }
  }
}
