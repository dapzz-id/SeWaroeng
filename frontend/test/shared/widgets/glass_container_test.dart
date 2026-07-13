import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sewaroeng/shared/widgets/glass_container.dart';

void main() {
  group('GlassContainer Widget Tests', () {
    testWidgets('renders child content correctly', (WidgetTester tester) async {
      await tester.pumpWidget(
        const ProviderScope(
          child: MaterialApp(
            home: Scaffold(
              body: GlassContainer(
                child: Text('Glass Content'),
              ),
            ),
          ),
        ),
      );

      expect(find.text('Glass Content'), findsOneWidget);
    });

    testWidgets('applies blur effect via BackdropFilter',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        const ProviderScope(
          child: MaterialApp(
            home: Scaffold(
              body: GlassContainer(
                blur: 10.0,
                child: SizedBox(width: 100, height: 100),
              ),
            ),
          ),
        ),
      );

      final backdropFilter =
          tester.widget<BackdropFilter>(find.byType(BackdropFilter));

      // Verify ImageFilter contains blur (this is a simplified check since ImageFilter.blur is opaque)
      expect(backdropFilter.filter, isNotNull);
    });
  });
}
