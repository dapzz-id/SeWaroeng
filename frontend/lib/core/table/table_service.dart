import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'table_models.dart';

class TableService extends ChangeNotifier {
  static final _uuid = const Uuid();

  final ApiClient apiClient;

  List<TableModel> _tables = [];
  List<BookingModel> _bookings = [];
  FloorPlanData? _floorPlan;
  bool _isLoading = false;
  String? _errorMessage;

  List<TableModel> get tables => _tables;
  List<BookingModel> get bookings => _bookings;
  FloorPlanData? get floorPlan => _floorPlan;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  TableService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch visual floor plan and table layouts for active outlet
  Future<void> fetchFloorPlan(String outletId) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.get<Map<String, dynamic>>(
        '/api/v1/tables/floor-plan?outlet_id=$outletId',
      );

      if (response.isSuccess && response.data != null) {
        final data = response.data!;
        if (data['floor_plan'] != null) {
          _floorPlan = FloorPlanData.fromJson(data['floor_plan'] as Map<String, dynamic>);
        }
        if (data['tables'] is List) {
          _tables = (data['tables'] as List<dynamic>)
              .map((e) => TableModel.fromJson(e as Map<String, dynamic>))
              .toList();
        }
      } else {
        _setError(response.message ?? 'Gagal memuat denah meja.');
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  /// Save floor plan layout and table coordinates
  Future<bool> saveFloorPlan({
    required String outletId,
    required FloorPlanData floorPlan,
    required List<TableModel> tables,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final tablesPayload = tables.map((t) => {
        'id': t.id,
        'x': t.posX,
        'y': t.posY,
        'width': t.tableWidth,
        'height': t.tableHeight,
        'shape': t.shape,
        'zone': t.zone,
        'name': t.name,
        'capacity': t.capacity,
      }).toList();

      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/tables/floor-plan',
        idempotencyKey: idempotencyKey ?? _uuid.v7(),
        body: {
          'outlet_id': outletId,
          'floor_plan': floorPlan.toJson(),
          'tables': tablesPayload,
        },
      );

      if (response.isSuccess && response.data != null) {
        final data = response.data!;
        if (data['floor_plan'] != null) {
          _floorPlan = FloorPlanData.fromJson(data['floor_plan'] as Map<String, dynamic>);
        }
        if (data['tables'] is List) {
          _tables = (data['tables'] as List<dynamic>)
              .map((e) => TableModel.fromJson(e as Map<String, dynamic>))
              .toList();
        }
        return true;
      } else {
        _setError(response.message ?? 'Gagal menyimpan denah meja.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch tables for active outlet
  Future<void> fetchTables({String? outletId, String? status}) async {
    _setLoading(true);
    _setError(null);

    try {
      final queryParams = <String, String>{};
      if (outletId != null && outletId.isNotEmpty) {
        queryParams['outlet_id'] = outletId;
      }
      if (status != null && status.isNotEmpty) {
        queryParams['status'] = status;
      }

      final uri = Uri(
        path: '/api/v1/tables',
        queryParameters: queryParams.isEmpty ? null : queryParams,
      );

      final response = await apiClient.get<List<dynamic>>(uri.toString());

      if (response.isSuccess && response.data != null) {
        _tables = response.data!
            .map((e) => TableModel.fromJson(e as Map<String, dynamic>))
            .toList();
      } else {
        _setError(response.message ?? 'Gagal memuat meja.');
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  /// Create table
  Future<bool> createTable({
    required String outletId,
    required String code,
    required String name,
    int capacity = 4,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/tables',
        idempotencyKey: key,
        body: {
          'outlet_id': outletId,
          'code': code.trim(),
          'name': name.trim(),
          'capacity': capacity,
        },
      );

      if (response.isSuccess) {
        await fetchTables(outletId: outletId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal membuat meja.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Check-in table (mark occupied)
  Future<bool> checkInTable(String tableId, {String? outletId}) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/tables/$tableId/check-in',
        idempotencyKey: _uuid.v7(),
        body: {},
      );

      if (response.isSuccess) {
        await fetchTables(outletId: outletId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal check-in meja.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Release table (mark available)
  Future<bool> releaseTable(String tableId, {String? outletId}) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/tables/$tableId/release',
        idempotencyKey: _uuid.v7(),
        body: {},
      );

      if (response.isSuccess) {
        await fetchTables(outletId: outletId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal melepaskan meja.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch bookings
  Future<void> fetchBookings({String? outletId, String? status, String? date}) async {
    _setLoading(true);
    _setError(null);

    try {
      final queryParams = <String, String>{};
      if (outletId != null && outletId.isNotEmpty) {
        queryParams['outlet_id'] = outletId;
      }
      if (status != null && status.isNotEmpty) {
        queryParams['status'] = status;
      }
      if (date != null && date.isNotEmpty) {
        queryParams['date'] = date;
      }

      final uri = Uri(
        path: '/api/v1/bookings',
        queryParameters: queryParams.isEmpty ? null : queryParams,
      );

      final response = await apiClient.get<List<dynamic>>(uri.toString());

      if (response.isSuccess && response.data != null) {
        _bookings = response.data!
            .map((e) => BookingModel.fromJson(e as Map<String, dynamic>))
            .toList();
      } else {
        _setError(response.message ?? 'Gagal memuat reservasi.');
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  /// Create booking
  Future<bool> createBooking({
    required String outletId,
    String? tableId,
    String? customerName,
    String? customerContact,
    required int partySize,
    required String startsAt,
    String? notes,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final body = <String, dynamic>{
        'outlet_id': outletId,
        'party_size': partySize,
        'starts_at': startsAt,
      };
      if (tableId != null) body['table_id'] = tableId;
      if (customerName != null) body['customer_name'] = customerName.trim();
      if (customerContact != null) body['customer_contact'] = customerContact.trim();
      if (notes != null) body['notes'] = notes.trim();

      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/bookings',
        idempotencyKey: key,
        body: body,
      );

      if (response.isSuccess) {
        await fetchBookings(outletId: outletId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal membuat reservasi.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Check-in booking
  Future<bool> checkInBooking(String bookingId, {String? outletId}) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/bookings/$bookingId/check-in',
        idempotencyKey: _uuid.v7(),
        body: {},
      );

      if (response.isSuccess) {
        await fetchBookings(outletId: outletId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal check-in reservasi.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Cancel booking
  Future<bool> cancelBooking(String bookingId, {String? outletId}) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/bookings/$bookingId/cancel',
        idempotencyKey: _uuid.v7(),
        body: {},
      );

      if (response.isSuccess) {
        await fetchBookings(outletId: outletId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal membatalkan reservasi.');
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
