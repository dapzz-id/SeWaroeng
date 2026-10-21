import 'dart:io';
import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as p;
import '../database_encryption.dart';

QueryExecutor openConnection([DatabaseEncryptionKeyManager? keyManager]) {
  return LazyDatabase(() async {
    final dbFolder = await getApplicationDocumentsDirectory();
    final file = File(p.join(dbFolder.path, 'sewaroeng_local.sqlite'));
    final km = keyManager ?? DatabaseEncryptionKeyManager();
    final encryptionKey = await km.getOrCreateDatabaseKey();
    return NativeDatabase.createInBackground(
      file,
      setup: (rawDb) {
        DatabaseEncryptionKeyManager.applyEncryptionPragma(rawDb, encryptionKey);
      },
    );
  });
}
