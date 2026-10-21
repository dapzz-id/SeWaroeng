import 'package:flutter/material.dart';
import '../../core/whatsapp/whatsapp_models.dart';
import '../../core/whatsapp/whatsapp_service.dart';

class WhatsAppSettingsScreen extends StatefulWidget {
  final WhatsAppService whatsAppService;

  const WhatsAppSettingsScreen({
    super.key,
    required this.whatsAppService,
  });

  @override
  State<WhatsAppSettingsScreen> createState() => _WhatsAppSettingsScreenState();
}

class _WhatsAppSettingsScreenState extends State<WhatsAppSettingsScreen> {
  final _formKey = GlobalKey<FormState>();

  String _selectedProvider = 'meta_cloud';
  String _selectedEnvironment = 'sandbox';
  final _phoneIdController = TextEditingController();
  final _businessIdController = TextEditingController();
  final _accessTokenController = TextEditingController();
  final _appSecretController = TextEditingController();
  final _verifyTokenController = TextEditingController();
  final _testPhoneController = TextEditingController();
  final _queryController = TextEditingController();
  final _merchantPhoneController = TextEditingController();

  bool _isSaving = false;
  bool _isVerifying = false;
  bool _isSendingTest = false;
  bool _isSavingPrefs = false;
  bool _isExecutingQuery = false;

  bool _dailySalesSummary = true;
  bool _lowStock = true;
  bool _bookingReminder = true;
  bool _paymentSettlement = true;
  bool _anomalyAlert = true;

