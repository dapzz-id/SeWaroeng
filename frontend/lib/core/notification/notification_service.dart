import 'package:flutter/foundation.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/notification/notification_models.dart';

class NotificationService extends ChangeNotifier {
  final ApiClient apiClient;

  List<AppNotification> _notifications = [];
  int _unreadCount = 0;
  bool _isLoading = false;
  String? _errorMessage;

  List<AppNotification> get notifications => _notifications;
  int get unreadCount => _unreadCount;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  NotificationService({required this.apiClient});

  void _setLoading(bool value) {
    _isLoading = value;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch notifications list
  Future<List<AppNotification>> fetchNotifications({
    bool unreadOnly = false,
    String? type,
    int page = 1,
    int perPage = 20,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>[];
      if (unreadOnly) params.add('unread_only=true');
      if (type != null && type.isNotEmpty) params.add('type=$type');
      params.add('page=$page');
      params.add('per_page=$perPage');

      final queryStr = params.isNotEmpty ? '?${params.join('&')}' : '';
      final response = await apiClient.get<dynamic>('/api/v1/notifications$queryStr');

      if (response.isSuccess && response.data != null) {
        final dynamic rawData = response.data;
        List<dynamic> items = [];
        if (rawData is Map && rawData.containsKey('data')) {
          items = rawData['data'] as List<dynamic>? ?? [];
        } else if (rawData is List) {
          items = rawData;
        }

        _notifications = items
            .map((item) => AppNotification.fromJson(item as Map<String, dynamic>))
            .toList();

        // Also update unread count if returned in meta, otherwise count unread
        if (rawData is Map && rawData['meta'] != null && rawData['meta']['unread_count'] != null) {
          _unreadCount = (rawData['meta']['unread_count'] as num).toInt();
        } else {
          _unreadCount = _notifications.where((n) => !n.isRead).length;
        }

        _setLoading(false);
        return _notifications;
      } else {
        _setError(response.message ?? 'Gagal memuat notifikasi.');
        _setLoading(false);
        return [];
      }
    } catch (e) {
      _setError('Terjadi kesalahan saat memuat notifikasi: $e');
      _setLoading(false);
      return [];
    }
  }

  /// Fetch unread notifications count
  Future<int> fetchUnreadCount() async {
    try {
      final response = await apiClient.get<dynamic>('/api/v1/notifications/unread-count');
      if (response.isSuccess && response.data != null) {
        final data = response.data;
        if (data is Map && data.containsKey('data') && data['data'] is Map) {
          _unreadCount = ((data['data'] as Map)['unread_count'] as num?)?.toInt() ?? 0;
        } else if (data is Map && data.containsKey('unread_count')) {
          _unreadCount = (data['unread_count'] as num?)?.toInt() ?? 0;
        }
        notifyListeners();
        return _unreadCount;
      }
    } catch (_) {}
    return _unreadCount;
  }

  /// Mark single notification as read
  Future<bool> markAsRead(String id) async {
    try {
      final response = await apiClient.post<dynamic>('/api/v1/notifications/$id/read', body: {});
      if (response.isSuccess) {
        final index = _notifications.indexWhere((n) => n.id == id);
        if (index != -1) {
          final old = _notifications[index];
          _notifications[index] = AppNotification(
            id: old.id,
            type: old.type,
            title: old.title,
            message: old.message,
            severity: old.severity,
            data: old.data,
            readAt: DateTime.now(),
            createdAt: old.createdAt,
          );
        }
        if (_unreadCount > 0) {
          _unreadCount--;
        }
        notifyListeners();
        return true;
      }
    } catch (_) {}
    return false;
  }

  /// Mark all notifications as read
  Future<bool> markAllAsRead() async {
    try {
      final response = await apiClient.post<dynamic>('/api/v1/notifications/read-all', body: {});
      if (response.isSuccess) {
        _notifications = _notifications
            .map((old) => AppNotification(
                  id: old.id,
                  type: old.type,
                  title: old.title,
                  message: old.message,
                  severity: old.severity,
                  data: old.data,
                  readAt: old.readAt ?? DateTime.now(),
                  createdAt: old.createdAt,
                ))
            .toList();
        _unreadCount = 0;
        notifyListeners();
        return true;
      }
    } catch (_) {}
    return false;
  }
}
