import 'package:flutter/material.dart';
import '../../core/employee/employee_models.dart';
import '../../core/employee/employee_service.dart';
import '../../core/tenant/tenant_service.dart';

class EmployeeManagementScreen extends StatefulWidget {
  final EmployeeService employeeService;
  final TenantService tenantService;

  const EmployeeManagementScreen({
    super.key,
    required this.employeeService,
    required this.tenantService,
  });

  @override
  State<EmployeeManagementScreen> createState() =>
      _EmployeeManagementScreenState();
}

class _EmployeeManagementScreenState extends State<EmployeeManagementScreen> {
  final _searchController = TextEditingController();
  String? _selectedRoleFilter;

  @override
  void initState() {
    super.initState();
    widget.employeeService.addListener(_onServiceChanged);
    _loadData();
  }

  @override
  void dispose() {
    widget.employeeService.removeListener(_onServiceChanged);
    _searchController.dispose();
    super.dispose();
  }

  void _onServiceChanged() {
    if (mounted) setState(() {});
  }

  Future<void> _loadData() async {
    await widget.employeeService.fetchRoles();
    await widget.employeeService.fetchEmployees();
  }

  void _applyFilter() {
    widget.employeeService.fetchEmployees(
      role: _selectedRoleFilter,
      search: _searchController.text.trim(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final service = widget.employeeService;
    final employees = service.employees;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Manajemen Karyawan'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadData,
            tooltip: 'Segarkan',
          ),
        ],
      ),
      body: Column(
        children: [
          if (service.errorMessage != null)
            Container(
              color: Colors.red.shade50,
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Icon(Icons.error_outline, color: Colors.red.shade700),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      service.errorMessage!,
                      style: TextStyle(color: Colors.red.shade900),
                    ),
                  ),
                ],
              ),
            ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _searchController,
                    decoration: InputDecoration(
                      hintText: 'Cari nama, email, telepon...',
                      prefixIcon: const Icon(Icons.search),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                      isDense: true,
                    ),
                    onSubmitted: (_) => _applyFilter(),
                  ),
                ),
                const SizedBox(width: 12),
                DropdownButton<String?>(
                  value: _selectedRoleFilter,
                  hint: const Text('Semua Peran'),
                  items: [
                    const DropdownMenuItem(
                      value: null,
                      child: Text('Semua Peran'),
                    ),
                    ...service.availableRoles.map(
                      (r) => DropdownMenuItem(
                        value: r.slug,
                        child: Text(r.name),
                      ),
                    ),
                  ],
                  onChanged: (val) {
                    setState(() => _selectedRoleFilter = val);
                    _applyFilter();
                  },
                ),
              ],
            ),
          ),
          Expanded(
            child: service.isLoading && employees.isEmpty
                ? const Center(child: CircularProgressIndicator())
                : employees.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.people_outline,
                                size: 64, color: Colors.grey.shade400),
                            const SizedBox(height: 16),
                            const Text(
                              'Belum ada karyawan terdaftar',
                              style: TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Tambahkan kasir, manager, atau staf untuk outlet Anda.',
                              style: TextStyle(color: Colors.grey),
                            ),
                          ],
                        ),
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        itemCount: employees.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 12),
                        itemBuilder: (context, index) {
                          final emp = employees[index];
                          return _buildEmployeeCard(emp);
                        },
                      ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _showAddEmployeeDialog,
        icon: const Icon(Icons.person_add),
        label: const Text('Tambah Karyawan'),
      ),
    );
  }

  Widget _buildEmployeeCard(EmployeeModel emp) {
    Color roleColor;
    switch (emp.role.slug) {
      case 'owner':
        roleColor = Colors.purple;
        break;
      case 'manager':
        roleColor = Colors.blue;
        break;
      case 'cashier':
        roleColor = Colors.teal;
        break;
      default:
        roleColor = Colors.orange;
    }

    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  backgroundColor: roleColor.withValues(alpha: 0.15),
                  child: Text(
                    emp.name.isNotEmpty ? emp.name[0].toUpperCase() : '?',
                    style: TextStyle(
                      color: roleColor,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        emp.name,
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      Text(
                        emp.email,
                        style: TextStyle(
                          color: Colors.grey.shade600,
                          fontSize: 13,
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: roleColor.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: roleColor),
                  ),
                  child: Text(
                    emp.role.name,
                    style: TextStyle(
                      color: roleColor,
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
            ),
            const Divider(height: 24),
            Row(
              children: [
                Icon(Icons.storefront, size: 16, color: Colors.grey.shade600),
                const SizedBox(width: 6),
                Text(
                  emp.outletAccess == 'all'
                      ? 'Akses: Semua Outlet'
                      : 'Akses: ${emp.permittedOutlets.map((o) => o.name).join(", ")}',
                  style: TextStyle(fontSize: 13, color: Colors.grey.shade700),
                ),
                const Spacer(),
                if (emp.status != 'active')
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.red.shade100,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: const Text(
                      'Non-aktif',
                      style: TextStyle(color: Colors.red, fontSize: 11),
                    ),
                  ),
                if (!emp.isOwner) ...[
                  IconButton(
                    icon: const Icon(Icons.edit_outlined, size: 20),
                    onPressed: () => _showEditEmployeeDialog(emp),
                    tooltip: 'Ubah Data',
                  ),
                  IconButton(
                    icon: const Icon(Icons.delete_outline,
                        size: 20, color: Colors.red),
                    onPressed: () => _confirmRemoveEmployee(emp),
                    tooltip: 'Hapus Karyawan',
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }

  void _showAddEmployeeDialog() {
    final nameCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final passCtrl = TextEditingController();
    String selectedRole = 'cashier';
    String outletAccess = 'all';
    final selectedOutletIds = <String>{};

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Text('Tambah Karyawan Baru'),
          content: SingleChildScrollView(
            child: SizedBox(
              width: 400,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  TextField(
                    controller: nameCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Nama Lengkap *',
                      isDense: true,
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: emailCtrl,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(
                      labelText: 'Email *',
                      isDense: true,
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: phoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'Nomor WhatsApp / Telepon',
                      isDense: true,
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: passCtrl,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Kata Sandi Awal (opsional, min. 8 karakter)',
                      isDense: true,
                    ),
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String>(
                    initialValue: selectedRole,
                    decoration: const InputDecoration(
                      labelText: 'Peran / Hak Akses *',
                      isDense: true,
                    ),
                    items: widget.employeeService.availableRoles
                        .where((r) => r.slug != 'owner')
                        .map((r) => DropdownMenuItem(
                              value: r.slug,
                              child: Text(r.name),
                            ))
                        .toList(),
                    onChanged: (val) {
                      if (val != null) {
                        setDialogState(() => selectedRole = val);
                      }
                    },
                  ),
                  const SizedBox(height: 16),
                  const Text('Akses Outlet:',
                      style: TextStyle(fontWeight: FontWeight.bold)),
                  RadioGroup<String>(
                    groupValue: outletAccess,
                    onChanged: (val) {
                      if (val != null) setDialogState(() => outletAccess = val);
                    },
                    child: Column(
                      children: [
                        const RadioListTile<String>(
                          title: Text('Semua Outlet'),
                          value: 'all',
                        ),
                        const RadioListTile<String>(
                          title: Text('Outlet Tertentu'),
                          value: 'specific',
                        ),
                      ],
                    ),
                  ),
                  if (outletAccess == 'specific') ...[
                    const SizedBox(height: 8),
                    ...widget.tenantService.outlets.map((outlet) {
                      return CheckboxListTile(
                        title: Text(outlet.name),
                        value: selectedOutletIds.contains(outlet.id),
                        onChanged: (checked) {
                          setDialogState(() {
                            if (checked == true) {
                              selectedOutletIds.add(outlet.id);
                            } else {
                              selectedOutletIds.remove(outlet.id);
                            }
                          });
                        },
                      );
                    }),
                  ],
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: () async {
                if (nameCtrl.text.trim().isEmpty ||
                    emailCtrl.text.trim().isEmpty) {
                  return;
                }
                final nav = Navigator.of(ctx);
                final success = await widget.employeeService.addEmployee(
                  name: nameCtrl.text.trim(),
                  email: emailCtrl.text.trim(),
                  phoneNumber: phoneCtrl.text.trim(),
                  password: passCtrl.text.trim().isNotEmpty
                      ? passCtrl.text.trim()
                      : null,
                  roleSlug: selectedRole,
                  outletAccess: outletAccess,
                  assignedOutletIds: selectedOutletIds.toList(),
                );
                if (success && mounted) {
                  nav.pop();
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                        content: Text('Karyawan berhasil ditambahkan')),
                  );
                }
              },
              child: const Text('Simpan'),
            ),
          ],
        ),
      ),
    );
  }

  void _showEditEmployeeDialog(EmployeeModel emp) {
    String selectedRole = emp.role.slug;
    String status = emp.status;
    String outletAccess = emp.outletAccess;
    final selectedOutletIds = Set<String>.from(emp.assignedOutletIds);

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text('Ubah Data: ${emp.name}'),
          content: SingleChildScrollView(
            child: SizedBox(
              width: 400,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  DropdownButtonFormField<String>(
                    initialValue: selectedRole,
                    decoration: const InputDecoration(
                      labelText: 'Peran',
                      isDense: true,
                    ),
                    items: widget.employeeService.availableRoles
                        .where((r) => r.slug != 'owner')
                        .map((r) => DropdownMenuItem(
                              value: r.slug,
                              child: Text(r.name),
                            ))
                        .toList(),
                    onChanged: (val) {
                      if (val != null) {
                        setDialogState(() => selectedRole = val);
                      }
                    },
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String>(
                    initialValue: status,
                    decoration: const InputDecoration(
                      labelText: 'Status Akun',
                      isDense: true,
                    ),
                    items: const [
                      DropdownMenuItem(
                          value: 'active', child: Text('Aktif')),
                      DropdownMenuItem(
                          value: 'suspended', child: Text('Ditangguhkan')),
                    ],
                    onChanged: (val) {
                      if (val != null) setDialogState(() => status = val);
                    },
                  ),
                  const SizedBox(height: 16),
                  const Text('Akses Outlet:',
                      style: TextStyle(fontWeight: FontWeight.bold)),
                  RadioGroup<String>(
                    groupValue: outletAccess,
                    onChanged: (val) {
                      if (val != null) setDialogState(() => outletAccess = val);
                    },
                    child: Column(
                      children: [
                        const RadioListTile<String>(
                          title: Text('Semua Outlet'),
                          value: 'all',
                        ),
                        const RadioListTile<String>(
                          title: Text('Outlet Tertentu'),
                          value: 'specific',
                        ),
                      ],
                    ),
                  ),
                  if (outletAccess == 'specific') ...[
                    const SizedBox(height: 8),
                    ...widget.tenantService.outlets.map((outlet) {
                      return CheckboxListTile(
                        title: Text(outlet.name),
                        value: selectedOutletIds.contains(outlet.id),
                        onChanged: (checked) {
                          setDialogState(() {
                            if (checked == true) {
                              selectedOutletIds.add(outlet.id);
                            } else {
                              selectedOutletIds.remove(outlet.id);
                            }
                          });
                        },
                      );
                    }),
                  ],
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: () async {
                final nav = Navigator.of(ctx);
                final success = await widget.employeeService.updateEmployee(
                  membershipId: emp.id,
                  roleSlug: selectedRole,
                  status: status,
                  outletAccess: outletAccess,
                  assignedOutletIds: selectedOutletIds.toList(),
                );
                if (success && mounted) {
                  nav.pop();
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                        content: Text('Data karyawan berhasil diperbarui')),
                  );
                }
              },
              child: const Text('Simpan Perubahan'),
            ),
          ],
        ),
      ),
    );
  }

  void _confirmRemoveEmployee(EmployeeModel emp) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Hapus Akses Karyawan'),
        content: Text(
          'Apakah Anda yakin ingin mencabut akses ${emp.name} dari bisnis ini?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              final nav = Navigator.of(ctx);
              final success =
                  await widget.employeeService.removeEmployee(emp.id);
              if (success && mounted) {
                nav.pop();
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Akses karyawan telah dicabut')),
                );
              }
            },
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
  }
}
