import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'ai_copilot_models.dart';

class AiCopilotService extends ChangeNotifier {
  static final _uuid = const Uuid();

  final ApiClient apiClient;

  final List<CopilotChatMessage> _messages = [];
  bool _isLoading = false;
  String? _errorMessage;

  List<CopilotChatMessage> get messages => _messages;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  AiCopilotService({required this.apiClient}) {
    // Add initial assistant greeting
    _messages.add(
      CopilotChatMessage(
        text: 'Halo! Saya AI Business Copilot SeWaroeng.\n'
            'Anda dapat menanyakan analisa bisnis harian, seperti:\n'
            '• "Omzet hari ini?"\n'
            '• "Produk paling laku minggu ini?"\n'
            '• "Cek stok kopi?"',
        isUser: false,
        timestamp: DateTime.now(),
      ),
    );
  }

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Send business question to AI Copilot
  Future<void> askQuestion(String query) async {
    final trimmed = query.trim();
    if (trimmed.isEmpty) return;

    // Add user message immediately
    _messages.add(
      CopilotChatMessage(
        text: trimmed,
        isUser: true,
        timestamp: DateTime.now(),
      ),
    );
    notifyListeners();

    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/ai/copilot',
        idempotencyKey: _uuid.v7(),
        body: {'query': trimmed},
      );

      if (response.isSuccess && response.data != null) {
        final copilotData = CopilotQueryResponse.fromJson(response.data!);
        _messages.add(
          CopilotChatMessage(
            text: copilotData.replyText,
            isUser: false,
            timestamp: DateTime.now(),
            responseData: copilotData,
          ),
        );
      } else {
        final err = response.message ?? 'Maaf, terjadi kendala memproses pertanyaan.';
        _setError(err);
        _messages.add(
          CopilotChatMessage(
            text: err,
            isUser: false,
            timestamp: DateTime.now(),
          ),
        );
      }
    } catch (e) {
      _setError(e.toString());
      _messages.add(
        CopilotChatMessage(
          text: 'Terjadi kesalahan jaringan atau server: $e',
          isUser: false,
          timestamp: DateTime.now(),
        ),
      );
    } finally {
      _setLoading(false);
    }
  }

  void clearHistory() {
    _messages.clear();
    _messages.add(
      CopilotChatMessage(
        text: 'Riwayat percakapan telah dibersihkan. Ada yang ingin Anda tanyakan lagi?',
        isUser: false,
        timestamp: DateTime.now(),
      ),
    );
    notifyListeners();
  }
}
