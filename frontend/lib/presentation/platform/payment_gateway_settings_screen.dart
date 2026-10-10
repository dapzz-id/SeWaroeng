import 'package:flutter/material.dart';
import '../../core/payment/payment_models.dart';
import '../../core/payment/payment_service.dart';

class PaymentGatewaySettingsScreen extends StatefulWidget {
  final PaymentGatewayService paymentService;
  final String? initialTenantId;

  const PaymentGatewaySettingsScreen({
    super.key,
    required this.paymentService,
    this.initialTenantId,
  });

  @override
  State<PaymentGatewaySettingsScreen> createState() =>
      _PaymentGatewaySettingsScreenState();
}

class _PaymentGatewaySettingsScreenState
    extends State<PaymentGatewaySettingsScreen> {
  String? _selectedTenantId;

  @override
  void initState() {
    super.initState();
    _selectedTenantId = widget.initialTenantId;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      widget.paymentService.fetchPlatformProviders();
      if (_selectedTenantId != null) {
        widget.paymentService.fetchTenantConnections(_selectedTenantId!);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.paymentService,
      builder: (context, _) {
        final providers = widget.paymentService.platformProviders;
        final connections = widget.paymentService.tenantConnections;
        final isLoading = widget.paymentService.isLoading;
        final error = widget.paymentService.errorMessage;

        return Scaffold(
          appBar: AppBar(
            title: const Text('Superadmin Payment Gateway Settings'),
            backgroundColor: Theme.of(context).colorScheme.inversePrimary,
            actions: [
              IconButton(
                icon: const Icon(Icons.refresh),
                tooltip: 'Refresh',
                onPressed: () {
                  widget.paymentService.fetchPlatformProviders();
                  if (_selectedTenantId != null) {
                    widget.paymentService.fetchTenantConnections(_selectedTenantId!);
                  }
                },
              ),
            ],
          ),
          body: isLoading && providers.isEmpty
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.all(16),
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
                              child: Text(
                                error,
                                style: const TextStyle(color: Colors.red),
                              ),
                            ),
                          ],
                        ),
                      ),
                    const Text(
                      'Platform Payment Gateways',
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'Manage global payment provider integrations and tenant gateway credentials.',
                      style: TextStyle(color: Colors.grey),
                    ),
                    const SizedBox(height: 16),
                    ...providers.map((p) => _buildProviderCard(context, p, connections)),
                  ],
                ),
        );
      },
    );
  }

  Widget _buildProviderCard(
    BuildContext context,
    PaymentProviderDefinition provider,
    List<TenantPaymentConnection> connections,
  ) {
    final connection = connections.where((c) => c.provider == provider.provider).firstOrNull;

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      elevation: 2,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Icon(
                      provider.provider == 'midtrans'
                          ? Icons.payment
                          : Icons.account_balance_wallet,
                      color: Colors.indigo,
                      size: 28,
                    ),
                    const SizedBox(width: 12),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          provider.displayName,
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        Text(
                          'Provider Key: ${provider.provider}',
                          style: const TextStyle(fontSize: 12, color: Colors.grey),
                        ),
                      ],
                    ),
                  ],
                ),
                Switch(
                  value: provider.isEnabled,
                  onChanged: (val) {
                    _confirmToggleProvider(context, provider, val);
                  },
                ),
              ],
            ),
            const Divider(height: 24),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                Chip(
                  label: Text('Status: ${provider.isEnabled ? "Enabled" : "Disabled"}'),
                  backgroundColor: provider.isEnabled ? Colors.green.shade50 : Colors.grey.shade100,
                ),
                Chip(
                  label: Text('Methods: ${provider.supportedMethods.join(", ")}'),
                ),
                Chip(
                  label: Text('Refund: ${provider.capabilities["refund"] == true ? "Supported" : "Unsupported"}'),
                ),
              ],
            ),
            if (connection != null) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.grey.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.grey.shade300),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Tenant Connection (${connection.environment})',
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                        _buildVerificationBadge(connection.verificationStatus),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text('Merchant ID/Code: ${connection.merchantIdentifier}'),
                    if (connection.maskedCredentials.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        'Credentials: ${connection.maskedCredentials.entries.map((e) => "${e.key}: ${e.value}").join(", ")}',
                        style: const TextStyle(fontSize: 12, color: Colors.black87),
                      ),
                    ],
                    if (connection.verifiedAt != null)
                      Text(
                        'Verified At: ${connection.verifiedAt}',
                        style: const TextStyle(fontSize: 11, color: Colors.grey),
                      ),
                    if (connection.lastErrorCode != null)
                      Text(
                        'Last Error: [${connection.lastErrorCode}] ${connection.lastErrorMessage ?? ""}',
                        style: const TextStyle(fontSize: 11, color: Colors.red),
                      ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 16),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                if (connection != null && _selectedTenantId != null)
                  OutlinedButton.icon(
                    icon: const Icon(Icons.verified_outlined, size: 18),
                    label: const Text('Verify Connection'),
                    onPressed: () {
                      widget.paymentService.verifyTenantConnection(
                        tenantId: _selectedTenantId!,
                        provider: provider.provider,
                      );
                    },
                  ),
                const SizedBox(width: 8),
                ElevatedButton.icon(
                  icon: const Icon(Icons.settings, size: 18),
                  label: Text(connection != null ? 'Edit Connection' : 'Configure Connection'),
                  onPressed: () {
                    _showConfigureDialog(context, provider, connection);
                  },
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildVerificationBadge(String status) {
    Color bg = Colors.grey.shade200;
    Color fg = Colors.grey.shade800;

    if (status == 'verified') {
      bg = Colors.green.shade100;
      fg = Colors.green.shade900;
    } else if (status == 'failed') {
      bg = Colors.red.shade100;
      fg = Colors.red.shade900;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        status.toUpperCase(),
        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: fg),
      ),
    );
  }

  void _confirmToggleProvider(
    BuildContext context,
    PaymentProviderDefinition provider,
    bool enable,
  ) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('${enable ? "Enable" : "Disable"} ${provider.displayName}?'),
        content: Text(
          'Are you sure you want to ${enable ? "enable" : "disable"} ${provider.displayName} globally across the platform?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () {
              Navigator.pop(ctx);
              widget.paymentService.togglePlatformProvider(provider.provider, enable);
            },
            child: const Text('Confirm'),
          ),
        ],
      ),
    );
  }

  void _showConfigureDialog(
    BuildContext context,
    PaymentProviderDefinition provider,
    TenantPaymentConnection? connection,
  ) {
    final tenantController = TextEditingController(text: _selectedTenantId ?? '');
    final merchantController =
        TextEditingController(text: connection?.merchantIdentifier ?? '');
    final primaryKeyController = TextEditingController();
    final clientKeyController = TextEditingController();
    String environment = connection?.environment ?? 'sandbox';

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Configure ${provider.displayName}'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: tenantController,
                decoration: const InputDecoration(labelText: 'Tenant UUID'),
              ),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: environment,
                decoration: const InputDecoration(labelText: 'Environment'),
                items: const [
                  DropdownMenuItem(value: 'sandbox', child: Text('Sandbox')),
                  DropdownMenuItem(value: 'production', child: Text('Production')),
                ],
                onChanged: (val) {
                  if (val != null) environment = val;
                },
              ),
              const SizedBox(height: 8),
              TextField(
                controller: merchantController,
                decoration: InputDecoration(
                  labelText: provider.provider == 'midtrans'
                      ? 'Merchant ID'
                      : 'Merchant Code',
                ),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: primaryKeyController,
                obscureText: true,
                decoration: InputDecoration(
                  labelText: provider.provider == 'midtrans'
                      ? 'Server Key'
                      : 'API Key',
                  hintText: connection != null ? 'Leave blank to keep existing' : null,
                ),
              ),
              if (provider.provider == 'midtrans') ...[
                const SizedBox(height: 8),
                TextField(
                  controller: clientKeyController,
                  obscureText: true,
                  decoration: InputDecoration(
                    labelText: 'Client Key',
                    hintText: connection != null ? 'Leave blank to keep existing' : null,
                  ),
                ),
              ],
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () {
              final targetTenant = tenantController.text.trim();
              if (targetTenant.isEmpty) return;

              final creds = <String, dynamic>{};
              if (provider.provider == 'midtrans') {
                if (primaryKeyController.text.isNotEmpty) {
                  creds['server_key'] = primaryKeyController.text.trim();
                }
                if (clientKeyController.text.isNotEmpty) {
                  creds['client_key'] = clientKeyController.text.trim();
                }
              } else {
                if (primaryKeyController.text.isNotEmpty) {
                  creds['api_key'] = primaryKeyController.text.trim();
                }
              }

              Navigator.pop(ctx);
              setState(() {
                _selectedTenantId = targetTenant;
              });

              widget.paymentService.saveTenantConnection(
                tenantId: targetTenant,
                provider: provider.provider,
                environment: environment,
                merchantIdentifier: merchantController.text.trim(),
                credentials: creds,
              );
            },
            child: const Text('Save Connection'),
          ),
        ],
      ),
    );
  }
}
