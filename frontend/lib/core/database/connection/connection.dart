import 'package:drift/drift.dart';
import '../database_encryption.dart';

import 'connection_stub.dart'
    if (dart.library.io) 'connection_native.dart'
    if (dart.library.html) 'connection_web.dart'
    if (dart.library.js_interop) 'connection_web.dart' as impl;

QueryExecutor openConnection([DatabaseEncryptionKeyManager? keyManager]) =>
    impl.openConnection(keyManager);
