class CategoryModel {
  final String id;
  final String tenantId;
  final String? parentId;
  final String name;
  final String slug;
  final int sortOrder;
  final String status;
  final CategoryModel? parent;
  final List<CategoryModel>? children;

  const CategoryModel({
    required this.id,
    required this.tenantId,
    this.parentId,
    required this.name,
    required this.slug,
    this.sortOrder = 0,
    this.status = 'active',
    this.parent,
    this.children,
  });

  bool get isActive => status == 'active';

  factory CategoryModel.fromJson(Map<String, dynamic> json) {
    return CategoryModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      parentId: json['parent_id'] as String?,
      name: json['name'] as String? ?? '',
      slug: json['slug'] as String? ?? '',
      sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
      status: json['status'] as String? ?? 'active',
      parent: json['parent'] != null
          ? CategoryModel.fromJson(json['parent'] as Map<String, dynamic>)
          : null,
      children: json['children'] != null
          ? (json['children'] as List<dynamic>)
                .map((c) => CategoryModel.fromJson(c as Map<String, dynamic>))
                .toList()
          : null,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'parent_id': parentId,
      'name': name,
      'slug': slug,
      'sort_order': sortOrder,
      'status': status,
    };
  }

  CategoryModel copyWith({
    String? id,
    String? tenantId,
    String? parentId,
    String? name,
    String? slug,
    int? sortOrder,
    String? status,
    CategoryModel? parent,
    List<CategoryModel>? children,
  }) {
    return CategoryModel(
      id: id ?? this.id,
      tenantId: tenantId ?? this.tenantId,
      parentId: parentId ?? this.parentId,
      name: name ?? this.name,
      slug: slug ?? this.slug,
      sortOrder: sortOrder ?? this.sortOrder,
      status: status ?? this.status,
      parent: parent ?? this.parent,
      children: children ?? this.children,
    );
  }
}
