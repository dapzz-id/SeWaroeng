// ignore_for_file: deprecated_member_use

import 'package:drift/drift.dart';
import 'package:drift/web.dart';
import '../database_encryption.dart';

QueryExecutor openConnection([DatabaseEncryptionKeyManager? keyManager]) {
  return WebDatabase('sewaroeng_local');
}
