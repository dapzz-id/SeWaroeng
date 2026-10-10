class AppNotification {
  final String id;
  final String type;
  final String title;
  final String message;
  final String severity;
  final Map<String, dynamic>? data;
  final DateTime? readAt;
  final DateTime? resolvedAt;
  final DateTime? createdAt;

  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.message,
    this.severity = 'info',
    this.data,
    this.readAt,
    this.resolvedAt,
    this.createdAt,
  });

  bool get isRead => readAt != null;
  bool get isResolved => resolvedAt != null;

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    return AppNotification(
      id: json['id'] as String,
      type: json['type'] as String? ?? 'info',
      title: json['title'] as String? ?? '',
      message: json['message'] as String? ?? '',
      severity: json['severity'] as String? ?? 'info',
      data: json['data'] != null ? Map<String, dynamic>.from(json['data'] as Map) : null,
      readAt: json['read_at'] != null ? DateTime.tryParse(json['read_at'] as String) : null,
      resolvedAt: json['resolved_at'] != null ? DateTime.tryParse(json['resolved_at'] as String) : null,
      createdAt: json['created_at'] != null ? DateTime.tryParse(json['created_at'] as String) : null,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'type': type,
      'title': title,
      'message': message,
      'severity': severity,
      'data': data,
      'read_at': readAt?.toIso8601String(),
      'resolved_at': resolvedAt?.toIso8601String(),
      'created_at': createdAt?.toIso8601String(),
    };
  }
}
