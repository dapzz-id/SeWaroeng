import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/database/database_encryption.dart';

class _MockRawDatabase {
  final List<String> executedQueries = [];

  void execute(String sql) {
    executedQueries.add(sql);
  }
}

void main() {
  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
  });

  group('DatabaseEncryptionKeyManager Tests', () {
    test('generates dynamic 256-bit high-entropy key on first retrieval', () async {
      final storage = const FlutterSecureStorage();
      final keyManager = DatabaseEncryptionKeyManager(secureStorage: storage);

      final key1 = await keyManager.getOrCreateDatabaseKey();
      expect(key1, isNotEmpty);
      expect(key1.length, equals(64)); // 256 bits = 64 hex characters
      expect(RegExp(r'^[0-9a-fA-F]{64}$').hasMatch(key1), isTrue);

      // Subsequent retrieval returns the same stored key
      final key2 = await keyManager.getOrCreateDatabaseKey();
      expect(key2, equals(key1));
    });

    test('generates unique keys across different device resets (not hardcoded)', () async {
      FlutterSecureStorage.setMockInitialValues({});
      final keyManager1 = DatabaseEncryptionKeyManager();
      final key1 = await keyManager1.getOrCreateDatabaseKey();

      await keyManager1.clearKeyOnSecurityReset();

      final key2 = await keyManager1.getOrCreateDatabaseKey();
      expect(key2, isNot(equals(key1)));
      expect(key2.length, equals(64));
    });

    test('applies PRAGMA key to underlying database connection', () {
      final mockDb = _MockRawDatabase();
      const testKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

      DatabaseEncryptionKeyManager.applyEncryptionPragma(mockDb, testKey);

      expect(mockDb.executedQueries.length, equals(1));
      expect(mockDb.executedQueries.first, equals("PRAGMA key = '$testKey';"));
    });

    test('handles quotes safely when applying encryption pragma', () {
      final mockDb = _MockRawDatabase();
      const trickyKey = "key'with'quotes";

      DatabaseEncryptionKeyManager.applyEncryptionPragma(mockDb, trickyKey);

      expect(mockDb.executedQueries.first, equals("PRAGMA key = 'key''with''quotes';"));
    });
  });
}
