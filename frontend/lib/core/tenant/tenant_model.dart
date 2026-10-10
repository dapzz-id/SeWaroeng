class TenantModel {
  final String id;
  final String name;
  final String slug;
  final String businessType;
  final String status;
  final String? phone;
  final String? email;
  final String? description;
  final String? addressLine;
  final String? city;
  final String? province;
  final String? postalCode;
  final String countryCode;
  final double? latitude;
  final double? longitude;
  final String currency;
  final String timezone;
  final String? role;

  const TenantModel({
    required this.id,
    required this.name,
    required this.slug,
    this.businessType = 'fnb',
    this.status = 'active',
    this.phone,
    this.email,
    this.description,
    this.addressLine,
    this.city,
    this.province,
    this.postalCode,
    this.countryCode = 'ID',
    this.latitude,
    this.longitude,
    this.currency = 'IDR',
    this.timezone = 'Asia/Jakarta',
    this.role,
  });

  factory TenantModel.fromJson(Map<String, dynamic> json) {
    return TenantModel(
      id: json['id'] as String,
      name: json['name'] as String,
      slug: (json['slug'] ?? json['code'] ?? '') as String,
      businessType: (json['business_type'] ?? 'fnb') as String,
      status: (json['status'] ?? 'active') as String,
      phone: json['phone'] as String?,
      email: json['email'] as String?,
      description: json['description'] as String?,
      addressLine: json['address_line'] as String?,
      city: json['city'] as String?,
      province: json['province'] as String?,
      postalCode: json['postal_code'] as String?,
      countryCode: (json['country_code'] ?? 'ID') as String,
      latitude: json['latitude'] != null
          ? (json['latitude'] as num).toDouble()
          : null,
      longitude: json['longitude'] != null
          ? (json['longitude'] as num).toDouble()
          : null,
      currency: (json['currency'] ?? 'IDR') as String,
      timezone: (json['timezone'] ?? 'Asia/Jakarta') as String,
      role: json['role'] as String?,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'slug': slug,
      'business_type': businessType,
      'status': status,
      if (phone != null) 'phone': phone,
      if (email != null) 'email': email,
      if (description != null) 'description': description,
      if (addressLine != null) 'address_line': addressLine,
      if (city != null) 'city': city,
      if (province != null) 'province': province,
      if (postalCode != null) 'postal_code': postalCode,
      'country_code': countryCode,
      if (latitude != null) 'latitude': latitude,
      if (longitude != null) 'longitude': longitude,
      'currency': currency,
      'timezone': timezone,
      if (role != null) 'role': role,
    };
  }

  TenantModel copyWith({
    String? name,
    String? slug,
    String? businessType,
    String? status,
    String? phone,
    String? email,
    String? description,
    String? addressLine,
    String? city,
    String? province,
    String? postalCode,
    String? countryCode,
    double? latitude,
    double? longitude,
    String? currency,
    String? timezone,
    String? role,
  }) {
    return TenantModel(
      id: id,
      name: name ?? this.name,
      slug: slug ?? this.slug,
      businessType: businessType ?? this.businessType,
      status: status ?? this.status,
      phone: phone ?? this.phone,
      email: email ?? this.email,
      description: description ?? this.description,
      addressLine: addressLine ?? this.addressLine,
      city: city ?? this.city,
      province: province ?? this.province,
      postalCode: postalCode ?? this.postalCode,
      countryCode: countryCode ?? this.countryCode,
      latitude: latitude ?? this.latitude,
      longitude: longitude ?? this.longitude,
      currency: currency ?? this.currency,
      timezone: timezone ?? this.timezone,
      role: role ?? this.role,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is TenantModel &&
          runtimeType == other.runtimeType &&
          id == other.id;

  @override
  int get hashCode => id.hashCode;
}
