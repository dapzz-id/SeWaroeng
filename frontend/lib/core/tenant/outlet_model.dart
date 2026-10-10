class OutletModel {
  final String id;
  final String tenantId;
  final String name;
  final String code;
  final String status;
  final String? address;
  final String? addressLine;
  final String? city;
  final String? province;
  final String? postalCode;
  final String? phoneNumber;
  final double? latitude;
  final double? longitude;
  final String? timezone;
  final String? effectiveTimezone;
  final Map<String, dynamic>? operatingHours;
  final Map<String, dynamic>? settings;

  const OutletModel({
    required this.id,
    required this.tenantId,
    required this.name,
    required this.code,
    this.status = 'active',
    this.address,
    this.addressLine,
    this.city,
    this.province,
    this.postalCode,
    String? phone,
    String? phoneNumber,
    this.latitude,
    this.longitude,
    this.timezone,
    this.effectiveTimezone,
    this.operatingHours,
    this.settings,
  }) : phoneNumber = phoneNumber ?? phone;

  bool get isActive => status == 'active';
  String? get phone => phoneNumber;

  factory OutletModel.fromJson(Map<String, dynamic> json) {
    return OutletModel(
      id: json['id'] as String,
      tenantId: (json['tenant_id'] ?? '') as String,
      name: json['name'] as String,
      code: json['code'] as String,
      status: (json['status'] ?? 'active') as String,
      address: json['address'] as String?,
      addressLine: json['address_line'] as String?,
      city: json['city'] as String?,
      province: json['province'] as String?,
      postalCode: json['postal_code'] as String?,
      phoneNumber: (json['phone'] ?? json['phone_number']) as String?,
      latitude: json['latitude'] != null
          ? (json['latitude'] as num).toDouble()
          : null,
      longitude: json['longitude'] != null
          ? (json['longitude'] as num).toDouble()
          : null,
      timezone: json['timezone'] as String?,
      effectiveTimezone: json['effective_timezone'] as String?,
      operatingHours: json['operating_hours'] as Map<String, dynamic>?,
      settings: json['settings'] as Map<String, dynamic>?,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'name': name,
      'code': code,
      'status': status,
      if (address != null) 'address': address,
      if (addressLine != null) 'address_line': addressLine,
      if (city != null) 'city': city,
      if (province != null) 'province': province,
      if (postalCode != null) 'postal_code': postalCode,
      if (phoneNumber != null) 'phone_number': phoneNumber,
      if (latitude != null) 'latitude': latitude,
      if (longitude != null) 'longitude': longitude,
      if (timezone != null) 'timezone': timezone,
      if (effectiveTimezone != null) 'effective_timezone': effectiveTimezone,
      if (operatingHours != null) 'operating_hours': operatingHours,
      if (settings != null) 'settings': settings,
    };
  }

  OutletModel copyWith({
    String? name,
    String? code,
    String? status,
    String? address,
    String? addressLine,
    String? city,
    String? province,
    String? postalCode,
    String? phoneNumber,
    double? latitude,
    double? longitude,
    String? timezone,
    String? effectiveTimezone,
    Map<String, dynamic>? operatingHours,
    Map<String, dynamic>? settings,
  }) {
    return OutletModel(
      id: id,
      tenantId: tenantId,
      name: name ?? this.name,
      code: code ?? this.code,
      status: status ?? this.status,
      address: address ?? this.address,
      addressLine: addressLine ?? this.addressLine,
      city: city ?? this.city,
      province: province ?? this.province,
      postalCode: postalCode ?? this.postalCode,
      phoneNumber: phoneNumber ?? this.phoneNumber,
      latitude: latitude ?? this.latitude,
      longitude: longitude ?? this.longitude,
      timezone: timezone ?? this.timezone,
      effectiveTimezone: effectiveTimezone ?? this.effectiveTimezone,
      operatingHours: operatingHours ?? this.operatingHours,
      settings: settings ?? this.settings,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is OutletModel &&
          runtimeType == other.runtimeType &&
          id == other.id;

  @override
  int get hashCode => id.hashCode;
}