  WhatsAppBusinessQueryResultModel? _lastQueryResult;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await widget.whatsAppService.fetchConnection();
      await widget.whatsAppService.fetchMessages();
      await widget.whatsAppService.fetchPreferences();
      _populateFromConnection();
      _populateFromPreferences();
    });
  }

  void _populateFromPreferences() {
    final prefs = widget.whatsAppService.preferences;
    if (prefs != null) {
      setState(() {
        _merchantPhoneController.text = prefs.merchantPhone ?? '';
        _dailySalesSummary = prefs.dailySalesSummary;
        _lowStock = prefs.lowStock;
        _bookingReminder = prefs.bookingReminder;
        _paymentSettlement = prefs.paymentSettlement;
        _anomalyAlert = prefs.anomalyAlert;
      });
    }
  }

  void _populateFromConnection() {
    final conn = widget.whatsAppService.connection;
    if (conn != null) {
      setState(() {
        _selectedProvider = conn.provider;
        _selectedEnvironment = conn.environment;
        _phoneIdController.text = conn.phoneNumberId ?? '';
        _businessIdController.text = conn.businessAccountId ?? '';
      });
    }
  }

  @override
  void dispose() {
    _phoneIdController.dispose();
    _businessIdController.dispose();
    _accessTokenController.dispose();
    _appSecretController.dispose();
    _verifyTokenController.dispose();
    _testPhoneController.dispose();
    _queryController.dispose();
    _merchantPhoneController.dispose();
    super.dispose();
  }

  Future<void> _handleSave() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSaving = true);

    final credentials = <String, dynamic>{};
    if (_accessTokenController.text.isNotEmpty) {
      credentials['access_token'] = _accessTokenController.text.trim();
    }
    if (_appSecretController.text.isNotEmpty) {
      credentials['app_secret'] = _appSecretController.text.trim();
    }
    if (_verifyTokenController.text.isNotEmpty) {
      credentials['verify_token'] = _verifyTokenController.text.trim();
    }

    final success = await widget.whatsAppService.saveConnection(
      provider: _selectedProvider,
      environment: _selectedEnvironment,
      credentials: credentials,
      phoneNumberId: _phoneIdController.text.trim().isEmpty ? null : _phoneIdController.text.trim(),
      businessAccountId: _businessIdController.text.trim().isEmpty ? null : _businessIdController.text.trim(),
    );

    if (!mounted) return;
    setState(() => _isSaving = false);

    if (success) {
      _accessTokenController.clear();
      _appSecretController.clear();
      _verifyTokenController.clear();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Konfigurasi WhatsApp berhasil disimpan.'),
          backgroundColor: Colors.green,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(widget.whatsAppService.errorMessage ?? 'Gagal menyimpan konfigurasi.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  Future<void> _handleVerify() async {
    setState(() => _isVerifying = true);
    final res = await widget.whatsAppService.verifyConnection();
    if (!mounted) return;
    setState(() => _isVerifying = false);

    if (res != null && res['verified'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Koneksi WhatsApp berhasil diverifikasi!'),
          backgroundColor: Colors.green,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(widget.whatsAppService.errorMessage ?? 'Verifikasi koneksi gagal.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  Future<void> _showSendTestDialog() async {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Kirim Pesan Uji Coba'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Masukkan nomor WhatsApp penerima (format 08xxx atau 628xxx):',
              style: TextStyle(fontSize: 13, color: Colors.black87),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _testPhoneController,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'Nomor WhatsApp',
                hintText: '081234567890',
                border: OutlineInputBorder(),
                prefixIcon: Icon(Icons.phone),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () async {
              final phone = _testPhoneController.text.trim();
              if (phone.isEmpty) return;

              Navigator.pop(ctx);
              setState(() => _isSendingTest = true);
              final success = await widget.whatsAppService.sendTestMessage(phone);
              if (!mounted) return;
              setState(() => _isSendingTest = false);

              if (success) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(
                    content: Text('Pesan uji coba berhasil dikirim!'),
                    backgroundColor: Colors.green,
                  ),
                );
                await widget.whatsAppService.fetchMessages();
              } else {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text(widget.whatsAppService.errorMessage ?? 'Gagal mengirim pesan uji coba.'),
                    backgroundColor: Colors.red,
                  ),
                );
              }
            },
            child: const Text('Kirim'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.whatsAppService,
      builder: (context, _) {
        final conn = widget.whatsAppService.connection;
        final providers = widget.whatsAppService.providers;
        final messages = widget.whatsAppService.messages;
        final isLoading = widget.whatsAppService.isLoading;
        final error = widget.whatsAppService.errorMessage;

        return DefaultTabController(
          length: 3,
          child: Scaffold(
            appBar: AppBar(
              title: const Text('WhatsApp Integration'),
              backgroundColor: Theme.of(context).colorScheme.inversePrimary,
              bottom: const TabBar(
                tabs: [
                  Tab(icon: Icon(Icons.settings), text: 'Konfigurasi'),
                  Tab(icon: Icon(Icons.notifications_active), text: 'Notifikasi & Query'),
                  Tab(icon: Icon(Icons.history), text: 'Riwayat Pesan'),
                ],
              ),
              actions: [
                IconButton(
                  icon: const Icon(Icons.refresh),
                  tooltip: 'Muat Ulang',
                  onPressed: () {
                    widget.whatsAppService.fetchConnection();
                    widget.whatsAppService.fetchMessages();
                    widget.whatsAppService.fetchPreferences();
                  },
                ),
              ],
            ),
            body: TabBarView(
              children: [
                _buildConfigurationTab(conn, providers, isLoading, error),
                _buildPreferencesAndQueryTab(),
                _buildMessagesTab(messages, isLoading),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildConfigurationTab(
    WhatsAppConnectionModel? conn,
    List<WhatsAppProviderDefinitionModel> providers,
    bool isLoading,
    String? error,
  ) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (error != null)
              Container(
                padding: const EdgeInsets.all(12),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: Colors.red.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.red.shade200),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.error_outline, color: Colors.red),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(error, style: const TextStyle(color: Colors.red)),
                    ),
                  ],
                ),
              ),

            // Connection Status Banner
            _buildStatusCard(conn),
            const SizedBox(height: 16),

            // Provider selection
            Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Penyedia WhatsApp (Provider)',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      initialValue: _selectedProvider,
                      decoration: const InputDecoration(
                        labelText: 'Provider Gateway',
                        border: OutlineInputBorder(),
                      ),
                      items: [
                        const DropdownMenuItem(value: 'meta_cloud', child: Text('Meta WhatsApp Cloud API (Resmi)')),
                        const DropdownMenuItem(value: 'mock', child: Text('Mock / Fake WhatsApp Provider (Pengujian)')),
                        ...providers
                            .where((p) => p.provider != 'meta_cloud' && p.provider != 'mock')
                            .map((p) => DropdownMenuItem(value: p.provider, child: Text(p.displayName))),
                      ],
                      onChanged: (val) {
                        if (val != null) setState(() => _selectedProvider = val);
                      },
                    ),
                    const SizedBox(height: 16),
                    DropdownButtonFormField<String>(
                      initialValue: _selectedEnvironment,
                      decoration: const InputDecoration(
                        labelText: 'Environment',
                        border: OutlineInputBorder(),
                      ),
                      items: const [
                        DropdownMenuItem(value: 'sandbox', child: Text('Sandbox / Development')),
                        DropdownMenuItem(value: 'production', child: Text('Production')),
                      ],
                      onChanged: (val) {
                        if (val != null) setState(() => _selectedEnvironment = val);
                      },
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Credential Inputs
            Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Parameter Akun & Kredensial',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      'Kredensial disimpan terenkripsi di server (AES-256) dan tidak pernah dikembalikan ke client.',
                      style: TextStyle(fontSize: 12, color: Colors.grey),
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _phoneIdController,
                      decoration: const InputDecoration(
                        labelText: 'Phone Number ID',
                        hintText: 'Misal: 105938472910482',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _businessIdController,
                      decoration: const InputDecoration(
                        labelText: 'WhatsApp Business Account ID (WABA)',
                        hintText: 'Misal: 109283746501928',
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _accessTokenController,
                      obscureText: true,
                      decoration: InputDecoration(
                        labelText: 'System User Permanent Access Token',
                        hintText: conn?.maskedCredentials.containsKey('access_token') == true
                            ? 'Tersimpan aman (kosongkan jika tidak ingin mengganti)'
                            : 'Masukkan token Meta API',
                        border: const OutlineInputBorder(),
                        helperText: 'Hanya diisi jika ingin memperbarui token.',
                      ),
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _appSecretController,
                      obscureText: true,
                      decoration: InputDecoration(
                        labelText: 'App Secret (HMAC-SHA256 Webhook Verification)',
                        hintText: conn?.maskedCredentials.containsKey('app_secret') == true
                            ? 'Tersimpan aman (kosongkan jika tidak ingin mengganti)'
                            : 'Masukkan App Secret',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _verifyTokenController,
                      obscureText: true,
                      decoration: InputDecoration(
                        labelText: 'Webhook Inbound Verify Token',
                        hintText: conn?.maskedCredentials.containsKey('verify_token') == true
                            ? 'Tersimpan aman (kosongkan jika tidak ingin mengganti)'
                            : 'Token verifikasi webhook Meta',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),

            // Action Buttons
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 14),
                backgroundColor: Theme.of(context).primaryColor,
                foregroundColor: Colors.white,
              ),
              onPressed: _isSaving ? null : _handleSave,
              icon: _isSaving
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.save),
              label: const Text('Simpan Konfigurasi'),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 12)),
                    onPressed: _isVerifying || conn == null ? null : _handleVerify,
                    icon: _isVerifying
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.verified),
                    label: const Text('Verifikasi Koneksi'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 12)),
                    onPressed: _isSendingTest || conn == null ? null : _showSendTestDialog,
                    icon: _isSendingTest
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.send),
                    label: const Text('Uji Coba Kirim'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildStatusCard(WhatsAppConnectionModel? conn) {
    if (conn == null) {
      return Card(
        color: Colors.amber.shade50,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        child: const Padding(
          padding: EdgeInsets.all(16),
          child: Row(
            children: [
              Icon(Icons.info_outline, color: Colors.amber),
              SizedBox(width: 12),
              Expanded(
                child: Text(
                  'WhatsApp belum terkonfigurasi untuk outlet ini. Masukkan kredensial untuk mengaktifkan pengiriman nota otomatis.',
                  style: TextStyle(fontSize: 13),
                ),
              ),
            ],
          ),
        ),
      );
    }

    final isVerified = conn.isVerified;
    return Card(
      color: isVerified ? Colors.green.shade50 : Colors.amber.shade50,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: isVerified ? Colors.green.shade300 : Colors.amber.shade300),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  isVerified ? Icons.check_circle : Icons.warning_amber,
                  color: isVerified ? Colors.green : Colors.amber.shade800,
                ),
                const SizedBox(width: 8),
                Text(
                  isVerified ? 'Koneksi Terverifikasi' : 'Belum Diverifikasi',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    color: isVerified ? Colors.green.shade900 : Colors.amber.shade900,
                  ),
                ),
                const Spacer(),
                Chip(
                  label: Text(conn.status.toUpperCase(), style: const TextStyle(fontSize: 11)),
                  backgroundColor: conn.isActive ? Colors.green.shade100 : Colors.grey.shade200,
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text('Provider: ${conn.provider} (${conn.environment})'),
            if (conn.phoneNumberId != null) Text('Phone ID: ${conn.phoneNumberId}'),
            if (conn.lastErrorMessage != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  'Catatan Terakhir: ${conn.lastErrorMessage}',
                  style: const TextStyle(fontSize: 12, color: Colors.red),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildMessagesTab(List<WhatsAppMessageModel> messages, bool isLoading) {
    if (isLoading && messages.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (messages.isEmpty) {
      return const Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.inbox_outlined, size: 48, color: Colors.grey),
            SizedBox(height: 12),
            Text('Belum ada pesan WhatsApp yang dikirim.'),
          ],
        ),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: messages.length,
      separatorBuilder: (_, _) => const Divider(),
      itemBuilder: (context, index) {
        final m = messages[index];
        return ListTile(
          leading: CircleAvatar(
            backgroundColor: _getStatusColor(m.status).withValues(alpha: 0.15),
            child: Icon(_getStatusIcon(m.status), color: _getStatusColor(m.status), size: 20),
          ),
          title: Text(m.recipientPhone, style: const TextStyle(fontWeight: FontWeight.bold)),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('${m.messageType} • ${m.provider}'),
              if (m.errorMessage != null)
                Text(
                  'Error: ${m.errorMessage}',
                  style: const TextStyle(color: Colors.red, fontSize: 11),
                ),
            ],
          ),
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Chip(
                label: Text(m.status.toUpperCase(), style: const TextStyle(fontSize: 10)),
                backgroundColor: _getStatusColor(m.status).withValues(alpha: 0.2),
                padding: EdgeInsets.zero,
                visualDensity: VisualDensity.compact,
              ),
              if (m.status == 'failed')
                IconButton(
                  icon: const Icon(Icons.refresh, size: 18),
                  tooltip: 'Kirim Ulang',
                  onPressed: () async {
                    final res = await widget.whatsAppService.resendMessage(m.id);
                    if (context.mounted && res) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Pesan dijadwalkan untuk dikirim ulang.')),
                      );
                    }
                  },
                ),
            ],
          ),
        );
      },
    );
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'delivered':
      case 'read':
        return Colors.green;
      case 'sent':
        return Colors.blue;
      case 'sending':
      case 'pending':
        return Colors.orange;
      case 'failed':
      default:
        return Colors.red;
    }
  }

  IconData _getStatusIcon(String status) {
    switch (status) {
      case 'read':
        return Icons.done_all;
      case 'delivered':
        return Icons.done_all;
      case 'sent':
        return Icons.done;
      case 'sending':
      case 'pending':
        return Icons.schedule;
      case 'failed':
      default:
        return Icons.error_outline;
    }
  }

  Widget _buildPreferencesAndQueryTab() {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Section 1: Merchant Notifications Preferences
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Preferensi Notifikasi Merchant (PRD Section 19)',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Pilih kategori pesan yang otomatis dikirimkan ke nomor WhatsApp pemilik/merchant.',
                    style: TextStyle(color: Colors.grey, fontSize: 13),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _merchantPhoneController,
                    decoration: const InputDecoration(
                      labelText: 'Nomor WhatsApp Merchant / Pemilik',
                      hintText: '08123456789 / +628123456789',
                      prefixIcon: Icon(Icons.phone),
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 12),
                  SwitchListTile(
                    title: const Text('Daily Sales Summary (Ringkasan Harian)'),
                    subtitle: const Text('Omzet, jumlah transaksi, dan rata-rata ticket harian.'),
                    value: _dailySalesSummary,
                    onChanged: (val) => setState(() => _dailySalesSummary = val),
                  ),
                  SwitchListTile(
                    title: const Text('Low-stock Alert (Peringatan Stok Menipis)'),
                    subtitle: const Text('Peringatan saat item berada di bawah batas minimum.'),
                    value: _lowStock,
                    onChanged: (val) => setState(() => _lowStock = val),
                  ),
                  SwitchListTile(
                    title: const Text('Booking Reminder (Pengingat Reservasi)'),
                    subtitle: const Text('Pengingat jadwal tamu sebelum reservasi dimulai.'),
                    value: _bookingReminder,
                    onChanged: (val) => setState(() => _bookingReminder = val),
                  ),
                  SwitchListTile(
                    title: const Text('Payment Settlement (Status Pencairan Dana)'),
                    subtitle: const Text('Notifikasi ketika dana gateway berhasil dicairkan (settled).'),
                    value: _paymentSettlement,
                    onChanged: (val) => setState(() => _paymentSettlement = val),
                  ),
                  SwitchListTile(
                    title: const Text('Anomaly Alert (Peringatan Anomali Operasional)'),
                    subtitle: const Text('Peringatan selisih kas penutupan shift dan ketidaksesuaian.'),
                    value: _anomalyAlert,
                    onChanged: (val) => setState(() => _anomalyAlert = val),
                  ),
                  const SizedBox(height: 12),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: _isSavingPrefs
                          ? null
                          : () async {
                              setState(() => _isSavingPrefs = true);
                              final success = await widget.whatsAppService.savePreferences(
                                WhatsAppPreferencesModel(
                                  merchantPhone: _merchantPhoneController.text.trim().isEmpty
                                      ? null
                                      : _merchantPhoneController.text.trim(),
                                  dailySalesSummary: _dailySalesSummary,
                                  lowStock: _lowStock,
                                  bookingReminder: _bookingReminder,
                                  paymentSettlement: _paymentSettlement,
                                  anomalyAlert: _anomalyAlert,
                                ),
                              );
                              if (mounted) {
                                setState(() => _isSavingPrefs = false);
                                ScaffoldMessenger.of(context).showSnackBar(
                                  SnackBar(
                                    content: Text(success
                                        ? 'Preferensi notifikasi berhasil disimpan.'
                                        : 'Gagal menyimpan preferensi notifikasi.'),
                                    backgroundColor: success ? Colors.green : Colors.red,
                                  ),
                                );
                              }
                            },
                      icon: _isSavingPrefs
                          ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Icon(Icons.save),
                      label: const Text('Simpan Preferensi Notifikasi'),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),

          // Section 2: Tanya Bisnis via Natural Language Safe Query
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Tanya Bisnis via WhatsApp (Natural Language Safe Query)',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Uji coba query bahasa alami yang dipetakan secara aman ke metrik bisnis terisolasi.',
                    style: TextStyle(color: Colors.grey, fontSize: 13),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _queryController,
                    decoration: InputDecoration(
                      labelText: 'Pertanyaan Bisnis',
                      hintText: 'Contoh: "Omzet hari ini?", "Produk paling laku minggu ini?"',
                      prefixIcon: const Icon(Icons.question_answer),
                      border: const OutlineInputBorder(),
                      suffixIcon: IconButton(
                        icon: const Icon(Icons.send),
                        onPressed: _isExecutingQuery
                            ? null
                            : () async {
                                final text = _queryController.text.trim();
                                if (text.isEmpty) return;
                                setState(() => _isExecutingQuery = true);
                                final res = await widget.whatsAppService.executeBusinessQuery(text);
                                if (mounted) {
                                  setState(() {
                                    _lastQueryResult = res;
                                    _isExecutingQuery = false;
                                  });
                                }
                              },
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    children: [
                      ActionChip(
                        label: const Text('Omzet hari ini?'),
                        onPressed: () {
                          _queryController.text = 'Omzet hari ini?';
                        },
                      ),
                      ActionChip(
                        label: const Text('Produk paling laku minggu ini?'),
                        onPressed: () {
                          _queryController.text = 'Produk paling laku minggu ini?';
                        },
                      ),
                    ],
                  ),
                  if (_lastQueryResult != null) ...[
                    const SizedBox(height: 16),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.blue.shade50,
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: Colors.blue.shade200),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Icon(
                                _lastQueryResult!.success ? Icons.check_circle : Icons.info,
                                color: _lastQueryResult!.success ? Colors.green : Colors.blue,
                                size: 18,
                              ),
                              const SizedBox(width: 8),
                              Text(
                                'Intent: ${_lastQueryResult!.intent}',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
                              ),
                            ],
                          ),
                          const Divider(),
                          Text(
                            _lastQueryResult!.replyText,
                            style: const TextStyle(fontSize: 13, height: 1.4),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
