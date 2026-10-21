class WhatsAppConnectionModel {
  final String id;
  final String tenantId;
  final String provider;
  final String environment;
  final String status;
  final bool isDefault;
  final String? phoneNumberId;
  final String? businessAccountId;
  final String verificationStatus;
  final Map<String, dynamic> maskedCredentials;
  final Map<String, dynamic>? configuration;
  final DateTime? verifiedAt;
  final String? lastErrorCode;
  final String? lastErrorMessage;

  const WhatsAppConnectionModel({
    required this.id,
    required this.tenantId,
    required this.provider,
    required this.environment,
    required this.status,
    required this.isDefault,
    this.phoneNumberId,
    this.businessAccountId,
    required this.verificationStatus,
    required this.maskedCredentials,
    this.configuration,
    this.verifiedAt,
    this.lastErrorCode,
    this.lastErrorMessage,
  });

  bool get isVerified => verificationStatus == 'verified';
  bool get isActive => status == 'active';

  factory WhatsAppConnectionModel.fromJson(Map<String, dynamic> json) {
    return WhatsAppConnectionModel(
      id: json['id'] as String? ?? '',
      tenantId: json['tenant_id'] as String? ?? '',
      provider: json['provider'] as String? ?? '',
      environment: json['environment'] as String? ?? 'sandbox',
      status: json['status'] as String? ?? 'active',
      isDefault: json['is_default'] as bool? ?? false,
      phoneNumberId: json['phone_number_id'] as String?,
      businessAccountId: json['business_account_id'] as String?,
      verificationStatus: json['verification_status'] as String? ?? 'unverified',
      maskedCredentials: json['masked_credentials'] != null
          ? Map<String, dynamic>.from(json['masked_credentials'] as Map)
          : {},
      configuration: json['configuration'] != null
          ? Map<String, dynamic>.from(json['configuration'] as Map)
          : null,
      verifiedAt: json['verified_at'] != null
          ? DateTime.tryParse(json['verified_at'] as String)
          : null,
      lastErrorCode: json['last_error_code'] as String?,
      lastErrorMessage: json['last_error_message'] as String?,
    );
  }
}

class WhatsAppProviderDefinitionModel {
  final String id;
  final String provider;
  final String displayName;
  final bool isEnabled;
  final Map<String, dynamic>? capabilities;

  const WhatsAppProviderDefinitionModel({
    required this.id,
    required this.provider,
    required this.displayName,
    required this.isEnabled,
    this.capabilities,
  });

  factory WhatsAppProviderDefinitionModel.fromJson(Map<String, dynamic> json) {
    return WhatsAppProviderDefinitionModel(
      id: json['id'] as String? ?? '',
      provider: json['provider'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      isEnabled: json['is_enabled'] as bool? ?? true,
      capabilities: json['capabilities'] != null
          ? Map<String, dynamic>.from(json['capabilities'] as Map)
          : null,
    );
  }
}

class WhatsAppMessageModel {
  final String id;
  final String recipientPhone;
  final String messageType;
  final String status;
  final String provider;
  final String? providerMessageId;
  final DateTime? createdAt;
  final DateTime? sentAt;
  final DateTime? deliveredAt;
  final String? errorMessage;

  const WhatsAppMessageModel({
    required this.id,
    required this.recipientPhone,
    required this.messageType,
    required this.status,
    required this.provider,
    this.providerMessageId,
    this.createdAt,
    this.sentAt,
    this.deliveredAt,
    this.errorMessage,
  });

  factory WhatsAppMessageModel.fromJson(Map<String, dynamic> json) {
    return WhatsAppMessageModel(
      id: json['id'] as String? ?? '',
      recipientPhone: json['recipient_phone'] as String? ?? '',
      messageType: json['message_type'] as String? ?? '',
      status: json['status'] as String? ?? 'pending',
      provider: json['provider'] as String? ?? '',
      providerMessageId: json['provider_message_id'] as String?,
      createdAt: json['created_at'] != null
          ? DateTime.tryParse(json['created_at'] as String)
          : null,
      sentAt: json['sent_at'] != null
          ? DateTime.tryParse(json['sent_at'] as String)
          : null,
      deliveredAt: json['delivered_at'] != null
          ? DateTime.tryParse(json['delivered_at'] as String)
          : null,
      errorMessage: json['error_message'] as String?,
    );
  }
}

class WhatsAppPreferencesModel {
  final String? merchantPhone;
  final bool dailySalesSummary;
  final bool lowStock;
  final bool bookingReminder;
  final bool paymentSettlement;
  final bool anomalyAlert;

  const WhatsAppPreferencesModel({
    this.merchantPhone,
    required this.dailySalesSummary,
    required this.lowStock,
    required this.bookingReminder,
    required this.paymentSettlement,
    required this.anomalyAlert,
  });

  factory WhatsAppPreferencesModel.fromJson(Map<String, dynamic> json) {
    return WhatsAppPreferencesModel(
      merchantPhone: json['merchant_phone'] as String?,
      dailySalesSummary: json['daily_sales_summary'] as bool? ?? true,
      lowStock: json['low_stock'] as bool? ?? true,
      bookingReminder: json['booking_reminder'] as bool? ?? true,
      paymentSettlement: json['payment_settlement'] as bool? ?? true,
      anomalyAlert: json['anomaly_alert'] as bool? ?? true,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'merchant_phone': merchantPhone,
      'daily_sales_summary': dailySalesSummary,
      'low_stock': lowStock,
      'booking_reminder': bookingReminder,
      'payment_settlement': paymentSettlement,
      'anomaly_alert': anomalyAlert,
    };
  }
}

class WhatsAppBusinessQueryResultModel {
  final String intent;
  final bool success;
  final String replyText;
  final Map<String, dynamic> data;

  const WhatsAppBusinessQueryResultModel({
    required this.intent,
    required this.success,
    required this.replyText,
    required this.data,
  });

  factory WhatsAppBusinessQueryResultModel.fromJson(Map<String, dynamic> json) {
    return WhatsAppBusinessQueryResultModel(
      intent: json['intent'] as String? ?? 'unsupported_query',
      success: json['success'] as bool? ?? false,
      replyText: json['reply_text'] as String? ?? '',
      data: json['data'] != null ? Map<String, dynamic>.from(json['data'] as Map) : {},
    );
  }
}

