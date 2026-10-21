class CopilotQueryResponse {
  final String intent;
  final bool success;
  final String replyText;
  final Map<String, dynamic> data;
  final String source;
  final String period;

  const CopilotQueryResponse({
    required this.intent,
    required this.success,
    required this.replyText,
    this.data = const {},
    required this.source,
    required this.period,
  });

  factory CopilotQueryResponse.fromJson(Map<String, dynamic> json) {
    return CopilotQueryResponse(
      intent: json['intent'] as String? ?? 'unknown',
      success: json['success'] as bool? ?? false,
      replyText: json['reply_text'] as String? ?? '',
      data: json['data'] is Map<String, dynamic>
          ? json['data'] as Map<String, dynamic>
          : const {},
      source: json['source'] as String? ?? 'SeWaroeng Analytics',
      period: json['period'] as String? ?? 'Hari ini',
    );
  }
}

class CopilotChatMessage {
  final String text;
  final bool isUser;
  final DateTime timestamp;
  final CopilotQueryResponse? responseData;

  CopilotChatMessage({
    required this.text,
    required this.isUser,
    required this.timestamp,
    this.responseData,
  });
}
