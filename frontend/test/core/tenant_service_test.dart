import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/tenant/outlet_model.dart';
import 'package:frontend/core/tenant/tenant_model.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late InMemoryTokenStorage tokenStorage;

  setUp(() {
    tokenStorage = InMemoryTokenStorage();
  });

  test(
    'createBusiness atomically establishes active business and outlet',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/tenants' && request.method == 'POST') {
          expect(request.headers['Idempotency-Key'], isNotEmpty);
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          expect(body['name'], equals('Waroeng Enak'));
          expect(body['slug'], equals('waroeng-enak'));

          return http.Response(
            jsonEncode({
              'data': {
                'tenant': {
                  'id': 'tenant-uuid-1',
                  'name': 'Waroeng Enak',
                  'slug': 'waroeng-enak',
                  'status': 'active',
                  'business_type': 'fnb',
                },
                'outlet': {
                  'id': 'outlet-uuid-1',
                  'tenant_id': 'tenant-uuid-1',
                  'name': 'Waroeng Enak - Pusat',
                  'code': 'PUSAT',
                  'status': 'active',
                },
                'membership': {
                  'id': 'membership-uuid-1',
                  'role': 'owner',
                  'status': 'active',
                },
              },
              'message': 'Business created successfully.',
            }),
            201,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
      );
      final tenantService = TenantService(
        apiClient: apiClient,
        tokenStorage: tokenStorage,
      );

      final success = await tenantService.createBusiness(
        name: 'Waroeng Enak',
        slug: 'waroeng-enak',
      );

      expect(success, isTrue);
      expect(tenantService.activeTenant?.id, equals('tenant-uuid-1'));
      expect(tenantService.activeTenant?.name, equals('Waroeng Enak'));
      expect(tenantService.activeOutlet?.id, equals('outlet-uuid-1'));
      expect(apiClient.activeTenantId, equals('tenant-uuid-1'));

      // Verify storage persistence
      expect(await tokenStorage.getActiveTenantId(), equals('tenant-uuid-1'));
      expect(await tokenStorage.getActiveOutletId(), equals('outlet-uuid-1'));
    },
  );

  test('loadBusinesses restores active tenant from storage', () async {
    await tokenStorage.saveActiveTenantId('tenant-uuid-2');

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/tenants') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'tenant-uuid-1',
                'name': 'Bisnis Satu',
                'slug': 'bisnis-satu',
                'status': 'active',
              },
              {
                'id': 'tenant-uuid-2',
                'name': 'Bisnis Dua',
                'slug': 'bisnis-dua',
                'status': 'active',
              },
            ],
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/outlets') {
        expect(request.headers['X-Tenant-ID'], equals('tenant-uuid-2'));
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'outlet-uuid-2a',
                'tenant_id': 'tenant-uuid-2',
                'name': 'Cabang 2A',
                'code': 'CAB2A',
                'status': 'active',
              },
            ],
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
    );
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );

    await tenantService.loadBusinesses();

    expect(tenantService.businesses.length, equals(2));
    expect(tenantService.activeTenant?.id, equals('tenant-uuid-2'));
    expect(apiClient.activeTenantId, equals('tenant-uuid-2'));
  });

  test('tenant switching updates context and apiClient header', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/outlets') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'outlet-uuid-b',
                'tenant_id': 'tenant-b',
                'name': 'Outlet B',
                'code': 'OUT-B',
                'status': 'active',
              },
            ],
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
    );
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );

    const tenantA = TenantModel(id: 'tenant-a', name: 'A', slug: 'a');
    const tenantB = TenantModel(id: 'tenant-b', name: 'B', slug: 'b');

    await tenantService.setActiveTenant(tenantA, reloadOutlets: false);
    expect(tenantService.activeTenant?.id, equals('tenant-a'));
    expect(apiClient.activeTenantId, equals('tenant-a'));

    await tenantService.setActiveTenant(tenantB, reloadOutlets: true);
    expect(tenantService.activeTenant?.id, equals('tenant-b'));
    expect(apiClient.activeTenantId, equals('tenant-b'));
    expect(await tokenStorage.getActiveTenantId(), equals('tenant-b'));
  });

  test('clearContext resets all tenant state and clears storage', () async {
    final apiClient = ApiClient(baseUrl: 'http://localhost:8000');
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );

    const tenant = TenantModel(id: 'tenant-x', name: 'X', slug: 'x');
    await tenantService.setActiveTenant(tenant, reloadOutlets: false);
    await tenantService.setActiveOutlet(
      const OutletModel(
        id: 'out-x',
        tenantId: 'tenant-x',
        name: 'O',
        code: 'C',
      ),
    );

    expect(tenantService.activeTenant, isNotNull);
    expect(tenantService.activeOutlet, isNotNull);
    expect(apiClient.activeTenantId, equals('tenant-x'));

    await tenantService.clearContext();

    expect(tenantService.activeTenant, isNull);
    expect(tenantService.activeOutlet, isNull);
    expect(apiClient.activeTenantId, isNull);
    expect(await tokenStorage.getActiveTenantId(), isNull);
    expect(await tokenStorage.getActiveOutletId(), isNull);
  });

  test('updateBusiness updates activeTenant with updated fields', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/tenants/tenant-uuid-1' &&
          request.method == 'PATCH') {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['name'], equals('Waroeng Baru'));
        expect(body['business_type'], equals('retail'));
        expect(body['currency'], equals('IDR'));
        expect(body['timezone'], equals('Asia/Makassar'));

        return http.Response(
          jsonEncode({
            'data': {
              'id': 'tenant-uuid-1',
              'name': 'Waroeng Baru',
              'slug': 'waroeng-baru',
              'business_type': 'retail',
              'status': 'active',
              'currency': 'IDR',
              'timezone': 'Asia/Makassar',
            },
            'message': 'Business profile updated successfully.',
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
    );
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );

    const initialTenant = TenantModel(
      id: 'tenant-uuid-1',
      name: 'Waroeng Lama',
      slug: 'waroeng-lama',
      businessType: 'fnb',
      status: 'active',
      currency: 'IDR',
      timezone: 'Asia/Jakarta',
    );
    await tenantService.setActiveTenant(initialTenant, reloadOutlets: false);

    final ok = await tenantService.updateBusiness(
      tenantId: 'tenant-uuid-1',
      data: {
        'name': 'Waroeng Baru',
        'slug': 'waroeng-baru',
        'business_type': 'retail',
        'currency': 'IDR',
        'timezone': 'Asia/Makassar',
      },
    );

    expect(ok, isTrue);
    expect(tenantService.activeTenant?.name, equals('Waroeng Baru'));
    expect(tenantService.activeTenant?.businessType, equals('retail'));
    expect(tenantService.activeTenant?.timezone, equals('Asia/Makassar'));
  });

  test(
    'updateOutlet and toggleOutletStatus update outlet state properly',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/outlets/outlet-1' &&
            request.method == 'PATCH') {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'outlet-1',
                'tenant_id': 'tenant-1',
                'name': body['name'] ?? 'Outlet 1',
                'code': body['code'] ?? 'OUT1',
                'status': body['status'] ?? 'active',
                'timezone': body['timezone'] ?? 'Asia/Jakarta',
              },
              'message': 'Outlet updated successfully.',
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
      );
      final tenantService = TenantService(
        apiClient: apiClient,
        tokenStorage: tokenStorage,
      );

      const initialOutlet = OutletModel(
        id: 'outlet-1',
        tenantId: 'tenant-1',
        name: 'Outlet 1',
        code: 'OUT1',
        status: 'active',
      );
      const tenant1 = TenantModel(id: 'tenant-1', name: 'T1', slug: 't1');
      await tenantService.setActiveTenant(tenant1, reloadOutlets: false);
      tenantService.setOutletsForTesting([initialOutlet]);
      await tenantService.setActiveOutlet(initialOutlet);

      // Update name
      final updateOk = await tenantService.updateOutlet(
        outletId: 'outlet-1',
        data: {'name': 'Outlet 1 Updated'},
      );
      expect(updateOk, isTrue);
      expect(tenantService.outlets.first.name, equals('Outlet 1 Updated'));
      expect(tenantService.activeOutlet?.name, equals('Outlet 1 Updated'));

      // Toggle status to inactive
      final toggleOk = await tenantService.toggleOutletStatus(
        outletId: 'outlet-1',
        status: 'inactive',
      );
      expect(toggleOk, isTrue);
      expect(tenantService.outlets.first.status, equals('inactive'));
      expect(tenantService.activeOutlet?.status, equals('inactive'));
    },
  );

  test(
    'context switching to new tenant clears previous active outlet',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/outlets') {
          return http.Response(
            jsonEncode({
              'data': [
                {
                  'id': 'outlet-b1',
                  'tenant_id': 'tenant-b',
                  'name': 'Outlet B1',
                  'code': 'B1',
                  'status': 'active',
                },
              ],
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
      );
      final tenantService = TenantService(
        apiClient: apiClient,
        tokenStorage: tokenStorage,
      );

      const tenantA = TenantModel(id: 'tenant-a', name: 'A', slug: 'a');
      const tenantB = TenantModel(id: 'tenant-b', name: 'B', slug: 'b');
      const outletA = OutletModel(
        id: 'outlet-a',
        tenantId: 'tenant-a',
        name: 'A1',
        code: 'A1',
      );

      await tenantService.setActiveTenant(tenantA, reloadOutlets: false);
      await tenantService.setActiveOutlet(outletA);

      expect(tenantService.activeTenant?.id, equals('tenant-a'));
      expect(tenantService.activeOutlet?.id, equals('outlet-a'));

      // Switch to Tenant B
      await tenantService.setActiveTenant(tenantB, reloadOutlets: true);

      // Outlet from Tenant A must be cleared, not retained!
      expect(tenantService.activeTenant?.id, equals('tenant-b'));
      // Since outlets were loaded, the first outlet of Tenant B is auto-selected
      expect(tenantService.activeOutlet?.id, equals('outlet-b1'));
      expect(tenantService.activeOutlet?.tenantId, equals('tenant-b'));
    },
  );
}
