import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/whatsapp/whatsapp_service.dart';
import 'package:frontend/presentation/platform/whatsapp_settings_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('renders WhatsApp settings screen with configuration and status card', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path.contains('/whatsapp/connection')) {
        return http.Response(
          jsonEncode({
            'data': {
              'connections': [
                {
                  'id': 'conn-1',
                  'tenant_id': 'tenant-1',
                  'provider': 'meta_cloud',
                  'environment': 'sandbox',
                  'status': 'active',
                  'is_default': true,
                  'phone_number_id': '105938472910482',
                  'business_account_id': '109283746501928',
                  'verification_status': 'verified',
                  'masked_credentials': {'access_token': 'EAA***'},
                },
              ],
              'available_providers': [
                {
                  'id': 'p-1',
                  'provider': 'meta_cloud',
                  'display_name': 'Meta WhatsApp Cloud API (Resmi)',
                  'is_enabled': true,
                },
              ],
            },
          }),
          200,
        );
      }
      if (request.url.path.contains('/whatsapp/messages')) {
        return http.Response(
          jsonEncode({'data': []}),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final whatsAppService = WhatsAppService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: WhatsAppSettingsScreen(
          whatsAppService: whatsAppService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('WhatsApp Integration'), findsOneWidget);
    expect(find.text('Koneksi Terverifikasi'), findsOneWidget);
    expect(find.text('Penyedia WhatsApp (Provider)'), findsOneWidget);
    expect(find.text('Simpan Konfigurasi'), findsOneWidget);
    expect(find.text('Verifikasi Koneksi'), findsOneWidget);
    expect(find.text('Uji Coba Kirim'), findsOneWidget);
  });
}
