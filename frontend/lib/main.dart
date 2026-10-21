import 'package:flutter/material.dart';
import 'core/ai/ai_copilot_service.dart';
import 'core/api/api_client.dart';
import 'core/auth/auth_service.dart';
import 'core/auth/token_storage.dart';
import 'core/catalog/catalog_service.dart';
import 'core/config/app_config.dart';
import 'core/customer/customer_service.dart';
import 'core/dashboard/dashboard_service.dart';
import 'core/device/device_service.dart';
import 'core/employee/employee_service.dart';
import 'core/finance/finance_service.dart';
import 'core/inventory/inventory_service.dart';
import 'core/loyalty/loyalty_service.dart';
import 'core/notification/notification_service.dart';
import 'core/order/order_service.dart';
import 'core/payment/payment_reconciliation_service.dart';
import 'core/payment/payment_service.dart';
import 'core/pos/pos_service.dart';
import 'core/purchasing/purchasing_service.dart';
import 'core/recipe/recipe_service.dart';
import 'core/shift/shift_service.dart';
import 'core/subscription/subscription_service.dart';
import 'core/table/table_service.dart';
import 'core/tenant/tenant_model.dart';
import 'core/tenant/tenant_service.dart';
import 'core/whatsapp/whatsapp_service.dart';
import 'presentation/auth/login_screen.dart';
import 'presentation/dashboard/dashboard_placeholder_screen.dart';
import 'presentation/tenant/tenant_selection_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  AppConfig.validate();

  final tokenStorage = SecureTokenStorage();
  final deviceService = DeviceService();

  late final ApiClient apiClient;
  apiClient = ApiClient(
    baseUrl: AppConfig.apiBaseUrl,
    tokenProvider: () => tokenStorage.getToken(),
  );

  final authService = AuthService(
    apiClient: apiClient,
    tokenStorage: tokenStorage,
    deviceService: deviceService,
  );

  final tenantService = TenantService(
    apiClient: apiClient,
    tokenStorage: tokenStorage,
  );

  // Initialize and restore persisted session
  await authService.restoreSession();
  if (authService.state.isAuthenticated) {
    await tenantService.loadBusinesses();
  }

  runApp(
    SeWaroengApp(
      apiClient: apiClient,
      authService: authService,
      tenantService: tenantService,
    ),
  );
}

class SeWaroengApp extends StatefulWidget {
  final ApiClient apiClient;
  final AuthService authService;
  final TenantService tenantService;

  const SeWaroengApp({
    super.key,
    required this.apiClient,
    required this.authService,
    required this.tenantService,
  });

  @override
  State<SeWaroengApp> createState() => _SeWaroengAppState();
}

class _SeWaroengAppState extends State<SeWaroengApp> {
  late final CatalogService _catalogService;
  late final InventoryService _inventoryService;
  late final PurchasingService _purchasingService;
  late final ShiftService _shiftService;
  late final DashboardService _dashboardService;
  late final NotificationService _notificationService;
  late final EmployeeService _employeeService;
  late final CustomerService _customerService;
  late final TableService _tableService;
  late final OrderService _orderService;
  late final RecipeService _recipeService;
  late final LoyaltyService _loyaltyService;
  late final AiCopilotService _aiCopilotService;
  late final WhatsAppService _whatsappService;
  late final PaymentGatewayService _paymentService;
  late final PaymentReconciliationService _paymentReconciliationService;
  late final SubscriptionService _subscriptionService;
  late final PosService _posService;
  late final FinanceService _financeService;

  @override
  void initState() {
    super.initState();
    final client = widget.apiClient;
    _catalogService = CatalogService(apiClient: client);
    _inventoryService = InventoryService(apiClient: client);
    _purchasingService = PurchasingService(apiClient: client);
    _shiftService = ShiftService(apiClient: client);
    _dashboardService = DashboardService(apiClient: client);
    _notificationService = NotificationService(apiClient: client);
    _employeeService = EmployeeService(apiClient: client);
    _customerService = CustomerService(apiClient: client);
    _tableService = TableService(apiClient: client);
    _orderService = OrderService(apiClient: client);
    _recipeService = RecipeService(apiClient: client);
    _loyaltyService = LoyaltyService(apiClient: client);
    _aiCopilotService = AiCopilotService(apiClient: client);
    _whatsappService = WhatsAppService(apiClient: client);
    _paymentService = PaymentGatewayService(apiClient: client);
    _paymentReconciliationService = PaymentReconciliationService(apiClient: client);
    _subscriptionService = SubscriptionService(apiClient: client);
    _posService = PosService(apiClient: client);
    _financeService = FinanceService(apiClient: client);

    widget.authService.addListener(_onAuthChanged);
    widget.tenantService.addListener(_onTenantChanged);
  }

  @override
  void dispose() {
    widget.authService.removeListener(_onAuthChanged);
    widget.tenantService.removeListener(_onTenantChanged);
    super.dispose();
  }

  void _onAuthChanged() {
    if (mounted) setState(() {});
  }

  void _onTenantChanged() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'SeWaroeng',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        brightness: Brightness.dark,
        scaffoldBackgroundColor: const Color(0xFF0F172A),
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF3B82F6),
          brightness: Brightness.dark,
          surface: const Color(0xFF1E293B),
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: Color(0xFF1E293B),
          elevation: 0,
        ),
        cardColor: const Color(0xFF1E293B),
        dividerColor: const Color(0xFF334155),
      ),
      home: _buildCurrentScreen(),
    );
  }

  Widget _buildCurrentScreen() {
    final authState = widget.authService.state;

    // 1. Not Authenticated -> Login Screen
    if (!authState.isAuthenticated) {
      return LoginScreen(
        authService: widget.authService,
        onLoginSuccess: () async {
          await widget.tenantService.loadBusinesses();
        },
      );
    }

    // 2. Authenticated, but no active tenant selected -> Tenant Selection Screen
    if (widget.tenantService.activeTenant == null) {
      return TenantSelectionScreen(
        tenantService: widget.tenantService,
        authService: widget.authService,
        onTenantSelected: (TenantModel tenant) async {
          await widget.tenantService.setActiveTenant(tenant, reloadOutlets: true);
        },
      );
    }

    // 3. Authenticated and active tenant selected -> Super Dashboard POS
    return DashboardPlaceholderScreen(
      tenantService: widget.tenantService,
      authService: widget.authService,
      catalogService: _catalogService,
      inventoryService: _inventoryService,
      purchasingService: _purchasingService,
      shiftService: _shiftService,
      dashboardService: _dashboardService,
      notificationService: _notificationService,
      employeeService: _employeeService,
      customerService: _customerService,
      tableService: _tableService,
      orderService: _orderService,
      recipeService: _recipeService,
      copilotService: _aiCopilotService,
      posService: _posService,
      financeService: _financeService,
      loyaltyService: _loyaltyService,
      subscriptionService: _subscriptionService,
      whatsAppService: _whatsappService,
      paymentGatewayService: _paymentService,
      paymentReconciliationService: _paymentReconciliationService,
    );
  }
}
