class EmployeeRoleModel {
  final String id;
  final String name;
  final String slug;
  final String? description;
  final List<String> permissions;

  const EmployeeRoleModel({
    required this.id,
    required this.name,
    required this.slug,
    this.description,
    this.permissions = const [],
  });

  factory EmployeeRoleModel.fromJson(Map<String, dynamic> json) {
    return EmployeeRoleModel(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      slug: json['slug'] as String? ?? '',
      description: json['description'] as String?,
      permissions: (json['permissions'] as List<dynamic>?)
              ?.map((e) => e.toString())
              .toList() ??
          const [],
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'slug': slug,
        'description': description,
        'permissions': permissions,
      };
}

class PermittedOutletModel {
  final String id;
  final String name;

  const PermittedOutletModel({required this.id, required this.name});

  factory PermittedOutletModel.fromJson(Map<String, dynamic> json) {
    return PermittedOutletModel(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
    );
  }

  Map<String, dynamic> toJson() => {'id': id, 'name': name};
}

class EmployeeModel {
  final String id;
  final String userId;
  final String name;
  final String email;
  final String? phoneNumber;
  final EmployeeRoleModel role;
  final String status;
  final String outletAccess;
  final List<String> assignedOutletIds;
  final List<PermittedOutletModel> permittedOutlets;
  final String? createdAt;

  const EmployeeModel({
    required this.id,
    required this.userId,
    required this.name,
    required this.email,
    this.phoneNumber,
    required this.role,
    this.status = 'active',
    this.outletAccess = 'all',
    this.assignedOutletIds = const [],
    this.permittedOutlets = const [],
    this.createdAt,
  });

  bool get isActive => status == 'active';
  bool get isOwner => role.slug == 'owner';
  bool get isManager => role.slug == 'manager';
  bool get isCashier => role.slug == 'cashier';

  factory EmployeeModel.fromJson(Map<String, dynamic> json) {
    return EmployeeModel(
      id: json['id'] as String? ?? '',
      userId: json['user_id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      email: json['email'] as String? ?? '',
      phoneNumber: json['phone_number'] as String?,
      role: json['role'] is Map<String, dynamic>
          ? EmployeeRoleModel.fromJson(json['role'] as Map<String, dynamic>)
          : const EmployeeRoleModel(id: '', name: 'Staff', slug: 'staff'),
      status: json['status'] as String? ?? 'active',
      outletAccess: json['outlet_access'] as String? ?? 'all',
      assignedOutletIds: (json['assigned_outlet_ids'] as List<dynamic>?)
              ?.map((e) => e.toString())
              .toList() ??
          const [],
      permittedOutlets: (json['permitted_outlets'] as List<dynamic>?)
              ?.map((e) =>
                  PermittedOutletModel.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
      createdAt: json['created_at'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'user_id': userId,
        'name': name,
        'email': email,
        'phone_number': phoneNumber,
        'role': role.toJson(),
        'status': status,
        'outlet_access': outletAccess,
        'assigned_outlet_ids': assignedOutletIds,
        'permitted_outlets': permittedOutlets.map((o) => o.toJson()).toList(),
        'created_at': createdAt,
      };
}
