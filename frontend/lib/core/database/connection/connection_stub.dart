import 'package:drift/drift.dart';
import '../database_encryption.dart';

QueryExecutor openConnection([DatabaseEncryptionKeyManager? keyManager]) {
  throw UnsupportedError('Platform not supported for database');
}
