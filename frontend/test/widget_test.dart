// Basic Flutter widget test for SeWaroeng app.
//
// To perform an interaction with a widget in your test, use the WidgetTester
// utility in the flutter_test package. For example, you can send tap and scroll
// gestures. You can also use WidgetTester to find child widgets in the widget
// tree, read text, and verify that the values of widget properties are correct.

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:sewaroeng/main.dart';

void main() {
  testWidgets('App starts successfully', (WidgetTester tester) async {
    // Build our app wrapped in a ProviderScope (required by Riverpod).
    await tester.pumpWidget(
      const ProviderScope(
        child: SeWaroengApp(),
      ),
    );

    // Verify that the app renders without throwing.
    expect(tester.takeException(), isNull);
  });
}
