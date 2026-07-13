// Neura Cakrawira Solusi | SeWaroeng
// Main entry point for Flutter app.
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/theme/app_theme.dart';
import 'core/router/app_router.dart';
import 'package:flutter_web_plugins/url_strategy.dart';

import 'dart:convert';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'features/pos/data/models/pos_transaction_hive_model.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  usePathUrlStrategy();
  
  // Initialize Hive
  await Hive.initFlutter();

  // Setup encryption key
  const secureStorage = FlutterSecureStorage();
  final hasKey = await secureStorage.containsKey(key: 'hive_key');
  if (!hasKey) {
    final key = Hive.generateSecureKey();
    await secureStorage.write(key: 'hive_key', value: base64UrlEncode(key));
  }
  
  final keyStr = await secureStorage.read(key: 'hive_key');
  final encryptionKeyUint8List = base64Url.decode(keyStr!);

  // Register Adapters
  Hive.registerAdapter(PosTransactionHiveModelAdapter());
  Hive.registerAdapter(PosTransactionItemHiveModelAdapter());

  // Open encrypted box
  await Hive.openBox<PosTransactionHiveModel>(
    'pos_transactions',
    encryptionCipher: HiveAesCipher(encryptionKeyUint8List),
  );
  
  runApp(
    const ProviderScope(
      child: SeWaroengApp(),
    ),
  );
}

class SeWaroengApp extends ConsumerWidget {
  const SeWaroengApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final goRouter = ref.watch(goRouterProvider);
    
    return MaterialApp.router(
      title: 'SeWaroeng',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.darkTheme,
      routerConfig: goRouter,
    );
  }
}
