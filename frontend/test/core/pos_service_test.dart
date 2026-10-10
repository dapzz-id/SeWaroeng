import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/pos/pos_models.dart';
import 'package:frontend/core/pos/pos_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetchCatalog parses categories, products, variants, prices, and modifiers', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/pos/catalog' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'categories': [
                {'id': 'cat-1', 'name': 'Makanan', 'slug': 'makanan'},
                {'id': 'cat-2', 'name': 'Minuman', 'slug': 'minuman'},
              ],
              'products': [
                {
                  'id': 'prod-1',
                  'name': 'Kopi Susu Aren',
                  'description': 'Kopi susu dengan gula aren asli',
                  'category_id': 'cat-2',
                  'category_name': 'Minuman',
                  'variants': [
                    {
                      'id': 'var-1',
                      'name': 'Regular',
                      'sku': 'KOP-REG',
                      'resolved_price': {
                        'price_id': 'prc-1',
                        'amount': 18000,
                        'currency': 'IDR',
                      },
                    },
                  ],
                  'modifier_groups': [
                    {
                      'id': 'mg-1',
                      'name': 'Topping',
                      'selection_type': 'multiple',
                      'min_selection': 0,
                      'max_selection': 3,
                      'is_required': false,
                      'modifiers': [
                        {'id': 'mod-1', 'name': 'Extra Shot', 'price_adjustment': 4000},
                        {'id': 'mod-2', 'name': 'Jelly', 'price_adjustment': 3000},
                      ],
                    },
                  ],
                },
              ],
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = PosService(apiClient: apiClient);

    await service.fetchCatalog(outletId: 'out-1');

    expect(service.categories.length, equals(2));
    expect(service.products.length, equals(1));
    expect(service.products.first.name, equals('Kopi Susu Aren'));
    expect(service.products.first.variants.first.resolvedPrice?.amount, equals(18000));
    expect(service.products.first.modifierGroups.first.modifiers.length, equals(2));
  });

  test('Cart calculations: subtotal, discount, and total', () {
    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: MockClient((_) async => http.Response('', 200)));
    final service = PosService(apiClient: apiClient);

    final variant = PosCatalogVariant(
      id: 'var-1',
      name: 'Regular',
      sku: 'SKU-1',
      resolvedPrice: const PosCatalogPrice(priceId: 'p-1', amount: 20000, currency: 'IDR'),
    );
    final mod = const PosCatalogModifier(id: 'm-1', name: 'Keju', priceAdjustment: 5000);
    final product = PosCatalogProduct(
      id: 'prod-1',
      name: 'Pisang Bakar',
      variants: [variant],
      modifierGroups: const [],
    );

    // Add 2 portions with Keju (+5000)
    service.addToCart(
      product: product,
      variant: variant,
      quantity: 2,
      modifiers: [mod],
    );

    // Each portion = 20000 + 5000 = 25000. 2 portions = 50000.
    expect(service.cartItems.length, equals(1));
    expect(service.cartItems.first.subtotalMinor, equals(50000));
    expect(service.cartSubtotalMinor, equals(50000));
    expect(service.cartTotalMinor, equals(50000));

    // Apply discount Rp 5.000
    service.setDiscount(5000);
    expect(service.cartTotalMinor, equals(45000));

    // Update quantity to 3
    service.updateCartItemQuantity(service.cartItems.first.id, 3);
    // 3 * 25000 = 75000. After discount 5000 = 70000.
    expect(service.cartSubtotalMinor, equals(75000));
    expect(service.cartTotalMinor, equals(70000));

    // Clear cart
    service.clearCart();
    expect(service.cartItems.isEmpty, isTrue);
    expect(service.cartTotalMinor, equals(0));
  });

  test('finalizeCurrentSale invokes finalize API and clears cart on success', () async {
    String? capturedIdempotencyKey;
    final mockClient = MockClient((request) async {
      capturedIdempotencyKey = request.headers['Idempotency-Key'];

      if (request.url.path == '/api/v1/pos/sales' && request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'sale-uuid-1',
              'reference_number': 'SALE-20261004-ABC123',
              'status': 'open',
              'subtotal_minor': 25000,
              'discount_minor': 0,
              'tax_minor': 0,
              'total_minor': 25000,
              'items': [],
            },
          }),
          201,
        );
      }

      if (request.url.path == '/api/v1/pos/sales/sale-uuid-1/finalize' && request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'sale-uuid-1',
              'reference_number': 'SALE-20261004-ABC123',
              'status': 'finalized',
              'subtotal_minor': 25000,
              'discount_minor': 0,
              'tax_minor': 0,
              'total_minor': 25000,
              'items': [],
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = PosService(apiClient: apiClient);

    final variant = PosCatalogVariant(
      id: 'var-1',
      name: 'Regular',
      sku: 'SKU-1',
      resolvedPrice: const PosCatalogPrice(priceId: 'p-1', amount: 25000, currency: 'IDR'),
    );
    final product = PosCatalogProduct(id: 'prod-1', name: 'Nasi Goreng', variants: [variant], modifierGroups: const []);

    service.addToCart(product: product, variant: variant, quantity: 1);

    final finalized = await service.finalizeCurrentSale(outletId: 'out-1');

    expect(finalized, isNotNull);
    expect(finalized!.status, equals('finalized'));
    expect(finalized.referenceNumber, equals('SALE-20261004-ABC123'));
    expect(capturedIdempotencyKey, isNotNull);
    expect(service.cartItems.isEmpty, isTrue); // Cart cleared
  });
}
