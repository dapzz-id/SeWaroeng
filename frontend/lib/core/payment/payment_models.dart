class PaymentProviderDefinition {
  final String id;
  final String provider;
  final String displayName;
  final bool isEnabled;
  final List<String> supportedMethods;
  final Map<String, dynamic> capabilities;

  PaymentProviderDefinition({
    required this.id,
    required this.provider,
    required this.displayName,
    required this.isEnabled,
    required this.supportedMethods,
    required this.capabilities,
  });

  factory PaymentProviderDefinition.fromJson(Map<String, dynamic> json) {
    return PaymentProviderDefinition(
      id: json['id'] as String? ?? '',
      provider: json['provider'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      isEnabled: json['is_enabled'] as bool? ?? true,
      supportedMethods: (json['supported_methods'] as List<dynamic>?)
              ?.map((e) => e.toString())
              .toList() ??
          [],
      capabilities: (json['capabilities'] as Map<String, dynamic>?) ?? {},
    );
  }
}

class TenantPaymentConnection {
  final String id;
  final String tenantId;
  final String provider;
  final String environment;
  final String status;
  final bool isDefault;
  final String merchantIdentifier;
  final Map<String, String> maskedCredentials;
  final String verificationStatus;
  final DateTime? verifiedAt;
  final String? lastErrorCode;
  final String? lastErrorMessage;

  TenantPaymentConnection({
    required this.id,
    required this.tenantId,
    required this.provider,
    required this.environment,
    required this.status,
    required this.isDefault,
    required this.merchantIdentifier,
    required this.maskedCredentials,
    required this.verificationStatus,
    this.verifiedAt,
    this.lastErrorCode,
    this.lastErrorMessage,
  });

  factory TenantPaymentConnection.fromJson(Map<String, dynamic> json) {
    return TenantPaymentConnection(
      id: json['id'] as String? ?? '',
      tenantId: json['tenant_id'] as String? ?? '',
      provider: json['provider'] as String? ?? '',
      environment: json['environment'] as String? ?? 'sandbox',
      status: json['status'] as String? ?? 'active',
      isDefault: json['is_default'] as bool? ?? false,
      merchantIdentifier: json['merchant_identifier'] as String? ?? '',
      maskedCredentials: (json['masked_credentials'] as Map<String, dynamic>?)
              ?.map((k, v) => MapEntry(k, v.toString())) ??
          {},
      verificationStatus: json['verification_status'] as String? ?? 'unverified',
      verifiedAt: json['verified_at'] != null
          ? DateTime.tryParse(json['verified_at'].toString())
          : null,
      lastErrorCode: json['last_error_code'] as String?,
      lastErrorMessage: json['last_error_message'] as String?,
    );
  }
}

class NormalizedPaymentResponse {
  final String status;
  final String? providerReference;
  final String? paymentUrl;
  final String? vaNumber;
  final String? qrString;
  final String? appUrl;
  final DateTime? expiresAt;
  final String? responseCode;
  final String? statusMessage;

  NormalizedPaymentResponse({
    required this.status,
    this.providerReference,
    this.paymentUrl,
    this.vaNumber,
    this.qrString,
    this.appUrl,
    this.expiresAt,
    this.responseCode,
    this.statusMessage,
  });

  factory NormalizedPaymentResponse.fromResponsePayload(
    String status,
    String? providerReference,
    String? responseCode,
    Map<String, dynamic>? payload,
  ) {
    return NormalizedPaymentResponse(
      status: status,
      providerReference: providerReference ?? payload?['reference'] as String?,
      paymentUrl: payload?['payment_url'] as String?,
      vaNumber: payload?['va_number'] as String?,
      qrString: payload?['qr_string'] as String?,
      appUrl: payload?['app_url'] as String?,
      expiresAt: payload?['expires_at'] != null
          ? DateTime.tryParse(payload!['expires_at'].toString())
          : null,
      responseCode: responseCode ?? payload?['response_code'] as String?,
      statusMessage: payload?['status_message'] as String?,
    );
  }
}
