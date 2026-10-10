import 'package:flutter/foundation.dart';
import '../api/api_client.dart';
import 'whatsapp_models.dart';

class WhatsAppService extends ChangeNotifier {
  final ApiClient apiClient;

  WhatsAppConnectionModel? _connection;
  List<WhatsAppProviderDefinitionModel> _providers = [];
  List<WhatsAppMessageModel> _messages = [];
  bool _isLoading = false;
  String? _errorMessage;

  WhatsAppConnectionModel? get connection => _connection;
  List<WhatsAppProviderDefinitionModel> get providers => _providers;
  List<WhatsAppMessageModel> get messages => _messages;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  WhatsAppService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch active tenant WhatsApp connection and available provider definitions.
  Future<void> fetchConnection() async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.get<dynamic>('/api/v1/whatsapp/connection');
      if (res.isSuccess && res.data is Map<String, dynamic>) {
        final data = res.data as Map<String, dynamic>;

        if (data['connections'] != null && data['connections'] is List) {
          final list = (data['connections'] as List)
              .map((c) => WhatsAppConnectionModel.fromJson(c as Map<String, dynamic>))
              .toList();
          _connection = list.isNotEmpty ? list.first : null;
        } else if (data['connection'] != null && data['connection'] is Map<String, dynamic>) {
          _connection = WhatsAppConnectionModel.fromJson(data['connection'] as Map<String, dynamic>);
        } else {
          _connection = null;
        }

        if (data['available_providers'] != null && data['available_providers'] is List) {
          _providers = (data['available_providers'] as List)
              .map((p) => WhatsAppProviderDefinitionModel.fromJson(p as Map<String, dynamic>))
              .toList();
        } else if (data['providers'] != null && data['providers'] is List) {
          _providers = (data['providers'] as List)
              .map((p) => WhatsAppProviderDefinitionModel.fromJson(p as Map<String, dynamic>))
              .toList();
        }
      } else {
        _setError(res.message ?? 'Gagal memuat koneksi WhatsApp');
      }
    } catch (e) {
      _setError('Error loading WhatsApp connection: $e');
    } finally {
      _setLoading(false);
    }
  }

  /// Save or update tenant WhatsApp connection credentials and settings.
  Future<bool> saveConnection({
    required String provider,
    required String environment,
    required Map<String, dynamic> credentials,
    String? phoneNumberId,
    String? businessAccountId,
    String status = 'active',
    bool isDefault = true,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.post<dynamic>(
        '/api/v1/whatsapp/connection',
        body: {
          'provider': provider,
          'environment': environment,
          'credentials': credentials,
          'phone_number_id': phoneNumberId,
          'business_account_id': businessAccountId,
          'status': status,
          'is_default': isDefault,
        },
      );

      if (res.isSuccess) {
        await fetchConnection();
        return true;
      } else {
        _setError(res.message ?? 'Gagal menyimpan konfigurasi WhatsApp');
        return false;
      }
    } catch (e) {
      _setError('Error saving WhatsApp connection: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Safe, non-destructive connection verification.
  Future<Map<String, dynamic>?> verifyConnection() async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.post<dynamic>('/api/v1/whatsapp/connection/verify');
      if (res.isSuccess) {
        await fetchConnection();
        return (res.data is Map<String, dynamic>)
            ? (res.data as Map<String, dynamic>)
            : {'verified': true};
      } else {
        _setError(res.message ?? 'Verifikasi koneksi gagal');
        return null;
      }
    } catch (e) {
      _setError('Error verifying connection: $e');
      return null;
    } finally {
      _setLoading(false);
    }
  }

  /// Send test message using active connection.
  Future<bool> sendTestMessage(String recipientPhone) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.post<dynamic>(
        '/api/v1/whatsapp/connection/test',
        body: {'recipient_phone': recipientPhone},
      );

      if (res.isSuccess) {
        return true;
      } else {
        _setError(res.message ?? 'Gagal mengirim pesan uji coba');
        return false;
      }
    } catch (e) {
      _setError('Error sending test message: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch audit list of WhatsApp messages.
  Future<void> fetchMessages({String? status, int page = 1}) async {
    _setLoading(true);
    _setError(null);

    try {
      final endpoint = status != null
          ? '/api/v1/whatsapp/messages?status=$status&page=$page'
          : '/api/v1/whatsapp/messages?page=$page';
      final res = await apiClient.get<dynamic>(endpoint);

      if (res.isSuccess) {
        List items = [];
        if (res.data is Map && res.data['data'] is List) {
          items = res.data['data'] as List;
        } else if (res.data is List) {
          items = res.data as List;
        }

        _messages = items
            .map((m) => WhatsAppMessageModel.fromJson(m as Map<String, dynamic>))
            .toList();
      } else {
        _setError(res.message ?? 'Gagal memuat riwayat pesan WhatsApp');
      }
    } catch (e) {
      _setError('Error loading WhatsApp messages: $e');
    } finally {
      _setLoading(false);
    }
  }

  /// Manual resend for authorized role.
  Future<bool> resendMessage(String messageId) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.post<dynamic>('/api/v1/whatsapp/messages/$messageId/resend');
      if (res.isSuccess) {
        await fetchMessages();
        return true;
      } else {
        _setError(res.message ?? 'Gagal mengirim ulang pesan');
        return false;
      }
    } catch (e) {
      _setError('Error resending message: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Send receipt via WhatsApp for a completed sale.
  Future<bool> sendReceipt({required String saleId, String? phone}) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.post<dynamic>(
        '/api/v1/whatsapp/receipt/$saleId/send',
        body: phone != null && phone.isNotEmpty ? {'phone': phone} : {},
      );

      if (res.isSuccess) {
        return true;
      } else {
        _setError(res.message ?? 'Gagal mengirim nota ke WhatsApp');
        return false;
      }
    } catch (e) {
      _setError('Error sending receipt to WhatsApp: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }
}
