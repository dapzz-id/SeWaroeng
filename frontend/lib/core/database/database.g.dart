// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'database.dart';

// ignore_for_file: type=lint
class $SyncOutboxTableTable extends SyncOutboxTable
    with TableInfo<$SyncOutboxTableTable, SyncOutboxTableData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $SyncOutboxTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _commandTypeMeta = const VerificationMeta(
    'commandType',
  );
  @override
  late final GeneratedColumn<String> commandType = GeneratedColumn<String>(
    'command_type',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _aggregateTypeMeta = const VerificationMeta(
    'aggregateType',
  );
  @override
  late final GeneratedColumn<String> aggregateType = GeneratedColumn<String>(
    'aggregate_type',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _aggregateIdMeta = const VerificationMeta(
    'aggregateId',
  );
  @override
  late final GeneratedColumn<String> aggregateId = GeneratedColumn<String>(
    'aggregate_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _payloadMeta = const VerificationMeta(
    'payload',
  );
  @override
  late final GeneratedColumn<String> payload = GeneratedColumn<String>(
    'payload',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<DateTime> createdAt = GeneratedColumn<DateTime>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _attemptCountMeta = const VerificationMeta(
    'attemptCount',
  );
  @override
  late final GeneratedColumn<int> attemptCount = GeneratedColumn<int>(
    'attempt_count',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _nextAttemptAtMeta = const VerificationMeta(
    'nextAttemptAt',
  );
  @override
  late final GeneratedColumn<DateTime> nextAttemptAt =
      GeneratedColumn<DateTime>(
        'next_attempt_at',
        aliasedName,
        true,
        type: DriftSqlType.dateTime,
        requiredDuringInsert: false,
      );
  static const VerificationMeta _stateMeta = const VerificationMeta('state');
  @override
  late final GeneratedColumn<String> state = GeneratedColumn<String>(
    'state',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('pending'),
  );
  static const VerificationMeta _lastErrorMeta = const VerificationMeta(
    'lastError',
  );
  @override
  late final GeneratedColumn<String> lastError = GeneratedColumn<String>(
    'last_error',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _causationIdMeta = const VerificationMeta(
    'causationId',
  );
  @override
  late final GeneratedColumn<String> causationId = GeneratedColumn<String>(
    'causation_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _sequenceNumberMeta = const VerificationMeta(
    'sequenceNumber',
  );
  @override
  late final GeneratedColumn<int> sequenceNumber = GeneratedColumn<int>(
    'sequence_number',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    commandType,
    aggregateType,
    aggregateId,
    payload,
    createdAt,
    attemptCount,
    nextAttemptAt,
    state,
    lastError,
    causationId,
    sequenceNumber,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'sync_outbox';
  @override
  VerificationContext validateIntegrity(
    Insertable<SyncOutboxTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('command_type')) {
      context.handle(
        _commandTypeMeta,
        commandType.isAcceptableOrUnknown(
          data['command_type']!,
          _commandTypeMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_commandTypeMeta);
    }
    if (data.containsKey('aggregate_type')) {
      context.handle(
        _aggregateTypeMeta,
        aggregateType.isAcceptableOrUnknown(
          data['aggregate_type']!,
          _aggregateTypeMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_aggregateTypeMeta);
    }
    if (data.containsKey('aggregate_id')) {
      context.handle(
        _aggregateIdMeta,
        aggregateId.isAcceptableOrUnknown(
          data['aggregate_id']!,
          _aggregateIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_aggregateIdMeta);
    }
    if (data.containsKey('payload')) {
      context.handle(
        _payloadMeta,
        payload.isAcceptableOrUnknown(data['payload']!, _payloadMeta),
      );
    } else if (isInserting) {
      context.missing(_payloadMeta);
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    if (data.containsKey('attempt_count')) {
      context.handle(
        _attemptCountMeta,
        attemptCount.isAcceptableOrUnknown(
          data['attempt_count']!,
          _attemptCountMeta,
        ),
      );
    }
    if (data.containsKey('next_attempt_at')) {
      context.handle(
        _nextAttemptAtMeta,
        nextAttemptAt.isAcceptableOrUnknown(
          data['next_attempt_at']!,
          _nextAttemptAtMeta,
        ),
      );
    }
    if (data.containsKey('state')) {
      context.handle(
        _stateMeta,
        state.isAcceptableOrUnknown(data['state']!, _stateMeta),
      );
    }
    if (data.containsKey('last_error')) {
      context.handle(
        _lastErrorMeta,
        lastError.isAcceptableOrUnknown(data['last_error']!, _lastErrorMeta),
      );
    }
    if (data.containsKey('causation_id')) {
      context.handle(
        _causationIdMeta,
        causationId.isAcceptableOrUnknown(
          data['causation_id']!,
          _causationIdMeta,
        ),
      );
    }
    if (data.containsKey('sequence_number')) {
      context.handle(
        _sequenceNumberMeta,
        sequenceNumber.isAcceptableOrUnknown(
          data['sequence_number']!,
          _sequenceNumberMeta,
        ),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  SyncOutboxTableData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return SyncOutboxTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      commandType: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}command_type'],
      )!,
      aggregateType: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}aggregate_type'],
      )!,
      aggregateId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}aggregate_id'],
      )!,
      payload: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}payload'],
      )!,
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}created_at'],
      )!,
      attemptCount: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}attempt_count'],
      )!,
      nextAttemptAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}next_attempt_at'],
      ),
      state: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}state'],
      )!,
      lastError: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}last_error'],
      ),
      causationId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}causation_id'],
      ),
      sequenceNumber: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}sequence_number'],
      )!,
    );
  }

  @override
  $SyncOutboxTableTable createAlias(String alias) {
    return $SyncOutboxTableTable(attachedDatabase, alias);
  }
}

class SyncOutboxTableData extends DataClass
    implements Insertable<SyncOutboxTableData> {
  /// Client-generated UUIDv7 command identifier
  final String id;

  /// e.g. 'sales.finalize', 'sales.create'
  final String commandType;

  /// e.g. 'sale'
  final String aggregateType;

  /// Aggregate root UUIDv7
  final String aggregateId;

  /// JSON serialized command payload
  final String payload;

  /// Creation timestamp
  final DateTime createdAt;

  /// Number of sync attempts performed
  final int attemptCount;

  /// Next retry scheduled timestamp for backoff
  final DateTime? nextAttemptAt;

  /// State enum value: pending, uploading, synced, failed, conflict, requires_action
  final String state;

  /// Last error message from server or network
  final String? lastError;

  /// Parent command ID for causal ordering dependencies
  final String? causationId;

  /// Monotonically increasing sequence number per device
  final int sequenceNumber;
  const SyncOutboxTableData({
    required this.id,
    required this.commandType,
    required this.aggregateType,
    required this.aggregateId,
    required this.payload,
    required this.createdAt,
    required this.attemptCount,
    this.nextAttemptAt,
    required this.state,
    this.lastError,
    this.causationId,
    required this.sequenceNumber,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['command_type'] = Variable<String>(commandType);
    map['aggregate_type'] = Variable<String>(aggregateType);
    map['aggregate_id'] = Variable<String>(aggregateId);
    map['payload'] = Variable<String>(payload);
    map['created_at'] = Variable<DateTime>(createdAt);
    map['attempt_count'] = Variable<int>(attemptCount);
    if (!nullToAbsent || nextAttemptAt != null) {
      map['next_attempt_at'] = Variable<DateTime>(nextAttemptAt);
    }
    map['state'] = Variable<String>(state);
    if (!nullToAbsent || lastError != null) {
      map['last_error'] = Variable<String>(lastError);
    }
    if (!nullToAbsent || causationId != null) {
      map['causation_id'] = Variable<String>(causationId);
    }
    map['sequence_number'] = Variable<int>(sequenceNumber);
    return map;
  }

  SyncOutboxTableCompanion toCompanion(bool nullToAbsent) {
    return SyncOutboxTableCompanion(
      id: Value(id),
      commandType: Value(commandType),
      aggregateType: Value(aggregateType),
      aggregateId: Value(aggregateId),
      payload: Value(payload),
      createdAt: Value(createdAt),
      attemptCount: Value(attemptCount),
      nextAttemptAt: nextAttemptAt == null && nullToAbsent
          ? const Value.absent()
          : Value(nextAttemptAt),
      state: Value(state),
      lastError: lastError == null && nullToAbsent
          ? const Value.absent()
          : Value(lastError),
      causationId: causationId == null && nullToAbsent
          ? const Value.absent()
          : Value(causationId),
      sequenceNumber: Value(sequenceNumber),
    );
  }

  factory SyncOutboxTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return SyncOutboxTableData(
      id: serializer.fromJson<String>(json['id']),
      commandType: serializer.fromJson<String>(json['commandType']),
      aggregateType: serializer.fromJson<String>(json['aggregateType']),
      aggregateId: serializer.fromJson<String>(json['aggregateId']),
      payload: serializer.fromJson<String>(json['payload']),
      createdAt: serializer.fromJson<DateTime>(json['createdAt']),
      attemptCount: serializer.fromJson<int>(json['attemptCount']),
      nextAttemptAt: serializer.fromJson<DateTime?>(json['nextAttemptAt']),
      state: serializer.fromJson<String>(json['state']),
      lastError: serializer.fromJson<String?>(json['lastError']),
      causationId: serializer.fromJson<String?>(json['causationId']),
      sequenceNumber: serializer.fromJson<int>(json['sequenceNumber']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'commandType': serializer.toJson<String>(commandType),
      'aggregateType': serializer.toJson<String>(aggregateType),
      'aggregateId': serializer.toJson<String>(aggregateId),
      'payload': serializer.toJson<String>(payload),
      'createdAt': serializer.toJson<DateTime>(createdAt),
      'attemptCount': serializer.toJson<int>(attemptCount),
      'nextAttemptAt': serializer.toJson<DateTime?>(nextAttemptAt),
      'state': serializer.toJson<String>(state),
      'lastError': serializer.toJson<String?>(lastError),
      'causationId': serializer.toJson<String?>(causationId),
      'sequenceNumber': serializer.toJson<int>(sequenceNumber),
    };
  }

  SyncOutboxTableData copyWith({
    String? id,
    String? commandType,
    String? aggregateType,
    String? aggregateId,
    String? payload,
    DateTime? createdAt,
    int? attemptCount,
    Value<DateTime?> nextAttemptAt = const Value.absent(),
    String? state,
    Value<String?> lastError = const Value.absent(),
    Value<String?> causationId = const Value.absent(),
    int? sequenceNumber,
  }) => SyncOutboxTableData(
    id: id ?? this.id,
    commandType: commandType ?? this.commandType,
    aggregateType: aggregateType ?? this.aggregateType,
    aggregateId: aggregateId ?? this.aggregateId,
    payload: payload ?? this.payload,
    createdAt: createdAt ?? this.createdAt,
    attemptCount: attemptCount ?? this.attemptCount,
    nextAttemptAt: nextAttemptAt.present
        ? nextAttemptAt.value
        : this.nextAttemptAt,
    state: state ?? this.state,
    lastError: lastError.present ? lastError.value : this.lastError,
    causationId: causationId.present ? causationId.value : this.causationId,
    sequenceNumber: sequenceNumber ?? this.sequenceNumber,
  );
  SyncOutboxTableData copyWithCompanion(SyncOutboxTableCompanion data) {
    return SyncOutboxTableData(
      id: data.id.present ? data.id.value : this.id,
      commandType: data.commandType.present
          ? data.commandType.value
          : this.commandType,
      aggregateType: data.aggregateType.present
          ? data.aggregateType.value
          : this.aggregateType,
      aggregateId: data.aggregateId.present
          ? data.aggregateId.value
          : this.aggregateId,
      payload: data.payload.present ? data.payload.value : this.payload,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
      attemptCount: data.attemptCount.present
          ? data.attemptCount.value
          : this.attemptCount,
      nextAttemptAt: data.nextAttemptAt.present
          ? data.nextAttemptAt.value
          : this.nextAttemptAt,
      state: data.state.present ? data.state.value : this.state,
      lastError: data.lastError.present ? data.lastError.value : this.lastError,
      causationId: data.causationId.present
          ? data.causationId.value
          : this.causationId,
      sequenceNumber: data.sequenceNumber.present
          ? data.sequenceNumber.value
          : this.sequenceNumber,
    );
  }

  @override
  String toString() {
    return (StringBuffer('SyncOutboxTableData(')
          ..write('id: $id, ')
          ..write('commandType: $commandType, ')
          ..write('aggregateType: $aggregateType, ')
          ..write('aggregateId: $aggregateId, ')
          ..write('payload: $payload, ')
          ..write('createdAt: $createdAt, ')
          ..write('attemptCount: $attemptCount, ')
          ..write('nextAttemptAt: $nextAttemptAt, ')
          ..write('state: $state, ')
          ..write('lastError: $lastError, ')
          ..write('causationId: $causationId, ')
          ..write('sequenceNumber: $sequenceNumber')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    commandType,
    aggregateType,
    aggregateId,
    payload,
    createdAt,
    attemptCount,
    nextAttemptAt,
    state,
    lastError,
    causationId,
    sequenceNumber,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is SyncOutboxTableData &&
          other.id == this.id &&
          other.commandType == this.commandType &&
          other.aggregateType == this.aggregateType &&
          other.aggregateId == this.aggregateId &&
          other.payload == this.payload &&
          other.createdAt == this.createdAt &&
          other.attemptCount == this.attemptCount &&
          other.nextAttemptAt == this.nextAttemptAt &&
          other.state == this.state &&
          other.lastError == this.lastError &&
          other.causationId == this.causationId &&
          other.sequenceNumber == this.sequenceNumber);
}

class SyncOutboxTableCompanion extends UpdateCompanion<SyncOutboxTableData> {
  final Value<String> id;
  final Value<String> commandType;
  final Value<String> aggregateType;
  final Value<String> aggregateId;
  final Value<String> payload;
  final Value<DateTime> createdAt;
  final Value<int> attemptCount;
  final Value<DateTime?> nextAttemptAt;
  final Value<String> state;
  final Value<String?> lastError;
  final Value<String?> causationId;
  final Value<int> sequenceNumber;
  final Value<int> rowid;
  const SyncOutboxTableCompanion({
    this.id = const Value.absent(),
    this.commandType = const Value.absent(),
    this.aggregateType = const Value.absent(),
    this.aggregateId = const Value.absent(),
    this.payload = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.attemptCount = const Value.absent(),
    this.nextAttemptAt = const Value.absent(),
    this.state = const Value.absent(),
    this.lastError = const Value.absent(),
    this.causationId = const Value.absent(),
    this.sequenceNumber = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  SyncOutboxTableCompanion.insert({
    required String id,
    required String commandType,
    required String aggregateType,
    required String aggregateId,
    required String payload,
    required DateTime createdAt,
    this.attemptCount = const Value.absent(),
    this.nextAttemptAt = const Value.absent(),
    this.state = const Value.absent(),
    this.lastError = const Value.absent(),
    this.causationId = const Value.absent(),
    this.sequenceNumber = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       commandType = Value(commandType),
       aggregateType = Value(aggregateType),
       aggregateId = Value(aggregateId),
       payload = Value(payload),
       createdAt = Value(createdAt);
  static Insertable<SyncOutboxTableData> custom({
    Expression<String>? id,
    Expression<String>? commandType,
    Expression<String>? aggregateType,
    Expression<String>? aggregateId,
    Expression<String>? payload,
    Expression<DateTime>? createdAt,
    Expression<int>? attemptCount,
    Expression<DateTime>? nextAttemptAt,
    Expression<String>? state,
    Expression<String>? lastError,
    Expression<String>? causationId,
    Expression<int>? sequenceNumber,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (commandType != null) 'command_type': commandType,
      if (aggregateType != null) 'aggregate_type': aggregateType,
      if (aggregateId != null) 'aggregate_id': aggregateId,
      if (payload != null) 'payload': payload,
      if (createdAt != null) 'created_at': createdAt,
      if (attemptCount != null) 'attempt_count': attemptCount,
      if (nextAttemptAt != null) 'next_attempt_at': nextAttemptAt,
      if (state != null) 'state': state,
      if (lastError != null) 'last_error': lastError,
      if (causationId != null) 'causation_id': causationId,
      if (sequenceNumber != null) 'sequence_number': sequenceNumber,
      if (rowid != null) 'rowid': rowid,
    });
  }

  SyncOutboxTableCompanion copyWith({
    Value<String>? id,
    Value<String>? commandType,
    Value<String>? aggregateType,
    Value<String>? aggregateId,
    Value<String>? payload,
    Value<DateTime>? createdAt,
    Value<int>? attemptCount,
    Value<DateTime?>? nextAttemptAt,
    Value<String>? state,
    Value<String?>? lastError,
    Value<String?>? causationId,
    Value<int>? sequenceNumber,
    Value<int>? rowid,
  }) {
    return SyncOutboxTableCompanion(
      id: id ?? this.id,
      commandType: commandType ?? this.commandType,
      aggregateType: aggregateType ?? this.aggregateType,
      aggregateId: aggregateId ?? this.aggregateId,
      payload: payload ?? this.payload,
      createdAt: createdAt ?? this.createdAt,
      attemptCount: attemptCount ?? this.attemptCount,
      nextAttemptAt: nextAttemptAt ?? this.nextAttemptAt,
      state: state ?? this.state,
      lastError: lastError ?? this.lastError,
      causationId: causationId ?? this.causationId,
      sequenceNumber: sequenceNumber ?? this.sequenceNumber,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (commandType.present) {
      map['command_type'] = Variable<String>(commandType.value);
    }
    if (aggregateType.present) {
      map['aggregate_type'] = Variable<String>(aggregateType.value);
    }
    if (aggregateId.present) {
      map['aggregate_id'] = Variable<String>(aggregateId.value);
    }
    if (payload.present) {
      map['payload'] = Variable<String>(payload.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<DateTime>(createdAt.value);
    }
    if (attemptCount.present) {
      map['attempt_count'] = Variable<int>(attemptCount.value);
    }
    if (nextAttemptAt.present) {
      map['next_attempt_at'] = Variable<DateTime>(nextAttemptAt.value);
    }
    if (state.present) {
      map['state'] = Variable<String>(state.value);
    }
    if (lastError.present) {
      map['last_error'] = Variable<String>(lastError.value);
    }
    if (causationId.present) {
      map['causation_id'] = Variable<String>(causationId.value);
    }
    if (sequenceNumber.present) {
      map['sequence_number'] = Variable<int>(sequenceNumber.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('SyncOutboxTableCompanion(')
          ..write('id: $id, ')
          ..write('commandType: $commandType, ')
          ..write('aggregateType: $aggregateType, ')
          ..write('aggregateId: $aggregateId, ')
          ..write('payload: $payload, ')
          ..write('createdAt: $createdAt, ')
          ..write('attemptCount: $attemptCount, ')
          ..write('nextAttemptAt: $nextAttemptAt, ')
          ..write('state: $state, ')
          ..write('lastError: $lastError, ')
          ..write('causationId: $causationId, ')
          ..write('sequenceNumber: $sequenceNumber, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $SessionStateTableTable extends SessionStateTable
    with TableInfo<$SessionStateTableTable, SessionStateTableData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $SessionStateTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _keyMeta = const VerificationMeta('key');
  @override
  late final GeneratedColumn<String> key = GeneratedColumn<String>(
    'key',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _valueMeta = const VerificationMeta('value');
  @override
  late final GeneratedColumn<String> value = GeneratedColumn<String>(
    'value',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [key, value, updatedAt];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'session_state';
  @override
  VerificationContext validateIntegrity(
    Insertable<SessionStateTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('key')) {
      context.handle(
        _keyMeta,
        key.isAcceptableOrUnknown(data['key']!, _keyMeta),
      );
    } else if (isInserting) {
      context.missing(_keyMeta);
    }
    if (data.containsKey('value')) {
      context.handle(
        _valueMeta,
        value.isAcceptableOrUnknown(data['value']!, _valueMeta),
      );
    } else if (isInserting) {
      context.missing(_valueMeta);
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {key};
  @override
  SessionStateTableData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return SessionStateTableData(
      key: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}key'],
      )!,
      value: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}value'],
      )!,
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      )!,
    );
  }

  @override
  $SessionStateTableTable createAlias(String alias) {
    return $SessionStateTableTable(attachedDatabase, alias);
  }
}

class SessionStateTableData extends DataClass
    implements Insertable<SessionStateTableData> {
  final String key;
  final String value;
  final DateTime updatedAt;
  const SessionStateTableData({
    required this.key,
    required this.value,
    required this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['key'] = Variable<String>(key);
    map['value'] = Variable<String>(value);
    map['updated_at'] = Variable<DateTime>(updatedAt);
    return map;
  }

  SessionStateTableCompanion toCompanion(bool nullToAbsent) {
    return SessionStateTableCompanion(
      key: Value(key),
      value: Value(value),
      updatedAt: Value(updatedAt),
    );
  }

  factory SessionStateTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return SessionStateTableData(
      key: serializer.fromJson<String>(json['key']),
      value: serializer.fromJson<String>(json['value']),
      updatedAt: serializer.fromJson<DateTime>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'key': serializer.toJson<String>(key),
      'value': serializer.toJson<String>(value),
      'updatedAt': serializer.toJson<DateTime>(updatedAt),
    };
  }

  SessionStateTableData copyWith({
    String? key,
    String? value,
    DateTime? updatedAt,
  }) => SessionStateTableData(
    key: key ?? this.key,
    value: value ?? this.value,
    updatedAt: updatedAt ?? this.updatedAt,
  );
  SessionStateTableData copyWithCompanion(SessionStateTableCompanion data) {
    return SessionStateTableData(
      key: data.key.present ? data.key.value : this.key,
      value: data.value.present ? data.value.value : this.value,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('SessionStateTableData(')
          ..write('key: $key, ')
          ..write('value: $value, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(key, value, updatedAt);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is SessionStateTableData &&
          other.key == this.key &&
          other.value == this.value &&
          other.updatedAt == this.updatedAt);
}

class SessionStateTableCompanion
    extends UpdateCompanion<SessionStateTableData> {
  final Value<String> key;
  final Value<String> value;
  final Value<DateTime> updatedAt;
  final Value<int> rowid;
  const SessionStateTableCompanion({
    this.key = const Value.absent(),
    this.value = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  SessionStateTableCompanion.insert({
    required String key,
    required String value,
    required DateTime updatedAt,
    this.rowid = const Value.absent(),
  }) : key = Value(key),
       value = Value(value),
       updatedAt = Value(updatedAt);
  static Insertable<SessionStateTableData> custom({
    Expression<String>? key,
    Expression<String>? value,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (key != null) 'key': key,
      if (value != null) 'value': value,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  SessionStateTableCompanion copyWith({
    Value<String>? key,
    Value<String>? value,
    Value<DateTime>? updatedAt,
    Value<int>? rowid,
  }) {
    return SessionStateTableCompanion(
      key: key ?? this.key,
      value: value ?? this.value,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (key.present) {
      map['key'] = Variable<String>(key.value);
    }
    if (value.present) {
      map['value'] = Variable<String>(value.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('SessionStateTableCompanion(')
          ..write('key: $key, ')
          ..write('value: $value, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $SyncMetadataTableTable extends SyncMetadataTable
    with TableInfo<$SyncMetadataTableTable, SyncMetadataTableData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $SyncMetadataTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _keyMeta = const VerificationMeta('key');
  @override
  late final GeneratedColumn<String> key = GeneratedColumn<String>(
    'key',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _lastSyncedAtMeta = const VerificationMeta(
    'lastSyncedAt',
  );
  @override
  late final GeneratedColumn<DateTime> lastSyncedAt = GeneratedColumn<DateTime>(
    'last_synced_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _cursorMeta = const VerificationMeta('cursor');
  @override
  late final GeneratedColumn<String> cursor = GeneratedColumn<String>(
    'cursor',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [key, lastSyncedAt, cursor];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'sync_metadata';
  @override
  VerificationContext validateIntegrity(
    Insertable<SyncMetadataTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('key')) {
      context.handle(
        _keyMeta,
        key.isAcceptableOrUnknown(data['key']!, _keyMeta),
      );
    } else if (isInserting) {
      context.missing(_keyMeta);
    }
    if (data.containsKey('last_synced_at')) {
      context.handle(
        _lastSyncedAtMeta,
        lastSyncedAt.isAcceptableOrUnknown(
          data['last_synced_at']!,
          _lastSyncedAtMeta,
        ),
      );
    }
    if (data.containsKey('cursor')) {
      context.handle(
        _cursorMeta,
        cursor.isAcceptableOrUnknown(data['cursor']!, _cursorMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {key};
  @override
  SyncMetadataTableData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return SyncMetadataTableData(
      key: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}key'],
      )!,
      lastSyncedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}last_synced_at'],
      ),
      cursor: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}cursor'],
      ),
    );
  }

  @override
  $SyncMetadataTableTable createAlias(String alias) {
    return $SyncMetadataTableTable(attachedDatabase, alias);
  }
}

class SyncMetadataTableData extends DataClass
    implements Insertable<SyncMetadataTableData> {
  final String key;
  final DateTime? lastSyncedAt;
  final String? cursor;
  const SyncMetadataTableData({
    required this.key,
    this.lastSyncedAt,
    this.cursor,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['key'] = Variable<String>(key);
    if (!nullToAbsent || lastSyncedAt != null) {
      map['last_synced_at'] = Variable<DateTime>(lastSyncedAt);
    }
    if (!nullToAbsent || cursor != null) {
      map['cursor'] = Variable<String>(cursor);
    }
    return map;
  }

  SyncMetadataTableCompanion toCompanion(bool nullToAbsent) {
    return SyncMetadataTableCompanion(
      key: Value(key),
      lastSyncedAt: lastSyncedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(lastSyncedAt),
      cursor: cursor == null && nullToAbsent
          ? const Value.absent()
          : Value(cursor),
    );
  }

  factory SyncMetadataTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return SyncMetadataTableData(
      key: serializer.fromJson<String>(json['key']),
      lastSyncedAt: serializer.fromJson<DateTime?>(json['lastSyncedAt']),
      cursor: serializer.fromJson<String?>(json['cursor']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'key': serializer.toJson<String>(key),
      'lastSyncedAt': serializer.toJson<DateTime?>(lastSyncedAt),
      'cursor': serializer.toJson<String?>(cursor),
    };
  }

  SyncMetadataTableData copyWith({
    String? key,
    Value<DateTime?> lastSyncedAt = const Value.absent(),
    Value<String?> cursor = const Value.absent(),
  }) => SyncMetadataTableData(
    key: key ?? this.key,
    lastSyncedAt: lastSyncedAt.present ? lastSyncedAt.value : this.lastSyncedAt,
    cursor: cursor.present ? cursor.value : this.cursor,
  );
  SyncMetadataTableData copyWithCompanion(SyncMetadataTableCompanion data) {
    return SyncMetadataTableData(
      key: data.key.present ? data.key.value : this.key,
      lastSyncedAt: data.lastSyncedAt.present
          ? data.lastSyncedAt.value
          : this.lastSyncedAt,
      cursor: data.cursor.present ? data.cursor.value : this.cursor,
    );
  }

  @override
  String toString() {
    return (StringBuffer('SyncMetadataTableData(')
          ..write('key: $key, ')
          ..write('lastSyncedAt: $lastSyncedAt, ')
          ..write('cursor: $cursor')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(key, lastSyncedAt, cursor);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is SyncMetadataTableData &&
          other.key == this.key &&
          other.lastSyncedAt == this.lastSyncedAt &&
          other.cursor == this.cursor);
}

class SyncMetadataTableCompanion
    extends UpdateCompanion<SyncMetadataTableData> {
  final Value<String> key;
  final Value<DateTime?> lastSyncedAt;
  final Value<String?> cursor;
  final Value<int> rowid;
  const SyncMetadataTableCompanion({
    this.key = const Value.absent(),
    this.lastSyncedAt = const Value.absent(),
    this.cursor = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  SyncMetadataTableCompanion.insert({
    required String key,
    this.lastSyncedAt = const Value.absent(),
    this.cursor = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : key = Value(key);
  static Insertable<SyncMetadataTableData> custom({
    Expression<String>? key,
    Expression<DateTime>? lastSyncedAt,
    Expression<String>? cursor,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (key != null) 'key': key,
      if (lastSyncedAt != null) 'last_synced_at': lastSyncedAt,
      if (cursor != null) 'cursor': cursor,
      if (rowid != null) 'rowid': rowid,
    });
  }

  SyncMetadataTableCompanion copyWith({
    Value<String>? key,
    Value<DateTime?>? lastSyncedAt,
    Value<String?>? cursor,
    Value<int>? rowid,
  }) {
    return SyncMetadataTableCompanion(
      key: key ?? this.key,
      lastSyncedAt: lastSyncedAt ?? this.lastSyncedAt,
      cursor: cursor ?? this.cursor,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (key.present) {
      map['key'] = Variable<String>(key.value);
    }
    if (lastSyncedAt.present) {
      map['last_synced_at'] = Variable<DateTime>(lastSyncedAt.value);
    }
    if (cursor.present) {
      map['cursor'] = Variable<String>(cursor.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('SyncMetadataTableCompanion(')
          ..write('key: $key, ')
          ..write('lastSyncedAt: $lastSyncedAt, ')
          ..write('cursor: $cursor, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalProductSnapshotsTableTable extends LocalProductSnapshotsTable
    with
        TableInfo<
          $LocalProductSnapshotsTableTable,
          LocalProductSnapshotsTableData
        > {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalProductSnapshotsTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _tenantIdMeta = const VerificationMeta(
    'tenantId',
  );
  @override
  late final GeneratedColumn<String> tenantId = GeneratedColumn<String>(
    'tenant_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _categoryIdMeta = const VerificationMeta(
    'categoryId',
  );
  @override
  late final GeneratedColumn<String> categoryId = GeneratedColumn<String>(
    'category_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _categoryNameMeta = const VerificationMeta(
    'categoryName',
  );
  @override
  late final GeneratedColumn<String> categoryName = GeneratedColumn<String>(
    'category_name',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _descriptionMeta = const VerificationMeta(
    'description',
  );
  @override
  late final GeneratedColumn<String> description = GeneratedColumn<String>(
    'description',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('active'),
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    tenantId,
    categoryId,
    categoryName,
    name,
    description,
    status,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_product_snapshots';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalProductSnapshotsTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('tenant_id')) {
      context.handle(
        _tenantIdMeta,
        tenantId.isAcceptableOrUnknown(data['tenant_id']!, _tenantIdMeta),
      );
    } else if (isInserting) {
      context.missing(_tenantIdMeta);
    }
    if (data.containsKey('category_id')) {
      context.handle(
        _categoryIdMeta,
        categoryId.isAcceptableOrUnknown(data['category_id']!, _categoryIdMeta),
      );
    }
    if (data.containsKey('category_name')) {
      context.handle(
        _categoryNameMeta,
        categoryName.isAcceptableOrUnknown(
          data['category_name']!,
          _categoryNameMeta,
        ),
      );
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('description')) {
      context.handle(
        _descriptionMeta,
        description.isAcceptableOrUnknown(
          data['description']!,
          _descriptionMeta,
        ),
      );
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalProductSnapshotsTableData map(
    Map<String, dynamic> data, {
    String? tablePrefix,
  }) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalProductSnapshotsTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      tenantId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}tenant_id'],
      )!,
      categoryId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}category_id'],
      ),
      categoryName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}category_name'],
      ),
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      description: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}description'],
      ),
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      )!,
    );
  }

  @override
  $LocalProductSnapshotsTableTable createAlias(String alias) {
    return $LocalProductSnapshotsTableTable(attachedDatabase, alias);
  }
}

class LocalProductSnapshotsTableData extends DataClass
    implements Insertable<LocalProductSnapshotsTableData> {
  final String id;
  final String tenantId;
  final String? categoryId;
  final String? categoryName;
  final String name;
  final String? description;
  final String status;
  final DateTime updatedAt;
  const LocalProductSnapshotsTableData({
    required this.id,
    required this.tenantId,
    this.categoryId,
    this.categoryName,
    required this.name,
    this.description,
    required this.status,
    required this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['tenant_id'] = Variable<String>(tenantId);
    if (!nullToAbsent || categoryId != null) {
      map['category_id'] = Variable<String>(categoryId);
    }
    if (!nullToAbsent || categoryName != null) {
      map['category_name'] = Variable<String>(categoryName);
    }
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || description != null) {
      map['description'] = Variable<String>(description);
    }
    map['status'] = Variable<String>(status);
    map['updated_at'] = Variable<DateTime>(updatedAt);
    return map;
  }

  LocalProductSnapshotsTableCompanion toCompanion(bool nullToAbsent) {
    return LocalProductSnapshotsTableCompanion(
      id: Value(id),
      tenantId: Value(tenantId),
      categoryId: categoryId == null && nullToAbsent
          ? const Value.absent()
          : Value(categoryId),
      categoryName: categoryName == null && nullToAbsent
          ? const Value.absent()
          : Value(categoryName),
      name: Value(name),
      description: description == null && nullToAbsent
          ? const Value.absent()
          : Value(description),
      status: Value(status),
      updatedAt: Value(updatedAt),
    );
  }

  factory LocalProductSnapshotsTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalProductSnapshotsTableData(
      id: serializer.fromJson<String>(json['id']),
      tenantId: serializer.fromJson<String>(json['tenantId']),
      categoryId: serializer.fromJson<String?>(json['categoryId']),
      categoryName: serializer.fromJson<String?>(json['categoryName']),
      name: serializer.fromJson<String>(json['name']),
      description: serializer.fromJson<String?>(json['description']),
      status: serializer.fromJson<String>(json['status']),
      updatedAt: serializer.fromJson<DateTime>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'tenantId': serializer.toJson<String>(tenantId),
      'categoryId': serializer.toJson<String?>(categoryId),
      'categoryName': serializer.toJson<String?>(categoryName),
      'name': serializer.toJson<String>(name),
      'description': serializer.toJson<String?>(description),
      'status': serializer.toJson<String>(status),
      'updatedAt': serializer.toJson<DateTime>(updatedAt),
    };
  }

  LocalProductSnapshotsTableData copyWith({
    String? id,
    String? tenantId,
    Value<String?> categoryId = const Value.absent(),
    Value<String?> categoryName = const Value.absent(),
    String? name,
    Value<String?> description = const Value.absent(),
    String? status,
    DateTime? updatedAt,
  }) => LocalProductSnapshotsTableData(
    id: id ?? this.id,
    tenantId: tenantId ?? this.tenantId,
    categoryId: categoryId.present ? categoryId.value : this.categoryId,
    categoryName: categoryName.present ? categoryName.value : this.categoryName,
    name: name ?? this.name,
    description: description.present ? description.value : this.description,
    status: status ?? this.status,
    updatedAt: updatedAt ?? this.updatedAt,
  );
  LocalProductSnapshotsTableData copyWithCompanion(
    LocalProductSnapshotsTableCompanion data,
  ) {
    return LocalProductSnapshotsTableData(
      id: data.id.present ? data.id.value : this.id,
      tenantId: data.tenantId.present ? data.tenantId.value : this.tenantId,
      categoryId: data.categoryId.present
          ? data.categoryId.value
          : this.categoryId,
      categoryName: data.categoryName.present
          ? data.categoryName.value
          : this.categoryName,
      name: data.name.present ? data.name.value : this.name,
      description: data.description.present
          ? data.description.value
          : this.description,
      status: data.status.present ? data.status.value : this.status,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalProductSnapshotsTableData(')
          ..write('id: $id, ')
          ..write('tenantId: $tenantId, ')
          ..write('categoryId: $categoryId, ')
          ..write('categoryName: $categoryName, ')
          ..write('name: $name, ')
          ..write('description: $description, ')
          ..write('status: $status, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    tenantId,
    categoryId,
    categoryName,
    name,
    description,
    status,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalProductSnapshotsTableData &&
          other.id == this.id &&
          other.tenantId == this.tenantId &&
          other.categoryId == this.categoryId &&
          other.categoryName == this.categoryName &&
          other.name == this.name &&
          other.description == this.description &&
          other.status == this.status &&
          other.updatedAt == this.updatedAt);
}

class LocalProductSnapshotsTableCompanion
    extends UpdateCompanion<LocalProductSnapshotsTableData> {
  final Value<String> id;
  final Value<String> tenantId;
  final Value<String?> categoryId;
  final Value<String?> categoryName;
  final Value<String> name;
  final Value<String?> description;
  final Value<String> status;
  final Value<DateTime> updatedAt;
  final Value<int> rowid;
  const LocalProductSnapshotsTableCompanion({
    this.id = const Value.absent(),
    this.tenantId = const Value.absent(),
    this.categoryId = const Value.absent(),
    this.categoryName = const Value.absent(),
    this.name = const Value.absent(),
    this.description = const Value.absent(),
    this.status = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalProductSnapshotsTableCompanion.insert({
    required String id,
    required String tenantId,
    this.categoryId = const Value.absent(),
    this.categoryName = const Value.absent(),
    required String name,
    this.description = const Value.absent(),
    this.status = const Value.absent(),
    required DateTime updatedAt,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       tenantId = Value(tenantId),
       name = Value(name),
       updatedAt = Value(updatedAt);
  static Insertable<LocalProductSnapshotsTableData> custom({
    Expression<String>? id,
    Expression<String>? tenantId,
    Expression<String>? categoryId,
    Expression<String>? categoryName,
    Expression<String>? name,
    Expression<String>? description,
    Expression<String>? status,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (tenantId != null) 'tenant_id': tenantId,
      if (categoryId != null) 'category_id': categoryId,
      if (categoryName != null) 'category_name': categoryName,
      if (name != null) 'name': name,
      if (description != null) 'description': description,
      if (status != null) 'status': status,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalProductSnapshotsTableCompanion copyWith({
    Value<String>? id,
    Value<String>? tenantId,
    Value<String?>? categoryId,
    Value<String?>? categoryName,
    Value<String>? name,
    Value<String?>? description,
    Value<String>? status,
    Value<DateTime>? updatedAt,
    Value<int>? rowid,
  }) {
    return LocalProductSnapshotsTableCompanion(
      id: id ?? this.id,
      tenantId: tenantId ?? this.tenantId,
      categoryId: categoryId ?? this.categoryId,
      categoryName: categoryName ?? this.categoryName,
      name: name ?? this.name,
      description: description ?? this.description,
      status: status ?? this.status,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (tenantId.present) {
      map['tenant_id'] = Variable<String>(tenantId.value);
    }
    if (categoryId.present) {
      map['category_id'] = Variable<String>(categoryId.value);
    }
    if (categoryName.present) {
      map['category_name'] = Variable<String>(categoryName.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (description.present) {
      map['description'] = Variable<String>(description.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalProductSnapshotsTableCompanion(')
          ..write('id: $id, ')
          ..write('tenantId: $tenantId, ')
          ..write('categoryId: $categoryId, ')
          ..write('categoryName: $categoryName, ')
          ..write('name: $name, ')
          ..write('description: $description, ')
          ..write('status: $status, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalProductVariantSnapshotsTableTable
    extends LocalProductVariantSnapshotsTable
    with
        TableInfo<
          $LocalProductVariantSnapshotsTableTable,
          LocalProductVariantSnapshotsTableData
        > {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalProductVariantSnapshotsTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _productIdMeta = const VerificationMeta(
    'productId',
  );
  @override
  late final GeneratedColumn<String> productId = GeneratedColumn<String>(
    'product_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _skuMeta = const VerificationMeta('sku');
  @override
  late final GeneratedColumn<String> sku = GeneratedColumn<String>(
    'sku',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _isDefaultMeta = const VerificationMeta(
    'isDefault',
  );
  @override
  late final GeneratedColumn<bool> isDefault = GeneratedColumn<bool>(
    'is_default',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("is_default" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('active'),
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    productId,
    name,
    sku,
    isDefault,
    status,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_product_variant_snapshots';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalProductVariantSnapshotsTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('product_id')) {
      context.handle(
        _productIdMeta,
        productId.isAcceptableOrUnknown(data['product_id']!, _productIdMeta),
      );
    } else if (isInserting) {
      context.missing(_productIdMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('sku')) {
      context.handle(
        _skuMeta,
        sku.isAcceptableOrUnknown(data['sku']!, _skuMeta),
      );
    }
    if (data.containsKey('is_default')) {
      context.handle(
        _isDefaultMeta,
        isDefault.isAcceptableOrUnknown(data['is_default']!, _isDefaultMeta),
      );
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalProductVariantSnapshotsTableData map(
    Map<String, dynamic> data, {
    String? tablePrefix,
  }) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalProductVariantSnapshotsTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      productId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      sku: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sku'],
      ),
      isDefault: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}is_default'],
      )!,
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
    );
  }

  @override
  $LocalProductVariantSnapshotsTableTable createAlias(String alias) {
    return $LocalProductVariantSnapshotsTableTable(attachedDatabase, alias);
  }
}

class LocalProductVariantSnapshotsTableData extends DataClass
    implements Insertable<LocalProductVariantSnapshotsTableData> {
  final String id;
  final String productId;
  final String name;
  final String? sku;
  final bool isDefault;
  final String status;
  const LocalProductVariantSnapshotsTableData({
    required this.id,
    required this.productId,
    required this.name,
    this.sku,
    required this.isDefault,
    required this.status,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['product_id'] = Variable<String>(productId);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || sku != null) {
      map['sku'] = Variable<String>(sku);
    }
    map['is_default'] = Variable<bool>(isDefault);
    map['status'] = Variable<String>(status);
    return map;
  }

  LocalProductVariantSnapshotsTableCompanion toCompanion(bool nullToAbsent) {
    return LocalProductVariantSnapshotsTableCompanion(
      id: Value(id),
      productId: Value(productId),
      name: Value(name),
      sku: sku == null && nullToAbsent ? const Value.absent() : Value(sku),
      isDefault: Value(isDefault),
      status: Value(status),
    );
  }

  factory LocalProductVariantSnapshotsTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalProductVariantSnapshotsTableData(
      id: serializer.fromJson<String>(json['id']),
      productId: serializer.fromJson<String>(json['productId']),
      name: serializer.fromJson<String>(json['name']),
      sku: serializer.fromJson<String?>(json['sku']),
      isDefault: serializer.fromJson<bool>(json['isDefault']),
      status: serializer.fromJson<String>(json['status']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'productId': serializer.toJson<String>(productId),
      'name': serializer.toJson<String>(name),
      'sku': serializer.toJson<String?>(sku),
      'isDefault': serializer.toJson<bool>(isDefault),
      'status': serializer.toJson<String>(status),
    };
  }

  LocalProductVariantSnapshotsTableData copyWith({
    String? id,
    String? productId,
    String? name,
    Value<String?> sku = const Value.absent(),
    bool? isDefault,
    String? status,
  }) => LocalProductVariantSnapshotsTableData(
    id: id ?? this.id,
    productId: productId ?? this.productId,
    name: name ?? this.name,
    sku: sku.present ? sku.value : this.sku,
    isDefault: isDefault ?? this.isDefault,
    status: status ?? this.status,
  );
  LocalProductVariantSnapshotsTableData copyWithCompanion(
    LocalProductVariantSnapshotsTableCompanion data,
  ) {
    return LocalProductVariantSnapshotsTableData(
      id: data.id.present ? data.id.value : this.id,
      productId: data.productId.present ? data.productId.value : this.productId,
      name: data.name.present ? data.name.value : this.name,
      sku: data.sku.present ? data.sku.value : this.sku,
      isDefault: data.isDefault.present ? data.isDefault.value : this.isDefault,
      status: data.status.present ? data.status.value : this.status,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalProductVariantSnapshotsTableData(')
          ..write('id: $id, ')
          ..write('productId: $productId, ')
          ..write('name: $name, ')
          ..write('sku: $sku, ')
          ..write('isDefault: $isDefault, ')
          ..write('status: $status')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(id, productId, name, sku, isDefault, status);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalProductVariantSnapshotsTableData &&
          other.id == this.id &&
          other.productId == this.productId &&
          other.name == this.name &&
          other.sku == this.sku &&
          other.isDefault == this.isDefault &&
          other.status == this.status);
}

class LocalProductVariantSnapshotsTableCompanion
    extends UpdateCompanion<LocalProductVariantSnapshotsTableData> {
  final Value<String> id;
  final Value<String> productId;
  final Value<String> name;
  final Value<String?> sku;
  final Value<bool> isDefault;
  final Value<String> status;
  final Value<int> rowid;
  const LocalProductVariantSnapshotsTableCompanion({
    this.id = const Value.absent(),
    this.productId = const Value.absent(),
    this.name = const Value.absent(),
    this.sku = const Value.absent(),
    this.isDefault = const Value.absent(),
    this.status = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalProductVariantSnapshotsTableCompanion.insert({
    required String id,
    required String productId,
    required String name,
    this.sku = const Value.absent(),
    this.isDefault = const Value.absent(),
    this.status = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       productId = Value(productId),
       name = Value(name);
  static Insertable<LocalProductVariantSnapshotsTableData> custom({
    Expression<String>? id,
    Expression<String>? productId,
    Expression<String>? name,
    Expression<String>? sku,
    Expression<bool>? isDefault,
    Expression<String>? status,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (productId != null) 'product_id': productId,
      if (name != null) 'name': name,
      if (sku != null) 'sku': sku,
      if (isDefault != null) 'is_default': isDefault,
      if (status != null) 'status': status,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalProductVariantSnapshotsTableCompanion copyWith({
    Value<String>? id,
    Value<String>? productId,
    Value<String>? name,
    Value<String?>? sku,
    Value<bool>? isDefault,
    Value<String>? status,
    Value<int>? rowid,
  }) {
    return LocalProductVariantSnapshotsTableCompanion(
      id: id ?? this.id,
      productId: productId ?? this.productId,
      name: name ?? this.name,
      sku: sku ?? this.sku,
      isDefault: isDefault ?? this.isDefault,
      status: status ?? this.status,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (productId.present) {
      map['product_id'] = Variable<String>(productId.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (sku.present) {
      map['sku'] = Variable<String>(sku.value);
    }
    if (isDefault.present) {
      map['is_default'] = Variable<bool>(isDefault.value);
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalProductVariantSnapshotsTableCompanion(')
          ..write('id: $id, ')
          ..write('productId: $productId, ')
          ..write('name: $name, ')
          ..write('sku: $sku, ')
          ..write('isDefault: $isDefault, ')
          ..write('status: $status, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalPriceSnapshotsTableTable extends LocalPriceSnapshotsTable
    with
        TableInfo<
          $LocalPriceSnapshotsTableTable,
          LocalPriceSnapshotsTableData
        > {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalPriceSnapshotsTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _variantIdMeta = const VerificationMeta(
    'variantId',
  );
  @override
  late final GeneratedColumn<String> variantId = GeneratedColumn<String>(
    'variant_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _outletIdMeta = const VerificationMeta(
    'outletId',
  );
  @override
  late final GeneratedColumn<String> outletId = GeneratedColumn<String>(
    'outlet_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _amountMinorMeta = const VerificationMeta(
    'amountMinor',
  );
  @override
  late final GeneratedColumn<int> amountMinor = GeneratedColumn<int>(
    'amount_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _currencyMeta = const VerificationMeta(
    'currency',
  );
  @override
  late final GeneratedColumn<String> currency = GeneratedColumn<String>(
    'currency',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('IDR'),
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    variantId,
    outletId,
    amountMinor,
    currency,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_price_snapshots';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalPriceSnapshotsTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('variant_id')) {
      context.handle(
        _variantIdMeta,
        variantId.isAcceptableOrUnknown(data['variant_id']!, _variantIdMeta),
      );
    } else if (isInserting) {
      context.missing(_variantIdMeta);
    }
    if (data.containsKey('outlet_id')) {
      context.handle(
        _outletIdMeta,
        outletId.isAcceptableOrUnknown(data['outlet_id']!, _outletIdMeta),
      );
    }
    if (data.containsKey('amount_minor')) {
      context.handle(
        _amountMinorMeta,
        amountMinor.isAcceptableOrUnknown(
          data['amount_minor']!,
          _amountMinorMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_amountMinorMeta);
    }
    if (data.containsKey('currency')) {
      context.handle(
        _currencyMeta,
        currency.isAcceptableOrUnknown(data['currency']!, _currencyMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalPriceSnapshotsTableData map(
    Map<String, dynamic> data, {
    String? tablePrefix,
  }) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalPriceSnapshotsTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      variantId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}variant_id'],
      )!,
      outletId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}outlet_id'],
      ),
      amountMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}amount_minor'],
      )!,
      currency: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}currency'],
      )!,
    );
  }

  @override
  $LocalPriceSnapshotsTableTable createAlias(String alias) {
    return $LocalPriceSnapshotsTableTable(attachedDatabase, alias);
  }
}

class LocalPriceSnapshotsTableData extends DataClass
    implements Insertable<LocalPriceSnapshotsTableData> {
  final String id;
  final String variantId;
  final String? outletId;
  final int amountMinor;
  final String currency;
  const LocalPriceSnapshotsTableData({
    required this.id,
    required this.variantId,
    this.outletId,
    required this.amountMinor,
    required this.currency,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['variant_id'] = Variable<String>(variantId);
    if (!nullToAbsent || outletId != null) {
      map['outlet_id'] = Variable<String>(outletId);
    }
    map['amount_minor'] = Variable<int>(amountMinor);
    map['currency'] = Variable<String>(currency);
    return map;
  }

  LocalPriceSnapshotsTableCompanion toCompanion(bool nullToAbsent) {
    return LocalPriceSnapshotsTableCompanion(
      id: Value(id),
      variantId: Value(variantId),
      outletId: outletId == null && nullToAbsent
          ? const Value.absent()
          : Value(outletId),
      amountMinor: Value(amountMinor),
      currency: Value(currency),
    );
  }

  factory LocalPriceSnapshotsTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalPriceSnapshotsTableData(
      id: serializer.fromJson<String>(json['id']),
      variantId: serializer.fromJson<String>(json['variantId']),
      outletId: serializer.fromJson<String?>(json['outletId']),
      amountMinor: serializer.fromJson<int>(json['amountMinor']),
      currency: serializer.fromJson<String>(json['currency']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'variantId': serializer.toJson<String>(variantId),
      'outletId': serializer.toJson<String?>(outletId),
      'amountMinor': serializer.toJson<int>(amountMinor),
      'currency': serializer.toJson<String>(currency),
    };
  }

  LocalPriceSnapshotsTableData copyWith({
    String? id,
    String? variantId,
    Value<String?> outletId = const Value.absent(),
    int? amountMinor,
    String? currency,
  }) => LocalPriceSnapshotsTableData(
    id: id ?? this.id,
    variantId: variantId ?? this.variantId,
    outletId: outletId.present ? outletId.value : this.outletId,
    amountMinor: amountMinor ?? this.amountMinor,
    currency: currency ?? this.currency,
  );
  LocalPriceSnapshotsTableData copyWithCompanion(
    LocalPriceSnapshotsTableCompanion data,
  ) {
    return LocalPriceSnapshotsTableData(
      id: data.id.present ? data.id.value : this.id,
      variantId: data.variantId.present ? data.variantId.value : this.variantId,
      outletId: data.outletId.present ? data.outletId.value : this.outletId,
      amountMinor: data.amountMinor.present
          ? data.amountMinor.value
          : this.amountMinor,
      currency: data.currency.present ? data.currency.value : this.currency,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalPriceSnapshotsTableData(')
          ..write('id: $id, ')
          ..write('variantId: $variantId, ')
          ..write('outletId: $outletId, ')
          ..write('amountMinor: $amountMinor, ')
          ..write('currency: $currency')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode =>
      Object.hash(id, variantId, outletId, amountMinor, currency);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalPriceSnapshotsTableData &&
          other.id == this.id &&
          other.variantId == this.variantId &&
          other.outletId == this.outletId &&
          other.amountMinor == this.amountMinor &&
          other.currency == this.currency);
}

class LocalPriceSnapshotsTableCompanion
    extends UpdateCompanion<LocalPriceSnapshotsTableData> {
  final Value<String> id;
  final Value<String> variantId;
  final Value<String?> outletId;
  final Value<int> amountMinor;
  final Value<String> currency;
  final Value<int> rowid;
  const LocalPriceSnapshotsTableCompanion({
    this.id = const Value.absent(),
    this.variantId = const Value.absent(),
    this.outletId = const Value.absent(),
    this.amountMinor = const Value.absent(),
    this.currency = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalPriceSnapshotsTableCompanion.insert({
    required String id,
    required String variantId,
    this.outletId = const Value.absent(),
    required int amountMinor,
    this.currency = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       variantId = Value(variantId),
       amountMinor = Value(amountMinor);
  static Insertable<LocalPriceSnapshotsTableData> custom({
    Expression<String>? id,
    Expression<String>? variantId,
    Expression<String>? outletId,
    Expression<int>? amountMinor,
    Expression<String>? currency,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (variantId != null) 'variant_id': variantId,
      if (outletId != null) 'outlet_id': outletId,
      if (amountMinor != null) 'amount_minor': amountMinor,
      if (currency != null) 'currency': currency,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalPriceSnapshotsTableCompanion copyWith({
    Value<String>? id,
    Value<String>? variantId,
    Value<String?>? outletId,
    Value<int>? amountMinor,
    Value<String>? currency,
    Value<int>? rowid,
  }) {
    return LocalPriceSnapshotsTableCompanion(
      id: id ?? this.id,
      variantId: variantId ?? this.variantId,
      outletId: outletId ?? this.outletId,
      amountMinor: amountMinor ?? this.amountMinor,
      currency: currency ?? this.currency,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (variantId.present) {
      map['variant_id'] = Variable<String>(variantId.value);
    }
    if (outletId.present) {
      map['outlet_id'] = Variable<String>(outletId.value);
    }
    if (amountMinor.present) {
      map['amount_minor'] = Variable<int>(amountMinor.value);
    }
    if (currency.present) {
      map['currency'] = Variable<String>(currency.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalPriceSnapshotsTableCompanion(')
          ..write('id: $id, ')
          ..write('variantId: $variantId, ')
          ..write('outletId: $outletId, ')
          ..write('amountMinor: $amountMinor, ')
          ..write('currency: $currency, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalInventorySnapshotsTableTable extends LocalInventorySnapshotsTable
    with
        TableInfo<
          $LocalInventorySnapshotsTableTable,
          LocalInventorySnapshotsTableData
        > {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalInventorySnapshotsTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _inventoryItemIdMeta = const VerificationMeta(
    'inventoryItemId',
  );
  @override
  late final GeneratedColumn<String> inventoryItemId = GeneratedColumn<String>(
    'inventory_item_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _locationIdMeta = const VerificationMeta(
    'locationId',
  );
  @override
  late final GeneratedColumn<String> locationId = GeneratedColumn<String>(
    'location_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _outletIdMeta = const VerificationMeta(
    'outletId',
  );
  @override
  late final GeneratedColumn<String> outletId = GeneratedColumn<String>(
    'outlet_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _skuMeta = const VerificationMeta('sku');
  @override
  late final GeneratedColumn<String> sku = GeneratedColumn<String>(
    'sku',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _onHandMeta = const VerificationMeta('onHand');
  @override
  late final GeneratedColumn<double> onHand = GeneratedColumn<double>(
    'on_hand',
    aliasedName,
    false,
    type: DriftSqlType.double,
    requiredDuringInsert: false,
    defaultValue: const Constant(0.0),
  );
  static const VerificationMeta _reservedMeta = const VerificationMeta(
    'reserved',
  );
  @override
  late final GeneratedColumn<double> reserved = GeneratedColumn<double>(
    'reserved',
    aliasedName,
    false,
    type: DriftSqlType.double,
    requiredDuringInsert: false,
    defaultValue: const Constant(0.0),
  );
  static const VerificationMeta _unitMeta = const VerificationMeta('unit');
  @override
  late final GeneratedColumn<String> unit = GeneratedColumn<String>(
    'unit',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('pcs'),
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    inventoryItemId,
    locationId,
    outletId,
    name,
    sku,
    onHand,
    reserved,
    unit,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_inventory_snapshots';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalInventorySnapshotsTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('inventory_item_id')) {
      context.handle(
        _inventoryItemIdMeta,
        inventoryItemId.isAcceptableOrUnknown(
          data['inventory_item_id']!,
          _inventoryItemIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_inventoryItemIdMeta);
    }
    if (data.containsKey('location_id')) {
      context.handle(
        _locationIdMeta,
        locationId.isAcceptableOrUnknown(data['location_id']!, _locationIdMeta),
      );
    } else if (isInserting) {
      context.missing(_locationIdMeta);
    }
    if (data.containsKey('outlet_id')) {
      context.handle(
        _outletIdMeta,
        outletId.isAcceptableOrUnknown(data['outlet_id']!, _outletIdMeta),
      );
    } else if (isInserting) {
      context.missing(_outletIdMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('sku')) {
      context.handle(
        _skuMeta,
        sku.isAcceptableOrUnknown(data['sku']!, _skuMeta),
      );
    }
    if (data.containsKey('on_hand')) {
      context.handle(
        _onHandMeta,
        onHand.isAcceptableOrUnknown(data['on_hand']!, _onHandMeta),
      );
    }
    if (data.containsKey('reserved')) {
      context.handle(
        _reservedMeta,
        reserved.isAcceptableOrUnknown(data['reserved']!, _reservedMeta),
      );
    }
    if (data.containsKey('unit')) {
      context.handle(
        _unitMeta,
        unit.isAcceptableOrUnknown(data['unit']!, _unitMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalInventorySnapshotsTableData map(
    Map<String, dynamic> data, {
    String? tablePrefix,
  }) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalInventorySnapshotsTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      inventoryItemId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}inventory_item_id'],
      )!,
      locationId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}location_id'],
      )!,
      outletId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}outlet_id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      sku: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sku'],
      ),
      onHand: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}on_hand'],
      )!,
      reserved: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}reserved'],
      )!,
      unit: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}unit'],
      )!,
    );
  }

  @override
  $LocalInventorySnapshotsTableTable createAlias(String alias) {
    return $LocalInventorySnapshotsTableTable(attachedDatabase, alias);
  }
}

class LocalInventorySnapshotsTableData extends DataClass
    implements Insertable<LocalInventorySnapshotsTableData> {
  final String id;
  final String inventoryItemId;
  final String locationId;
  final String outletId;
  final String name;
  final String? sku;
  final double onHand;
  final double reserved;
  final String unit;
  const LocalInventorySnapshotsTableData({
    required this.id,
    required this.inventoryItemId,
    required this.locationId,
    required this.outletId,
    required this.name,
    this.sku,
    required this.onHand,
    required this.reserved,
    required this.unit,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['inventory_item_id'] = Variable<String>(inventoryItemId);
    map['location_id'] = Variable<String>(locationId);
    map['outlet_id'] = Variable<String>(outletId);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || sku != null) {
      map['sku'] = Variable<String>(sku);
    }
    map['on_hand'] = Variable<double>(onHand);
    map['reserved'] = Variable<double>(reserved);
    map['unit'] = Variable<String>(unit);
    return map;
  }

  LocalInventorySnapshotsTableCompanion toCompanion(bool nullToAbsent) {
    return LocalInventorySnapshotsTableCompanion(
      id: Value(id),
      inventoryItemId: Value(inventoryItemId),
      locationId: Value(locationId),
      outletId: Value(outletId),
      name: Value(name),
      sku: sku == null && nullToAbsent ? const Value.absent() : Value(sku),
      onHand: Value(onHand),
      reserved: Value(reserved),
      unit: Value(unit),
    );
  }

  factory LocalInventorySnapshotsTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalInventorySnapshotsTableData(
      id: serializer.fromJson<String>(json['id']),
      inventoryItemId: serializer.fromJson<String>(json['inventoryItemId']),
      locationId: serializer.fromJson<String>(json['locationId']),
      outletId: serializer.fromJson<String>(json['outletId']),
      name: serializer.fromJson<String>(json['name']),
      sku: serializer.fromJson<String?>(json['sku']),
      onHand: serializer.fromJson<double>(json['onHand']),
      reserved: serializer.fromJson<double>(json['reserved']),
      unit: serializer.fromJson<String>(json['unit']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'inventoryItemId': serializer.toJson<String>(inventoryItemId),
      'locationId': serializer.toJson<String>(locationId),
      'outletId': serializer.toJson<String>(outletId),
      'name': serializer.toJson<String>(name),
      'sku': serializer.toJson<String?>(sku),
      'onHand': serializer.toJson<double>(onHand),
      'reserved': serializer.toJson<double>(reserved),
      'unit': serializer.toJson<String>(unit),
    };
  }

  LocalInventorySnapshotsTableData copyWith({
    String? id,
    String? inventoryItemId,
    String? locationId,
    String? outletId,
    String? name,
    Value<String?> sku = const Value.absent(),
    double? onHand,
    double? reserved,
    String? unit,
  }) => LocalInventorySnapshotsTableData(
    id: id ?? this.id,
    inventoryItemId: inventoryItemId ?? this.inventoryItemId,
    locationId: locationId ?? this.locationId,
    outletId: outletId ?? this.outletId,
    name: name ?? this.name,
    sku: sku.present ? sku.value : this.sku,
    onHand: onHand ?? this.onHand,
    reserved: reserved ?? this.reserved,
    unit: unit ?? this.unit,
  );
  LocalInventorySnapshotsTableData copyWithCompanion(
    LocalInventorySnapshotsTableCompanion data,
  ) {
    return LocalInventorySnapshotsTableData(
      id: data.id.present ? data.id.value : this.id,
      inventoryItemId: data.inventoryItemId.present
          ? data.inventoryItemId.value
          : this.inventoryItemId,
      locationId: data.locationId.present
          ? data.locationId.value
          : this.locationId,
      outletId: data.outletId.present ? data.outletId.value : this.outletId,
      name: data.name.present ? data.name.value : this.name,
      sku: data.sku.present ? data.sku.value : this.sku,
      onHand: data.onHand.present ? data.onHand.value : this.onHand,
      reserved: data.reserved.present ? data.reserved.value : this.reserved,
      unit: data.unit.present ? data.unit.value : this.unit,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalInventorySnapshotsTableData(')
          ..write('id: $id, ')
          ..write('inventoryItemId: $inventoryItemId, ')
          ..write('locationId: $locationId, ')
          ..write('outletId: $outletId, ')
          ..write('name: $name, ')
          ..write('sku: $sku, ')
          ..write('onHand: $onHand, ')
          ..write('reserved: $reserved, ')
          ..write('unit: $unit')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    inventoryItemId,
    locationId,
    outletId,
    name,
    sku,
    onHand,
    reserved,
    unit,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalInventorySnapshotsTableData &&
          other.id == this.id &&
          other.inventoryItemId == this.inventoryItemId &&
          other.locationId == this.locationId &&
          other.outletId == this.outletId &&
          other.name == this.name &&
          other.sku == this.sku &&
          other.onHand == this.onHand &&
          other.reserved == this.reserved &&
          other.unit == this.unit);
}

class LocalInventorySnapshotsTableCompanion
    extends UpdateCompanion<LocalInventorySnapshotsTableData> {
  final Value<String> id;
  final Value<String> inventoryItemId;
  final Value<String> locationId;
  final Value<String> outletId;
  final Value<String> name;
  final Value<String?> sku;
  final Value<double> onHand;
  final Value<double> reserved;
  final Value<String> unit;
  final Value<int> rowid;
  const LocalInventorySnapshotsTableCompanion({
    this.id = const Value.absent(),
    this.inventoryItemId = const Value.absent(),
    this.locationId = const Value.absent(),
    this.outletId = const Value.absent(),
    this.name = const Value.absent(),
    this.sku = const Value.absent(),
    this.onHand = const Value.absent(),
    this.reserved = const Value.absent(),
    this.unit = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalInventorySnapshotsTableCompanion.insert({
    required String id,
    required String inventoryItemId,
    required String locationId,
    required String outletId,
    required String name,
    this.sku = const Value.absent(),
    this.onHand = const Value.absent(),
    this.reserved = const Value.absent(),
    this.unit = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       inventoryItemId = Value(inventoryItemId),
       locationId = Value(locationId),
       outletId = Value(outletId),
       name = Value(name);
  static Insertable<LocalInventorySnapshotsTableData> custom({
    Expression<String>? id,
    Expression<String>? inventoryItemId,
    Expression<String>? locationId,
    Expression<String>? outletId,
    Expression<String>? name,
    Expression<String>? sku,
    Expression<double>? onHand,
    Expression<double>? reserved,
    Expression<String>? unit,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (inventoryItemId != null) 'inventory_item_id': inventoryItemId,
      if (locationId != null) 'location_id': locationId,
      if (outletId != null) 'outlet_id': outletId,
      if (name != null) 'name': name,
      if (sku != null) 'sku': sku,
      if (onHand != null) 'on_hand': onHand,
      if (reserved != null) 'reserved': reserved,
      if (unit != null) 'unit': unit,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalInventorySnapshotsTableCompanion copyWith({
    Value<String>? id,
    Value<String>? inventoryItemId,
    Value<String>? locationId,
    Value<String>? outletId,
    Value<String>? name,
    Value<String?>? sku,
    Value<double>? onHand,
    Value<double>? reserved,
    Value<String>? unit,
    Value<int>? rowid,
  }) {
    return LocalInventorySnapshotsTableCompanion(
      id: id ?? this.id,
      inventoryItemId: inventoryItemId ?? this.inventoryItemId,
      locationId: locationId ?? this.locationId,
      outletId: outletId ?? this.outletId,
      name: name ?? this.name,
      sku: sku ?? this.sku,
      onHand: onHand ?? this.onHand,
      reserved: reserved ?? this.reserved,
      unit: unit ?? this.unit,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (inventoryItemId.present) {
      map['inventory_item_id'] = Variable<String>(inventoryItemId.value);
    }
    if (locationId.present) {
      map['location_id'] = Variable<String>(locationId.value);
    }
    if (outletId.present) {
      map['outlet_id'] = Variable<String>(outletId.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (sku.present) {
      map['sku'] = Variable<String>(sku.value);
    }
    if (onHand.present) {
      map['on_hand'] = Variable<double>(onHand.value);
    }
    if (reserved.present) {
      map['reserved'] = Variable<double>(reserved.value);
    }
    if (unit.present) {
      map['unit'] = Variable<String>(unit.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalInventorySnapshotsTableCompanion(')
          ..write('id: $id, ')
          ..write('inventoryItemId: $inventoryItemId, ')
          ..write('locationId: $locationId, ')
          ..write('outletId: $outletId, ')
          ..write('name: $name, ')
          ..write('sku: $sku, ')
          ..write('onHand: $onHand, ')
          ..write('reserved: $reserved, ')
          ..write('unit: $unit, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalCustomerSnapshotsTableTable extends LocalCustomerSnapshotsTable
    with
        TableInfo<
          $LocalCustomerSnapshotsTableTable,
          LocalCustomerSnapshotsTableData
        > {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalCustomerSnapshotsTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _tenantIdMeta = const VerificationMeta(
    'tenantId',
  );
  @override
  late final GeneratedColumn<String> tenantId = GeneratedColumn<String>(
    'tenant_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _phoneMeta = const VerificationMeta('phone');
  @override
  late final GeneratedColumn<String> phone = GeneratedColumn<String>(
    'phone',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _emailMeta = const VerificationMeta('email');
  @override
  late final GeneratedColumn<String> email = GeneratedColumn<String>(
    'email',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [id, tenantId, name, phone, email];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_customer_snapshots';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalCustomerSnapshotsTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('tenant_id')) {
      context.handle(
        _tenantIdMeta,
        tenantId.isAcceptableOrUnknown(data['tenant_id']!, _tenantIdMeta),
      );
    } else if (isInserting) {
      context.missing(_tenantIdMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('phone')) {
      context.handle(
        _phoneMeta,
        phone.isAcceptableOrUnknown(data['phone']!, _phoneMeta),
      );
    }
    if (data.containsKey('email')) {
      context.handle(
        _emailMeta,
        email.isAcceptableOrUnknown(data['email']!, _emailMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalCustomerSnapshotsTableData map(
    Map<String, dynamic> data, {
    String? tablePrefix,
  }) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalCustomerSnapshotsTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      tenantId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}tenant_id'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      phone: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}phone'],
      ),
      email: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}email'],
      ),
    );
  }

  @override
  $LocalCustomerSnapshotsTableTable createAlias(String alias) {
    return $LocalCustomerSnapshotsTableTable(attachedDatabase, alias);
  }
}

class LocalCustomerSnapshotsTableData extends DataClass
    implements Insertable<LocalCustomerSnapshotsTableData> {
  final String id;
  final String tenantId;
  final String name;
  final String? phone;
  final String? email;
  const LocalCustomerSnapshotsTableData({
    required this.id,
    required this.tenantId,
    required this.name,
    this.phone,
    this.email,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['tenant_id'] = Variable<String>(tenantId);
    map['name'] = Variable<String>(name);
    if (!nullToAbsent || phone != null) {
      map['phone'] = Variable<String>(phone);
    }
    if (!nullToAbsent || email != null) {
      map['email'] = Variable<String>(email);
    }
    return map;
  }

  LocalCustomerSnapshotsTableCompanion toCompanion(bool nullToAbsent) {
    return LocalCustomerSnapshotsTableCompanion(
      id: Value(id),
      tenantId: Value(tenantId),
      name: Value(name),
      phone: phone == null && nullToAbsent
          ? const Value.absent()
          : Value(phone),
      email: email == null && nullToAbsent
          ? const Value.absent()
          : Value(email),
    );
  }

  factory LocalCustomerSnapshotsTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalCustomerSnapshotsTableData(
      id: serializer.fromJson<String>(json['id']),
      tenantId: serializer.fromJson<String>(json['tenantId']),
      name: serializer.fromJson<String>(json['name']),
      phone: serializer.fromJson<String?>(json['phone']),
      email: serializer.fromJson<String?>(json['email']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'tenantId': serializer.toJson<String>(tenantId),
      'name': serializer.toJson<String>(name),
      'phone': serializer.toJson<String?>(phone),
      'email': serializer.toJson<String?>(email),
    };
  }

  LocalCustomerSnapshotsTableData copyWith({
    String? id,
    String? tenantId,
    String? name,
    Value<String?> phone = const Value.absent(),
    Value<String?> email = const Value.absent(),
  }) => LocalCustomerSnapshotsTableData(
    id: id ?? this.id,
    tenantId: tenantId ?? this.tenantId,
    name: name ?? this.name,
    phone: phone.present ? phone.value : this.phone,
    email: email.present ? email.value : this.email,
  );
  LocalCustomerSnapshotsTableData copyWithCompanion(
    LocalCustomerSnapshotsTableCompanion data,
  ) {
    return LocalCustomerSnapshotsTableData(
      id: data.id.present ? data.id.value : this.id,
      tenantId: data.tenantId.present ? data.tenantId.value : this.tenantId,
      name: data.name.present ? data.name.value : this.name,
      phone: data.phone.present ? data.phone.value : this.phone,
      email: data.email.present ? data.email.value : this.email,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalCustomerSnapshotsTableData(')
          ..write('id: $id, ')
          ..write('tenantId: $tenantId, ')
          ..write('name: $name, ')
          ..write('phone: $phone, ')
          ..write('email: $email')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(id, tenantId, name, phone, email);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalCustomerSnapshotsTableData &&
          other.id == this.id &&
          other.tenantId == this.tenantId &&
          other.name == this.name &&
          other.phone == this.phone &&
          other.email == this.email);
}

class LocalCustomerSnapshotsTableCompanion
    extends UpdateCompanion<LocalCustomerSnapshotsTableData> {
  final Value<String> id;
  final Value<String> tenantId;
  final Value<String> name;
  final Value<String?> phone;
  final Value<String?> email;
  final Value<int> rowid;
  const LocalCustomerSnapshotsTableCompanion({
    this.id = const Value.absent(),
    this.tenantId = const Value.absent(),
    this.name = const Value.absent(),
    this.phone = const Value.absent(),
    this.email = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalCustomerSnapshotsTableCompanion.insert({
    required String id,
    required String tenantId,
    required String name,
    this.phone = const Value.absent(),
    this.email = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       tenantId = Value(tenantId),
       name = Value(name);
  static Insertable<LocalCustomerSnapshotsTableData> custom({
    Expression<String>? id,
    Expression<String>? tenantId,
    Expression<String>? name,
    Expression<String>? phone,
    Expression<String>? email,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (tenantId != null) 'tenant_id': tenantId,
      if (name != null) 'name': name,
      if (phone != null) 'phone': phone,
      if (email != null) 'email': email,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalCustomerSnapshotsTableCompanion copyWith({
    Value<String>? id,
    Value<String>? tenantId,
    Value<String>? name,
    Value<String?>? phone,
    Value<String?>? email,
    Value<int>? rowid,
  }) {
    return LocalCustomerSnapshotsTableCompanion(
      id: id ?? this.id,
      tenantId: tenantId ?? this.tenantId,
      name: name ?? this.name,
      phone: phone ?? this.phone,
      email: email ?? this.email,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (tenantId.present) {
      map['tenant_id'] = Variable<String>(tenantId.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (phone.present) {
      map['phone'] = Variable<String>(phone.value);
    }
    if (email.present) {
      map['email'] = Variable<String>(email.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalCustomerSnapshotsTableCompanion(')
          ..write('id: $id, ')
          ..write('tenantId: $tenantId, ')
          ..write('name: $name, ')
          ..write('phone: $phone, ')
          ..write('email: $email, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalCartsTableTable extends LocalCartsTable
    with TableInfo<$LocalCartsTableTable, LocalCartsTableData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalCartsTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _outletIdMeta = const VerificationMeta(
    'outletId',
  );
  @override
  late final GeneratedColumn<String> outletId = GeneratedColumn<String>(
    'outlet_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _customerIdMeta = const VerificationMeta(
    'customerId',
  );
  @override
  late final GeneratedColumn<String> customerId = GeneratedColumn<String>(
    'customer_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _discountMinorMeta = const VerificationMeta(
    'discountMinor',
  );
  @override
  late final GeneratedColumn<int> discountMinor = GeneratedColumn<int>(
    'discount_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _taxMinorMeta = const VerificationMeta(
    'taxMinor',
  );
  @override
  late final GeneratedColumn<int> taxMinor = GeneratedColumn<int>(
    'tax_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _notesMeta = const VerificationMeta('notes');
  @override
  late final GeneratedColumn<String> notes = GeneratedColumn<String>(
    'notes',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    outletId,
    customerId,
    discountMinor,
    taxMinor,
    notes,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_carts';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalCartsTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('outlet_id')) {
      context.handle(
        _outletIdMeta,
        outletId.isAcceptableOrUnknown(data['outlet_id']!, _outletIdMeta),
      );
    } else if (isInserting) {
      context.missing(_outletIdMeta);
    }
    if (data.containsKey('customer_id')) {
      context.handle(
        _customerIdMeta,
        customerId.isAcceptableOrUnknown(data['customer_id']!, _customerIdMeta),
      );
    }
    if (data.containsKey('discount_minor')) {
      context.handle(
        _discountMinorMeta,
        discountMinor.isAcceptableOrUnknown(
          data['discount_minor']!,
          _discountMinorMeta,
        ),
      );
    }
    if (data.containsKey('tax_minor')) {
      context.handle(
        _taxMinorMeta,
        taxMinor.isAcceptableOrUnknown(data['tax_minor']!, _taxMinorMeta),
      );
    }
    if (data.containsKey('notes')) {
      context.handle(
        _notesMeta,
        notes.isAcceptableOrUnknown(data['notes']!, _notesMeta),
      );
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalCartsTableData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalCartsTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      outletId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}outlet_id'],
      )!,
      customerId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}customer_id'],
      ),
      discountMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}discount_minor'],
      )!,
      taxMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}tax_minor'],
      )!,
      notes: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}notes'],
      ),
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      )!,
    );
  }

  @override
  $LocalCartsTableTable createAlias(String alias) {
    return $LocalCartsTableTable(attachedDatabase, alias);
  }
}

class LocalCartsTableData extends DataClass
    implements Insertable<LocalCartsTableData> {
  final String id;
  final String outletId;
  final String? customerId;
  final int discountMinor;
  final int taxMinor;
  final String? notes;
  final DateTime updatedAt;
  const LocalCartsTableData({
    required this.id,
    required this.outletId,
    this.customerId,
    required this.discountMinor,
    required this.taxMinor,
    this.notes,
    required this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['outlet_id'] = Variable<String>(outletId);
    if (!nullToAbsent || customerId != null) {
      map['customer_id'] = Variable<String>(customerId);
    }
    map['discount_minor'] = Variable<int>(discountMinor);
    map['tax_minor'] = Variable<int>(taxMinor);
    if (!nullToAbsent || notes != null) {
      map['notes'] = Variable<String>(notes);
    }
    map['updated_at'] = Variable<DateTime>(updatedAt);
    return map;
  }

  LocalCartsTableCompanion toCompanion(bool nullToAbsent) {
    return LocalCartsTableCompanion(
      id: Value(id),
      outletId: Value(outletId),
      customerId: customerId == null && nullToAbsent
          ? const Value.absent()
          : Value(customerId),
      discountMinor: Value(discountMinor),
      taxMinor: Value(taxMinor),
      notes: notes == null && nullToAbsent
          ? const Value.absent()
          : Value(notes),
      updatedAt: Value(updatedAt),
    );
  }

  factory LocalCartsTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalCartsTableData(
      id: serializer.fromJson<String>(json['id']),
      outletId: serializer.fromJson<String>(json['outletId']),
      customerId: serializer.fromJson<String?>(json['customerId']),
      discountMinor: serializer.fromJson<int>(json['discountMinor']),
      taxMinor: serializer.fromJson<int>(json['taxMinor']),
      notes: serializer.fromJson<String?>(json['notes']),
      updatedAt: serializer.fromJson<DateTime>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'outletId': serializer.toJson<String>(outletId),
      'customerId': serializer.toJson<String?>(customerId),
      'discountMinor': serializer.toJson<int>(discountMinor),
      'taxMinor': serializer.toJson<int>(taxMinor),
      'notes': serializer.toJson<String?>(notes),
      'updatedAt': serializer.toJson<DateTime>(updatedAt),
    };
  }

  LocalCartsTableData copyWith({
    String? id,
    String? outletId,
    Value<String?> customerId = const Value.absent(),
    int? discountMinor,
    int? taxMinor,
    Value<String?> notes = const Value.absent(),
    DateTime? updatedAt,
  }) => LocalCartsTableData(
    id: id ?? this.id,
    outletId: outletId ?? this.outletId,
    customerId: customerId.present ? customerId.value : this.customerId,
    discountMinor: discountMinor ?? this.discountMinor,
    taxMinor: taxMinor ?? this.taxMinor,
    notes: notes.present ? notes.value : this.notes,
    updatedAt: updatedAt ?? this.updatedAt,
  );
  LocalCartsTableData copyWithCompanion(LocalCartsTableCompanion data) {
    return LocalCartsTableData(
      id: data.id.present ? data.id.value : this.id,
      outletId: data.outletId.present ? data.outletId.value : this.outletId,
      customerId: data.customerId.present
          ? data.customerId.value
          : this.customerId,
      discountMinor: data.discountMinor.present
          ? data.discountMinor.value
          : this.discountMinor,
      taxMinor: data.taxMinor.present ? data.taxMinor.value : this.taxMinor,
      notes: data.notes.present ? data.notes.value : this.notes,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalCartsTableData(')
          ..write('id: $id, ')
          ..write('outletId: $outletId, ')
          ..write('customerId: $customerId, ')
          ..write('discountMinor: $discountMinor, ')
          ..write('taxMinor: $taxMinor, ')
          ..write('notes: $notes, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    outletId,
    customerId,
    discountMinor,
    taxMinor,
    notes,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalCartsTableData &&
          other.id == this.id &&
          other.outletId == this.outletId &&
          other.customerId == this.customerId &&
          other.discountMinor == this.discountMinor &&
          other.taxMinor == this.taxMinor &&
          other.notes == this.notes &&
          other.updatedAt == this.updatedAt);
}

class LocalCartsTableCompanion extends UpdateCompanion<LocalCartsTableData> {
  final Value<String> id;
  final Value<String> outletId;
  final Value<String?> customerId;
  final Value<int> discountMinor;
  final Value<int> taxMinor;
  final Value<String?> notes;
  final Value<DateTime> updatedAt;
  final Value<int> rowid;
  const LocalCartsTableCompanion({
    this.id = const Value.absent(),
    this.outletId = const Value.absent(),
    this.customerId = const Value.absent(),
    this.discountMinor = const Value.absent(),
    this.taxMinor = const Value.absent(),
    this.notes = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalCartsTableCompanion.insert({
    required String id,
    required String outletId,
    this.customerId = const Value.absent(),
    this.discountMinor = const Value.absent(),
    this.taxMinor = const Value.absent(),
    this.notes = const Value.absent(),
    required DateTime updatedAt,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       outletId = Value(outletId),
       updatedAt = Value(updatedAt);
  static Insertable<LocalCartsTableData> custom({
    Expression<String>? id,
    Expression<String>? outletId,
    Expression<String>? customerId,
    Expression<int>? discountMinor,
    Expression<int>? taxMinor,
    Expression<String>? notes,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (outletId != null) 'outlet_id': outletId,
      if (customerId != null) 'customer_id': customerId,
      if (discountMinor != null) 'discount_minor': discountMinor,
      if (taxMinor != null) 'tax_minor': taxMinor,
      if (notes != null) 'notes': notes,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalCartsTableCompanion copyWith({
    Value<String>? id,
    Value<String>? outletId,
    Value<String?>? customerId,
    Value<int>? discountMinor,
    Value<int>? taxMinor,
    Value<String?>? notes,
    Value<DateTime>? updatedAt,
    Value<int>? rowid,
  }) {
    return LocalCartsTableCompanion(
      id: id ?? this.id,
      outletId: outletId ?? this.outletId,
      customerId: customerId ?? this.customerId,
      discountMinor: discountMinor ?? this.discountMinor,
      taxMinor: taxMinor ?? this.taxMinor,
      notes: notes ?? this.notes,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (outletId.present) {
      map['outlet_id'] = Variable<String>(outletId.value);
    }
    if (customerId.present) {
      map['customer_id'] = Variable<String>(customerId.value);
    }
    if (discountMinor.present) {
      map['discount_minor'] = Variable<int>(discountMinor.value);
    }
    if (taxMinor.present) {
      map['tax_minor'] = Variable<int>(taxMinor.value);
    }
    if (notes.present) {
      map['notes'] = Variable<String>(notes.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalCartsTableCompanion(')
          ..write('id: $id, ')
          ..write('outletId: $outletId, ')
          ..write('customerId: $customerId, ')
          ..write('discountMinor: $discountMinor, ')
          ..write('taxMinor: $taxMinor, ')
          ..write('notes: $notes, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalCartItemsTableTable extends LocalCartItemsTable
    with TableInfo<$LocalCartItemsTableTable, LocalCartItemsTableData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalCartItemsTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _cartIdMeta = const VerificationMeta('cartId');
  @override
  late final GeneratedColumn<String> cartId = GeneratedColumn<String>(
    'cart_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _productVariantIdMeta = const VerificationMeta(
    'productVariantId',
  );
  @override
  late final GeneratedColumn<String> productVariantId = GeneratedColumn<String>(
    'product_variant_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _productNameMeta = const VerificationMeta(
    'productName',
  );
  @override
  late final GeneratedColumn<String> productName = GeneratedColumn<String>(
    'product_name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _variantNameMeta = const VerificationMeta(
    'variantName',
  );
  @override
  late final GeneratedColumn<String> variantName = GeneratedColumn<String>(
    'variant_name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _unitPriceMinorMeta = const VerificationMeta(
    'unitPriceMinor',
  );
  @override
  late final GeneratedColumn<int> unitPriceMinor = GeneratedColumn<int>(
    'unit_price_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _quantityMeta = const VerificationMeta(
    'quantity',
  );
  @override
  late final GeneratedColumn<int> quantity = GeneratedColumn<int>(
    'quantity',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(1),
  );
  static const VerificationMeta _notesMeta = const VerificationMeta('notes');
  @override
  late final GeneratedColumn<String> notes = GeneratedColumn<String>(
    'notes',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    cartId,
    productVariantId,
    productName,
    variantName,
    unitPriceMinor,
    quantity,
    notes,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_cart_items';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalCartItemsTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('cart_id')) {
      context.handle(
        _cartIdMeta,
        cartId.isAcceptableOrUnknown(data['cart_id']!, _cartIdMeta),
      );
    } else if (isInserting) {
      context.missing(_cartIdMeta);
    }
    if (data.containsKey('product_variant_id')) {
      context.handle(
        _productVariantIdMeta,
        productVariantId.isAcceptableOrUnknown(
          data['product_variant_id']!,
          _productVariantIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_productVariantIdMeta);
    }
    if (data.containsKey('product_name')) {
      context.handle(
        _productNameMeta,
        productName.isAcceptableOrUnknown(
          data['product_name']!,
          _productNameMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_productNameMeta);
    }
    if (data.containsKey('variant_name')) {
      context.handle(
        _variantNameMeta,
        variantName.isAcceptableOrUnknown(
          data['variant_name']!,
          _variantNameMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_variantNameMeta);
    }
    if (data.containsKey('unit_price_minor')) {
      context.handle(
        _unitPriceMinorMeta,
        unitPriceMinor.isAcceptableOrUnknown(
          data['unit_price_minor']!,
          _unitPriceMinorMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_unitPriceMinorMeta);
    }
    if (data.containsKey('quantity')) {
      context.handle(
        _quantityMeta,
        quantity.isAcceptableOrUnknown(data['quantity']!, _quantityMeta),
      );
    }
    if (data.containsKey('notes')) {
      context.handle(
        _notesMeta,
        notes.isAcceptableOrUnknown(data['notes']!, _notesMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalCartItemsTableData map(
    Map<String, dynamic> data, {
    String? tablePrefix,
  }) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalCartItemsTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      cartId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}cart_id'],
      )!,
      productVariantId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_variant_id'],
      )!,
      productName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_name'],
      )!,
      variantName: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}variant_name'],
      )!,
      unitPriceMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}unit_price_minor'],
      )!,
      quantity: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}quantity'],
      )!,
      notes: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}notes'],
      ),
    );
  }

  @override
  $LocalCartItemsTableTable createAlias(String alias) {
    return $LocalCartItemsTableTable(attachedDatabase, alias);
  }
}

class LocalCartItemsTableData extends DataClass
    implements Insertable<LocalCartItemsTableData> {
  final String id;
  final String cartId;
  final String productVariantId;
  final String productName;
  final String variantName;
  final int unitPriceMinor;
  final int quantity;
  final String? notes;
  const LocalCartItemsTableData({
    required this.id,
    required this.cartId,
    required this.productVariantId,
    required this.productName,
    required this.variantName,
    required this.unitPriceMinor,
    required this.quantity,
    this.notes,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['cart_id'] = Variable<String>(cartId);
    map['product_variant_id'] = Variable<String>(productVariantId);
    map['product_name'] = Variable<String>(productName);
    map['variant_name'] = Variable<String>(variantName);
    map['unit_price_minor'] = Variable<int>(unitPriceMinor);
    map['quantity'] = Variable<int>(quantity);
    if (!nullToAbsent || notes != null) {
      map['notes'] = Variable<String>(notes);
    }
    return map;
  }

  LocalCartItemsTableCompanion toCompanion(bool nullToAbsent) {
    return LocalCartItemsTableCompanion(
      id: Value(id),
      cartId: Value(cartId),
      productVariantId: Value(productVariantId),
      productName: Value(productName),
      variantName: Value(variantName),
      unitPriceMinor: Value(unitPriceMinor),
      quantity: Value(quantity),
      notes: notes == null && nullToAbsent
          ? const Value.absent()
          : Value(notes),
    );
  }

  factory LocalCartItemsTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalCartItemsTableData(
      id: serializer.fromJson<String>(json['id']),
      cartId: serializer.fromJson<String>(json['cartId']),
      productVariantId: serializer.fromJson<String>(json['productVariantId']),
      productName: serializer.fromJson<String>(json['productName']),
      variantName: serializer.fromJson<String>(json['variantName']),
      unitPriceMinor: serializer.fromJson<int>(json['unitPriceMinor']),
      quantity: serializer.fromJson<int>(json['quantity']),
      notes: serializer.fromJson<String?>(json['notes']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'cartId': serializer.toJson<String>(cartId),
      'productVariantId': serializer.toJson<String>(productVariantId),
      'productName': serializer.toJson<String>(productName),
      'variantName': serializer.toJson<String>(variantName),
      'unitPriceMinor': serializer.toJson<int>(unitPriceMinor),
      'quantity': serializer.toJson<int>(quantity),
      'notes': serializer.toJson<String?>(notes),
    };
  }

  LocalCartItemsTableData copyWith({
    String? id,
    String? cartId,
    String? productVariantId,
    String? productName,
    String? variantName,
    int? unitPriceMinor,
    int? quantity,
    Value<String?> notes = const Value.absent(),
  }) => LocalCartItemsTableData(
    id: id ?? this.id,
    cartId: cartId ?? this.cartId,
    productVariantId: productVariantId ?? this.productVariantId,
    productName: productName ?? this.productName,
    variantName: variantName ?? this.variantName,
    unitPriceMinor: unitPriceMinor ?? this.unitPriceMinor,
    quantity: quantity ?? this.quantity,
    notes: notes.present ? notes.value : this.notes,
  );
  LocalCartItemsTableData copyWithCompanion(LocalCartItemsTableCompanion data) {
    return LocalCartItemsTableData(
      id: data.id.present ? data.id.value : this.id,
      cartId: data.cartId.present ? data.cartId.value : this.cartId,
      productVariantId: data.productVariantId.present
          ? data.productVariantId.value
          : this.productVariantId,
      productName: data.productName.present
          ? data.productName.value
          : this.productName,
      variantName: data.variantName.present
          ? data.variantName.value
          : this.variantName,
      unitPriceMinor: data.unitPriceMinor.present
          ? data.unitPriceMinor.value
          : this.unitPriceMinor,
      quantity: data.quantity.present ? data.quantity.value : this.quantity,
      notes: data.notes.present ? data.notes.value : this.notes,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalCartItemsTableData(')
          ..write('id: $id, ')
          ..write('cartId: $cartId, ')
          ..write('productVariantId: $productVariantId, ')
          ..write('productName: $productName, ')
          ..write('variantName: $variantName, ')
          ..write('unitPriceMinor: $unitPriceMinor, ')
          ..write('quantity: $quantity, ')
          ..write('notes: $notes')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    cartId,
    productVariantId,
    productName,
    variantName,
    unitPriceMinor,
    quantity,
    notes,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalCartItemsTableData &&
          other.id == this.id &&
          other.cartId == this.cartId &&
          other.productVariantId == this.productVariantId &&
          other.productName == this.productName &&
          other.variantName == this.variantName &&
          other.unitPriceMinor == this.unitPriceMinor &&
          other.quantity == this.quantity &&
          other.notes == this.notes);
}

class LocalCartItemsTableCompanion
    extends UpdateCompanion<LocalCartItemsTableData> {
  final Value<String> id;
  final Value<String> cartId;
  final Value<String> productVariantId;
  final Value<String> productName;
  final Value<String> variantName;
  final Value<int> unitPriceMinor;
  final Value<int> quantity;
  final Value<String?> notes;
  final Value<int> rowid;
  const LocalCartItemsTableCompanion({
    this.id = const Value.absent(),
    this.cartId = const Value.absent(),
    this.productVariantId = const Value.absent(),
    this.productName = const Value.absent(),
    this.variantName = const Value.absent(),
    this.unitPriceMinor = const Value.absent(),
    this.quantity = const Value.absent(),
    this.notes = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalCartItemsTableCompanion.insert({
    required String id,
    required String cartId,
    required String productVariantId,
    required String productName,
    required String variantName,
    required int unitPriceMinor,
    this.quantity = const Value.absent(),
    this.notes = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       cartId = Value(cartId),
       productVariantId = Value(productVariantId),
       productName = Value(productName),
       variantName = Value(variantName),
       unitPriceMinor = Value(unitPriceMinor);
  static Insertable<LocalCartItemsTableData> custom({
    Expression<String>? id,
    Expression<String>? cartId,
    Expression<String>? productVariantId,
    Expression<String>? productName,
    Expression<String>? variantName,
    Expression<int>? unitPriceMinor,
    Expression<int>? quantity,
    Expression<String>? notes,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (cartId != null) 'cart_id': cartId,
      if (productVariantId != null) 'product_variant_id': productVariantId,
      if (productName != null) 'product_name': productName,
      if (variantName != null) 'variant_name': variantName,
      if (unitPriceMinor != null) 'unit_price_minor': unitPriceMinor,
      if (quantity != null) 'quantity': quantity,
      if (notes != null) 'notes': notes,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalCartItemsTableCompanion copyWith({
    Value<String>? id,
    Value<String>? cartId,
    Value<String>? productVariantId,
    Value<String>? productName,
    Value<String>? variantName,
    Value<int>? unitPriceMinor,
    Value<int>? quantity,
    Value<String?>? notes,
    Value<int>? rowid,
  }) {
    return LocalCartItemsTableCompanion(
      id: id ?? this.id,
      cartId: cartId ?? this.cartId,
      productVariantId: productVariantId ?? this.productVariantId,
      productName: productName ?? this.productName,
      variantName: variantName ?? this.variantName,
      unitPriceMinor: unitPriceMinor ?? this.unitPriceMinor,
      quantity: quantity ?? this.quantity,
      notes: notes ?? this.notes,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (cartId.present) {
      map['cart_id'] = Variable<String>(cartId.value);
    }
    if (productVariantId.present) {
      map['product_variant_id'] = Variable<String>(productVariantId.value);
    }
    if (productName.present) {
      map['product_name'] = Variable<String>(productName.value);
    }
    if (variantName.present) {
      map['variant_name'] = Variable<String>(variantName.value);
    }
    if (unitPriceMinor.present) {
      map['unit_price_minor'] = Variable<int>(unitPriceMinor.value);
    }
    if (quantity.present) {
      map['quantity'] = Variable<int>(quantity.value);
    }
    if (notes.present) {
      map['notes'] = Variable<String>(notes.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalCartItemsTableCompanion(')
          ..write('id: $id, ')
          ..write('cartId: $cartId, ')
          ..write('productVariantId: $productVariantId, ')
          ..write('productName: $productName, ')
          ..write('variantName: $variantName, ')
          ..write('unitPriceMinor: $unitPriceMinor, ')
          ..write('quantity: $quantity, ')
          ..write('notes: $notes, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalSalesTableTable extends LocalSalesTable
    with TableInfo<$LocalSalesTableTable, LocalSalesTableData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalSalesTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _tenantIdMeta = const VerificationMeta(
    'tenantId',
  );
  @override
  late final GeneratedColumn<String> tenantId = GeneratedColumn<String>(
    'tenant_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _outletIdMeta = const VerificationMeta(
    'outletId',
  );
  @override
  late final GeneratedColumn<String> outletId = GeneratedColumn<String>(
    'outlet_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _shiftIdMeta = const VerificationMeta(
    'shiftId',
  );
  @override
  late final GeneratedColumn<String> shiftId = GeneratedColumn<String>(
    'shift_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _referenceNumberMeta = const VerificationMeta(
    'referenceNumber',
  );
  @override
  late final GeneratedColumn<String> referenceNumber = GeneratedColumn<String>(
    'reference_number',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _cashierUserIdMeta = const VerificationMeta(
    'cashierUserId',
  );
  @override
  late final GeneratedColumn<String> cashierUserId = GeneratedColumn<String>(
    'cashier_user_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _customerIdMeta = const VerificationMeta(
    'customerId',
  );
  @override
  late final GeneratedColumn<String> customerId = GeneratedColumn<String>(
    'customer_id',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _customerNameSnapshotMeta =
      const VerificationMeta('customerNameSnapshot');
  @override
  late final GeneratedColumn<String> customerNameSnapshot =
      GeneratedColumn<String>(
        'customer_name_snapshot',
        aliasedName,
        true,
        type: DriftSqlType.string,
        requiredDuringInsert: false,
      );
  static const VerificationMeta _statusMeta = const VerificationMeta('status');
  @override
  late final GeneratedColumn<String> status = GeneratedColumn<String>(
    'status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('open'),
  );
  static const VerificationMeta _syncStatusMeta = const VerificationMeta(
    'syncStatus',
  );
  @override
  late final GeneratedColumn<String> syncStatus = GeneratedColumn<String>(
    'sync_status',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('pending'),
  );
  static const VerificationMeta _subtotalMinorMeta = const VerificationMeta(
    'subtotalMinor',
  );
  @override
  late final GeneratedColumn<int> subtotalMinor = GeneratedColumn<int>(
    'subtotal_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _discountMinorMeta = const VerificationMeta(
    'discountMinor',
  );
  @override
  late final GeneratedColumn<int> discountMinor = GeneratedColumn<int>(
    'discount_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _taxMinorMeta = const VerificationMeta(
    'taxMinor',
  );
  @override
  late final GeneratedColumn<int> taxMinor = GeneratedColumn<int>(
    'tax_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _totalMinorMeta = const VerificationMeta(
    'totalMinor',
  );
  @override
  late final GeneratedColumn<int> totalMinor = GeneratedColumn<int>(
    'total_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _paymentMethodMeta = const VerificationMeta(
    'paymentMethod',
  );
  @override
  late final GeneratedColumn<String> paymentMethod = GeneratedColumn<String>(
    'payment_method',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _cashReceivedMinorMeta = const VerificationMeta(
    'cashReceivedMinor',
  );
  @override
  late final GeneratedColumn<int> cashReceivedMinor = GeneratedColumn<int>(
    'cash_received_minor',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _cashChangeMinorMeta = const VerificationMeta(
    'cashChangeMinor',
  );
  @override
  late final GeneratedColumn<int> cashChangeMinor = GeneratedColumn<int>(
    'cash_change_minor',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _notesMeta = const VerificationMeta('notes');
  @override
  late final GeneratedColumn<String> notes = GeneratedColumn<String>(
    'notes',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _openedAtMeta = const VerificationMeta(
    'openedAt',
  );
  @override
  late final GeneratedColumn<DateTime> openedAt = GeneratedColumn<DateTime>(
    'opened_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _finalizedAtMeta = const VerificationMeta(
    'finalizedAt',
  );
  @override
  late final GeneratedColumn<DateTime> finalizedAt = GeneratedColumn<DateTime>(
    'finalized_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _syncedAtMeta = const VerificationMeta(
    'syncedAt',
  );
  @override
  late final GeneratedColumn<DateTime> syncedAt = GeneratedColumn<DateTime>(
    'synced_at',
    aliasedName,
    true,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _syncErrorMeta = const VerificationMeta(
    'syncError',
  );
  @override
  late final GeneratedColumn<String> syncError = GeneratedColumn<String>(
    'sync_error',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<DateTime> createdAt = GeneratedColumn<DateTime>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    tenantId,
    outletId,
    shiftId,
    referenceNumber,
    cashierUserId,
    customerId,
    customerNameSnapshot,
    status,
    syncStatus,
    subtotalMinor,
    discountMinor,
    taxMinor,
    totalMinor,
    paymentMethod,
    cashReceivedMinor,
    cashChangeMinor,
    notes,
    openedAt,
    finalizedAt,
    syncedAt,
    syncError,
    createdAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_sales';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalSalesTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('tenant_id')) {
      context.handle(
        _tenantIdMeta,
        tenantId.isAcceptableOrUnknown(data['tenant_id']!, _tenantIdMeta),
      );
    } else if (isInserting) {
      context.missing(_tenantIdMeta);
    }
    if (data.containsKey('outlet_id')) {
      context.handle(
        _outletIdMeta,
        outletId.isAcceptableOrUnknown(data['outlet_id']!, _outletIdMeta),
      );
    } else if (isInserting) {
      context.missing(_outletIdMeta);
    }
    if (data.containsKey('shift_id')) {
      context.handle(
        _shiftIdMeta,
        shiftId.isAcceptableOrUnknown(data['shift_id']!, _shiftIdMeta),
      );
    }
    if (data.containsKey('reference_number')) {
      context.handle(
        _referenceNumberMeta,
        referenceNumber.isAcceptableOrUnknown(
          data['reference_number']!,
          _referenceNumberMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_referenceNumberMeta);
    }
    if (data.containsKey('cashier_user_id')) {
      context.handle(
        _cashierUserIdMeta,
        cashierUserId.isAcceptableOrUnknown(
          data['cashier_user_id']!,
          _cashierUserIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_cashierUserIdMeta);
    }
    if (data.containsKey('customer_id')) {
      context.handle(
        _customerIdMeta,
        customerId.isAcceptableOrUnknown(data['customer_id']!, _customerIdMeta),
      );
    }
    if (data.containsKey('customer_name_snapshot')) {
      context.handle(
        _customerNameSnapshotMeta,
        customerNameSnapshot.isAcceptableOrUnknown(
          data['customer_name_snapshot']!,
          _customerNameSnapshotMeta,
        ),
      );
    }
    if (data.containsKey('status')) {
      context.handle(
        _statusMeta,
        status.isAcceptableOrUnknown(data['status']!, _statusMeta),
      );
    }
    if (data.containsKey('sync_status')) {
      context.handle(
        _syncStatusMeta,
        syncStatus.isAcceptableOrUnknown(data['sync_status']!, _syncStatusMeta),
      );
    }
    if (data.containsKey('subtotal_minor')) {
      context.handle(
        _subtotalMinorMeta,
        subtotalMinor.isAcceptableOrUnknown(
          data['subtotal_minor']!,
          _subtotalMinorMeta,
        ),
      );
    }
    if (data.containsKey('discount_minor')) {
      context.handle(
        _discountMinorMeta,
        discountMinor.isAcceptableOrUnknown(
          data['discount_minor']!,
          _discountMinorMeta,
        ),
      );
    }
    if (data.containsKey('tax_minor')) {
      context.handle(
        _taxMinorMeta,
        taxMinor.isAcceptableOrUnknown(data['tax_minor']!, _taxMinorMeta),
      );
    }
    if (data.containsKey('total_minor')) {
      context.handle(
        _totalMinorMeta,
        totalMinor.isAcceptableOrUnknown(data['total_minor']!, _totalMinorMeta),
      );
    }
    if (data.containsKey('payment_method')) {
      context.handle(
        _paymentMethodMeta,
        paymentMethod.isAcceptableOrUnknown(
          data['payment_method']!,
          _paymentMethodMeta,
        ),
      );
    }
    if (data.containsKey('cash_received_minor')) {
      context.handle(
        _cashReceivedMinorMeta,
        cashReceivedMinor.isAcceptableOrUnknown(
          data['cash_received_minor']!,
          _cashReceivedMinorMeta,
        ),
      );
    }
    if (data.containsKey('cash_change_minor')) {
      context.handle(
        _cashChangeMinorMeta,
        cashChangeMinor.isAcceptableOrUnknown(
          data['cash_change_minor']!,
          _cashChangeMinorMeta,
        ),
      );
    }
    if (data.containsKey('notes')) {
      context.handle(
        _notesMeta,
        notes.isAcceptableOrUnknown(data['notes']!, _notesMeta),
      );
    }
    if (data.containsKey('opened_at')) {
      context.handle(
        _openedAtMeta,
        openedAt.isAcceptableOrUnknown(data['opened_at']!, _openedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_openedAtMeta);
    }
    if (data.containsKey('finalized_at')) {
      context.handle(
        _finalizedAtMeta,
        finalizedAt.isAcceptableOrUnknown(
          data['finalized_at']!,
          _finalizedAtMeta,
        ),
      );
    }
    if (data.containsKey('synced_at')) {
      context.handle(
        _syncedAtMeta,
        syncedAt.isAcceptableOrUnknown(data['synced_at']!, _syncedAtMeta),
      );
    }
    if (data.containsKey('sync_error')) {
      context.handle(
        _syncErrorMeta,
        syncError.isAcceptableOrUnknown(data['sync_error']!, _syncErrorMeta),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalSalesTableData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalSalesTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      tenantId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}tenant_id'],
      )!,
      outletId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}outlet_id'],
      )!,
      shiftId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}shift_id'],
      ),
      referenceNumber: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}reference_number'],
      )!,
      cashierUserId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}cashier_user_id'],
      )!,
      customerId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}customer_id'],
      ),
      customerNameSnapshot: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}customer_name_snapshot'],
      ),
      status: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}status'],
      )!,
      syncStatus: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sync_status'],
      )!,
      subtotalMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}subtotal_minor'],
      )!,
      discountMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}discount_minor'],
      )!,
      taxMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}tax_minor'],
      )!,
      totalMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}total_minor'],
      )!,
      paymentMethod: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}payment_method'],
      ),
      cashReceivedMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}cash_received_minor'],
      ),
      cashChangeMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}cash_change_minor'],
      ),
      notes: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}notes'],
      ),
      openedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}opened_at'],
      )!,
      finalizedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}finalized_at'],
      ),
      syncedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}synced_at'],
      ),
      syncError: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sync_error'],
      ),
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}created_at'],
      )!,
    );
  }

  @override
  $LocalSalesTableTable createAlias(String alias) {
    return $LocalSalesTableTable(attachedDatabase, alias);
  }
}

class LocalSalesTableData extends DataClass
    implements Insertable<LocalSalesTableData> {
  /// Client-generated UUIDv7 identifier
  final String id;
  final String tenantId;
  final String outletId;
  final String? shiftId;
  final String referenceNumber;
  final String cashierUserId;
  final String? customerId;
  final String? customerNameSnapshot;
  final String status;
  final String syncStatus;
  final int subtotalMinor;
  final int discountMinor;
  final int taxMinor;
  final int totalMinor;
  final String? paymentMethod;
  final int? cashReceivedMinor;
  final int? cashChangeMinor;
  final String? notes;
  final DateTime openedAt;
  final DateTime? finalizedAt;
  final DateTime? syncedAt;
  final String? syncError;
  final DateTime createdAt;
  const LocalSalesTableData({
    required this.id,
    required this.tenantId,
    required this.outletId,
    this.shiftId,
    required this.referenceNumber,
    required this.cashierUserId,
    this.customerId,
    this.customerNameSnapshot,
    required this.status,
    required this.syncStatus,
    required this.subtotalMinor,
    required this.discountMinor,
    required this.taxMinor,
    required this.totalMinor,
    this.paymentMethod,
    this.cashReceivedMinor,
    this.cashChangeMinor,
    this.notes,
    required this.openedAt,
    this.finalizedAt,
    this.syncedAt,
    this.syncError,
    required this.createdAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['tenant_id'] = Variable<String>(tenantId);
    map['outlet_id'] = Variable<String>(outletId);
    if (!nullToAbsent || shiftId != null) {
      map['shift_id'] = Variable<String>(shiftId);
    }
    map['reference_number'] = Variable<String>(referenceNumber);
    map['cashier_user_id'] = Variable<String>(cashierUserId);
    if (!nullToAbsent || customerId != null) {
      map['customer_id'] = Variable<String>(customerId);
    }
    if (!nullToAbsent || customerNameSnapshot != null) {
      map['customer_name_snapshot'] = Variable<String>(customerNameSnapshot);
    }
    map['status'] = Variable<String>(status);
    map['sync_status'] = Variable<String>(syncStatus);
    map['subtotal_minor'] = Variable<int>(subtotalMinor);
    map['discount_minor'] = Variable<int>(discountMinor);
    map['tax_minor'] = Variable<int>(taxMinor);
    map['total_minor'] = Variable<int>(totalMinor);
    if (!nullToAbsent || paymentMethod != null) {
      map['payment_method'] = Variable<String>(paymentMethod);
    }
    if (!nullToAbsent || cashReceivedMinor != null) {
      map['cash_received_minor'] = Variable<int>(cashReceivedMinor);
    }
    if (!nullToAbsent || cashChangeMinor != null) {
      map['cash_change_minor'] = Variable<int>(cashChangeMinor);
    }
    if (!nullToAbsent || notes != null) {
      map['notes'] = Variable<String>(notes);
    }
    map['opened_at'] = Variable<DateTime>(openedAt);
    if (!nullToAbsent || finalizedAt != null) {
      map['finalized_at'] = Variable<DateTime>(finalizedAt);
    }
    if (!nullToAbsent || syncedAt != null) {
      map['synced_at'] = Variable<DateTime>(syncedAt);
    }
    if (!nullToAbsent || syncError != null) {
      map['sync_error'] = Variable<String>(syncError);
    }
    map['created_at'] = Variable<DateTime>(createdAt);
    return map;
  }

  LocalSalesTableCompanion toCompanion(bool nullToAbsent) {
    return LocalSalesTableCompanion(
      id: Value(id),
      tenantId: Value(tenantId),
      outletId: Value(outletId),
      shiftId: shiftId == null && nullToAbsent
          ? const Value.absent()
          : Value(shiftId),
      referenceNumber: Value(referenceNumber),
      cashierUserId: Value(cashierUserId),
      customerId: customerId == null && nullToAbsent
          ? const Value.absent()
          : Value(customerId),
      customerNameSnapshot: customerNameSnapshot == null && nullToAbsent
          ? const Value.absent()
          : Value(customerNameSnapshot),
      status: Value(status),
      syncStatus: Value(syncStatus),
      subtotalMinor: Value(subtotalMinor),
      discountMinor: Value(discountMinor),
      taxMinor: Value(taxMinor),
      totalMinor: Value(totalMinor),
      paymentMethod: paymentMethod == null && nullToAbsent
          ? const Value.absent()
          : Value(paymentMethod),
      cashReceivedMinor: cashReceivedMinor == null && nullToAbsent
          ? const Value.absent()
          : Value(cashReceivedMinor),
      cashChangeMinor: cashChangeMinor == null && nullToAbsent
          ? const Value.absent()
          : Value(cashChangeMinor),
      notes: notes == null && nullToAbsent
          ? const Value.absent()
          : Value(notes),
      openedAt: Value(openedAt),
      finalizedAt: finalizedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(finalizedAt),
      syncedAt: syncedAt == null && nullToAbsent
          ? const Value.absent()
          : Value(syncedAt),
      syncError: syncError == null && nullToAbsent
          ? const Value.absent()
          : Value(syncError),
      createdAt: Value(createdAt),
    );
  }

  factory LocalSalesTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalSalesTableData(
      id: serializer.fromJson<String>(json['id']),
      tenantId: serializer.fromJson<String>(json['tenantId']),
      outletId: serializer.fromJson<String>(json['outletId']),
      shiftId: serializer.fromJson<String?>(json['shiftId']),
      referenceNumber: serializer.fromJson<String>(json['referenceNumber']),
      cashierUserId: serializer.fromJson<String>(json['cashierUserId']),
      customerId: serializer.fromJson<String?>(json['customerId']),
      customerNameSnapshot: serializer.fromJson<String?>(
        json['customerNameSnapshot'],
      ),
      status: serializer.fromJson<String>(json['status']),
      syncStatus: serializer.fromJson<String>(json['syncStatus']),
      subtotalMinor: serializer.fromJson<int>(json['subtotalMinor']),
      discountMinor: serializer.fromJson<int>(json['discountMinor']),
      taxMinor: serializer.fromJson<int>(json['taxMinor']),
      totalMinor: serializer.fromJson<int>(json['totalMinor']),
      paymentMethod: serializer.fromJson<String?>(json['paymentMethod']),
      cashReceivedMinor: serializer.fromJson<int?>(json['cashReceivedMinor']),
      cashChangeMinor: serializer.fromJson<int?>(json['cashChangeMinor']),
      notes: serializer.fromJson<String?>(json['notes']),
      openedAt: serializer.fromJson<DateTime>(json['openedAt']),
      finalizedAt: serializer.fromJson<DateTime?>(json['finalizedAt']),
      syncedAt: serializer.fromJson<DateTime?>(json['syncedAt']),
      syncError: serializer.fromJson<String?>(json['syncError']),
      createdAt: serializer.fromJson<DateTime>(json['createdAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'tenantId': serializer.toJson<String>(tenantId),
      'outletId': serializer.toJson<String>(outletId),
      'shiftId': serializer.toJson<String?>(shiftId),
      'referenceNumber': serializer.toJson<String>(referenceNumber),
      'cashierUserId': serializer.toJson<String>(cashierUserId),
      'customerId': serializer.toJson<String?>(customerId),
      'customerNameSnapshot': serializer.toJson<String?>(customerNameSnapshot),
      'status': serializer.toJson<String>(status),
      'syncStatus': serializer.toJson<String>(syncStatus),
      'subtotalMinor': serializer.toJson<int>(subtotalMinor),
      'discountMinor': serializer.toJson<int>(discountMinor),
      'taxMinor': serializer.toJson<int>(taxMinor),
      'totalMinor': serializer.toJson<int>(totalMinor),
      'paymentMethod': serializer.toJson<String?>(paymentMethod),
      'cashReceivedMinor': serializer.toJson<int?>(cashReceivedMinor),
      'cashChangeMinor': serializer.toJson<int?>(cashChangeMinor),
      'notes': serializer.toJson<String?>(notes),
      'openedAt': serializer.toJson<DateTime>(openedAt),
      'finalizedAt': serializer.toJson<DateTime?>(finalizedAt),
      'syncedAt': serializer.toJson<DateTime?>(syncedAt),
      'syncError': serializer.toJson<String?>(syncError),
      'createdAt': serializer.toJson<DateTime>(createdAt),
    };
  }

  LocalSalesTableData copyWith({
    String? id,
    String? tenantId,
    String? outletId,
    Value<String?> shiftId = const Value.absent(),
    String? referenceNumber,
    String? cashierUserId,
    Value<String?> customerId = const Value.absent(),
    Value<String?> customerNameSnapshot = const Value.absent(),
    String? status,
    String? syncStatus,
    int? subtotalMinor,
    int? discountMinor,
    int? taxMinor,
    int? totalMinor,
    Value<String?> paymentMethod = const Value.absent(),
    Value<int?> cashReceivedMinor = const Value.absent(),
    Value<int?> cashChangeMinor = const Value.absent(),
    Value<String?> notes = const Value.absent(),
    DateTime? openedAt,
    Value<DateTime?> finalizedAt = const Value.absent(),
    Value<DateTime?> syncedAt = const Value.absent(),
    Value<String?> syncError = const Value.absent(),
    DateTime? createdAt,
  }) => LocalSalesTableData(
    id: id ?? this.id,
    tenantId: tenantId ?? this.tenantId,
    outletId: outletId ?? this.outletId,
    shiftId: shiftId.present ? shiftId.value : this.shiftId,
    referenceNumber: referenceNumber ?? this.referenceNumber,
    cashierUserId: cashierUserId ?? this.cashierUserId,
    customerId: customerId.present ? customerId.value : this.customerId,
    customerNameSnapshot: customerNameSnapshot.present
        ? customerNameSnapshot.value
        : this.customerNameSnapshot,
    status: status ?? this.status,
    syncStatus: syncStatus ?? this.syncStatus,
    subtotalMinor: subtotalMinor ?? this.subtotalMinor,
    discountMinor: discountMinor ?? this.discountMinor,
    taxMinor: taxMinor ?? this.taxMinor,
    totalMinor: totalMinor ?? this.totalMinor,
    paymentMethod: paymentMethod.present
        ? paymentMethod.value
        : this.paymentMethod,
    cashReceivedMinor: cashReceivedMinor.present
        ? cashReceivedMinor.value
        : this.cashReceivedMinor,
    cashChangeMinor: cashChangeMinor.present
        ? cashChangeMinor.value
        : this.cashChangeMinor,
    notes: notes.present ? notes.value : this.notes,
    openedAt: openedAt ?? this.openedAt,
    finalizedAt: finalizedAt.present ? finalizedAt.value : this.finalizedAt,
    syncedAt: syncedAt.present ? syncedAt.value : this.syncedAt,
    syncError: syncError.present ? syncError.value : this.syncError,
    createdAt: createdAt ?? this.createdAt,
  );
  LocalSalesTableData copyWithCompanion(LocalSalesTableCompanion data) {
    return LocalSalesTableData(
      id: data.id.present ? data.id.value : this.id,
      tenantId: data.tenantId.present ? data.tenantId.value : this.tenantId,
      outletId: data.outletId.present ? data.outletId.value : this.outletId,
      shiftId: data.shiftId.present ? data.shiftId.value : this.shiftId,
      referenceNumber: data.referenceNumber.present
          ? data.referenceNumber.value
          : this.referenceNumber,
      cashierUserId: data.cashierUserId.present
          ? data.cashierUserId.value
          : this.cashierUserId,
      customerId: data.customerId.present
          ? data.customerId.value
          : this.customerId,
      customerNameSnapshot: data.customerNameSnapshot.present
          ? data.customerNameSnapshot.value
          : this.customerNameSnapshot,
      status: data.status.present ? data.status.value : this.status,
      syncStatus: data.syncStatus.present
          ? data.syncStatus.value
          : this.syncStatus,
      subtotalMinor: data.subtotalMinor.present
          ? data.subtotalMinor.value
          : this.subtotalMinor,
      discountMinor: data.discountMinor.present
          ? data.discountMinor.value
          : this.discountMinor,
      taxMinor: data.taxMinor.present ? data.taxMinor.value : this.taxMinor,
      totalMinor: data.totalMinor.present
          ? data.totalMinor.value
          : this.totalMinor,
      paymentMethod: data.paymentMethod.present
          ? data.paymentMethod.value
          : this.paymentMethod,
      cashReceivedMinor: data.cashReceivedMinor.present
          ? data.cashReceivedMinor.value
          : this.cashReceivedMinor,
      cashChangeMinor: data.cashChangeMinor.present
          ? data.cashChangeMinor.value
          : this.cashChangeMinor,
      notes: data.notes.present ? data.notes.value : this.notes,
      openedAt: data.openedAt.present ? data.openedAt.value : this.openedAt,
      finalizedAt: data.finalizedAt.present
          ? data.finalizedAt.value
          : this.finalizedAt,
      syncedAt: data.syncedAt.present ? data.syncedAt.value : this.syncedAt,
      syncError: data.syncError.present ? data.syncError.value : this.syncError,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalSalesTableData(')
          ..write('id: $id, ')
          ..write('tenantId: $tenantId, ')
          ..write('outletId: $outletId, ')
          ..write('shiftId: $shiftId, ')
          ..write('referenceNumber: $referenceNumber, ')
          ..write('cashierUserId: $cashierUserId, ')
          ..write('customerId: $customerId, ')
          ..write('customerNameSnapshot: $customerNameSnapshot, ')
          ..write('status: $status, ')
          ..write('syncStatus: $syncStatus, ')
          ..write('subtotalMinor: $subtotalMinor, ')
          ..write('discountMinor: $discountMinor, ')
          ..write('taxMinor: $taxMinor, ')
          ..write('totalMinor: $totalMinor, ')
          ..write('paymentMethod: $paymentMethod, ')
          ..write('cashReceivedMinor: $cashReceivedMinor, ')
          ..write('cashChangeMinor: $cashChangeMinor, ')
          ..write('notes: $notes, ')
          ..write('openedAt: $openedAt, ')
          ..write('finalizedAt: $finalizedAt, ')
          ..write('syncedAt: $syncedAt, ')
          ..write('syncError: $syncError, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hashAll([
    id,
    tenantId,
    outletId,
    shiftId,
    referenceNumber,
    cashierUserId,
    customerId,
    customerNameSnapshot,
    status,
    syncStatus,
    subtotalMinor,
    discountMinor,
    taxMinor,
    totalMinor,
    paymentMethod,
    cashReceivedMinor,
    cashChangeMinor,
    notes,
    openedAt,
    finalizedAt,
    syncedAt,
    syncError,
    createdAt,
  ]);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalSalesTableData &&
          other.id == this.id &&
          other.tenantId == this.tenantId &&
          other.outletId == this.outletId &&
          other.shiftId == this.shiftId &&
          other.referenceNumber == this.referenceNumber &&
          other.cashierUserId == this.cashierUserId &&
          other.customerId == this.customerId &&
          other.customerNameSnapshot == this.customerNameSnapshot &&
          other.status == this.status &&
          other.syncStatus == this.syncStatus &&
          other.subtotalMinor == this.subtotalMinor &&
          other.discountMinor == this.discountMinor &&
          other.taxMinor == this.taxMinor &&
          other.totalMinor == this.totalMinor &&
          other.paymentMethod == this.paymentMethod &&
          other.cashReceivedMinor == this.cashReceivedMinor &&
          other.cashChangeMinor == this.cashChangeMinor &&
          other.notes == this.notes &&
          other.openedAt == this.openedAt &&
          other.finalizedAt == this.finalizedAt &&
          other.syncedAt == this.syncedAt &&
          other.syncError == this.syncError &&
          other.createdAt == this.createdAt);
}

class LocalSalesTableCompanion extends UpdateCompanion<LocalSalesTableData> {
  final Value<String> id;
  final Value<String> tenantId;
  final Value<String> outletId;
  final Value<String?> shiftId;
  final Value<String> referenceNumber;
  final Value<String> cashierUserId;
  final Value<String?> customerId;
  final Value<String?> customerNameSnapshot;
  final Value<String> status;
  final Value<String> syncStatus;
  final Value<int> subtotalMinor;
  final Value<int> discountMinor;
  final Value<int> taxMinor;
  final Value<int> totalMinor;
  final Value<String?> paymentMethod;
  final Value<int?> cashReceivedMinor;
  final Value<int?> cashChangeMinor;
  final Value<String?> notes;
  final Value<DateTime> openedAt;
  final Value<DateTime?> finalizedAt;
  final Value<DateTime?> syncedAt;
  final Value<String?> syncError;
  final Value<DateTime> createdAt;
  final Value<int> rowid;
  const LocalSalesTableCompanion({
    this.id = const Value.absent(),
    this.tenantId = const Value.absent(),
    this.outletId = const Value.absent(),
    this.shiftId = const Value.absent(),
    this.referenceNumber = const Value.absent(),
    this.cashierUserId = const Value.absent(),
    this.customerId = const Value.absent(),
    this.customerNameSnapshot = const Value.absent(),
    this.status = const Value.absent(),
    this.syncStatus = const Value.absent(),
    this.subtotalMinor = const Value.absent(),
    this.discountMinor = const Value.absent(),
    this.taxMinor = const Value.absent(),
    this.totalMinor = const Value.absent(),
    this.paymentMethod = const Value.absent(),
    this.cashReceivedMinor = const Value.absent(),
    this.cashChangeMinor = const Value.absent(),
    this.notes = const Value.absent(),
    this.openedAt = const Value.absent(),
    this.finalizedAt = const Value.absent(),
    this.syncedAt = const Value.absent(),
    this.syncError = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalSalesTableCompanion.insert({
    required String id,
    required String tenantId,
    required String outletId,
    this.shiftId = const Value.absent(),
    required String referenceNumber,
    required String cashierUserId,
    this.customerId = const Value.absent(),
    this.customerNameSnapshot = const Value.absent(),
    this.status = const Value.absent(),
    this.syncStatus = const Value.absent(),
    this.subtotalMinor = const Value.absent(),
    this.discountMinor = const Value.absent(),
    this.taxMinor = const Value.absent(),
    this.totalMinor = const Value.absent(),
    this.paymentMethod = const Value.absent(),
    this.cashReceivedMinor = const Value.absent(),
    this.cashChangeMinor = const Value.absent(),
    this.notes = const Value.absent(),
    required DateTime openedAt,
    this.finalizedAt = const Value.absent(),
    this.syncedAt = const Value.absent(),
    this.syncError = const Value.absent(),
    required DateTime createdAt,
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       tenantId = Value(tenantId),
       outletId = Value(outletId),
       referenceNumber = Value(referenceNumber),
       cashierUserId = Value(cashierUserId),
       openedAt = Value(openedAt),
       createdAt = Value(createdAt);
  static Insertable<LocalSalesTableData> custom({
    Expression<String>? id,
    Expression<String>? tenantId,
    Expression<String>? outletId,
    Expression<String>? shiftId,
    Expression<String>? referenceNumber,
    Expression<String>? cashierUserId,
    Expression<String>? customerId,
    Expression<String>? customerNameSnapshot,
    Expression<String>? status,
    Expression<String>? syncStatus,
    Expression<int>? subtotalMinor,
    Expression<int>? discountMinor,
    Expression<int>? taxMinor,
    Expression<int>? totalMinor,
    Expression<String>? paymentMethod,
    Expression<int>? cashReceivedMinor,
    Expression<int>? cashChangeMinor,
    Expression<String>? notes,
    Expression<DateTime>? openedAt,
    Expression<DateTime>? finalizedAt,
    Expression<DateTime>? syncedAt,
    Expression<String>? syncError,
    Expression<DateTime>? createdAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (tenantId != null) 'tenant_id': tenantId,
      if (outletId != null) 'outlet_id': outletId,
      if (shiftId != null) 'shift_id': shiftId,
      if (referenceNumber != null) 'reference_number': referenceNumber,
      if (cashierUserId != null) 'cashier_user_id': cashierUserId,
      if (customerId != null) 'customer_id': customerId,
      if (customerNameSnapshot != null)
        'customer_name_snapshot': customerNameSnapshot,
      if (status != null) 'status': status,
      if (syncStatus != null) 'sync_status': syncStatus,
      if (subtotalMinor != null) 'subtotal_minor': subtotalMinor,
      if (discountMinor != null) 'discount_minor': discountMinor,
      if (taxMinor != null) 'tax_minor': taxMinor,
      if (totalMinor != null) 'total_minor': totalMinor,
      if (paymentMethod != null) 'payment_method': paymentMethod,
      if (cashReceivedMinor != null) 'cash_received_minor': cashReceivedMinor,
      if (cashChangeMinor != null) 'cash_change_minor': cashChangeMinor,
      if (notes != null) 'notes': notes,
      if (openedAt != null) 'opened_at': openedAt,
      if (finalizedAt != null) 'finalized_at': finalizedAt,
      if (syncedAt != null) 'synced_at': syncedAt,
      if (syncError != null) 'sync_error': syncError,
      if (createdAt != null) 'created_at': createdAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalSalesTableCompanion copyWith({
    Value<String>? id,
    Value<String>? tenantId,
    Value<String>? outletId,
    Value<String?>? shiftId,
    Value<String>? referenceNumber,
    Value<String>? cashierUserId,
    Value<String?>? customerId,
    Value<String?>? customerNameSnapshot,
    Value<String>? status,
    Value<String>? syncStatus,
    Value<int>? subtotalMinor,
    Value<int>? discountMinor,
    Value<int>? taxMinor,
    Value<int>? totalMinor,
    Value<String?>? paymentMethod,
    Value<int?>? cashReceivedMinor,
    Value<int?>? cashChangeMinor,
    Value<String?>? notes,
    Value<DateTime>? openedAt,
    Value<DateTime?>? finalizedAt,
    Value<DateTime?>? syncedAt,
    Value<String?>? syncError,
    Value<DateTime>? createdAt,
    Value<int>? rowid,
  }) {
    return LocalSalesTableCompanion(
      id: id ?? this.id,
      tenantId: tenantId ?? this.tenantId,
      outletId: outletId ?? this.outletId,
      shiftId: shiftId ?? this.shiftId,
      referenceNumber: referenceNumber ?? this.referenceNumber,
      cashierUserId: cashierUserId ?? this.cashierUserId,
      customerId: customerId ?? this.customerId,
      customerNameSnapshot: customerNameSnapshot ?? this.customerNameSnapshot,
      status: status ?? this.status,
      syncStatus: syncStatus ?? this.syncStatus,
      subtotalMinor: subtotalMinor ?? this.subtotalMinor,
      discountMinor: discountMinor ?? this.discountMinor,
      taxMinor: taxMinor ?? this.taxMinor,
      totalMinor: totalMinor ?? this.totalMinor,
      paymentMethod: paymentMethod ?? this.paymentMethod,
      cashReceivedMinor: cashReceivedMinor ?? this.cashReceivedMinor,
      cashChangeMinor: cashChangeMinor ?? this.cashChangeMinor,
      notes: notes ?? this.notes,
      openedAt: openedAt ?? this.openedAt,
      finalizedAt: finalizedAt ?? this.finalizedAt,
      syncedAt: syncedAt ?? this.syncedAt,
      syncError: syncError ?? this.syncError,
      createdAt: createdAt ?? this.createdAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (tenantId.present) {
      map['tenant_id'] = Variable<String>(tenantId.value);
    }
    if (outletId.present) {
      map['outlet_id'] = Variable<String>(outletId.value);
    }
    if (shiftId.present) {
      map['shift_id'] = Variable<String>(shiftId.value);
    }
    if (referenceNumber.present) {
      map['reference_number'] = Variable<String>(referenceNumber.value);
    }
    if (cashierUserId.present) {
      map['cashier_user_id'] = Variable<String>(cashierUserId.value);
    }
    if (customerId.present) {
      map['customer_id'] = Variable<String>(customerId.value);
    }
    if (customerNameSnapshot.present) {
      map['customer_name_snapshot'] = Variable<String>(
        customerNameSnapshot.value,
      );
    }
    if (status.present) {
      map['status'] = Variable<String>(status.value);
    }
    if (syncStatus.present) {
      map['sync_status'] = Variable<String>(syncStatus.value);
    }
    if (subtotalMinor.present) {
      map['subtotal_minor'] = Variable<int>(subtotalMinor.value);
    }
    if (discountMinor.present) {
      map['discount_minor'] = Variable<int>(discountMinor.value);
    }
    if (taxMinor.present) {
      map['tax_minor'] = Variable<int>(taxMinor.value);
    }
    if (totalMinor.present) {
      map['total_minor'] = Variable<int>(totalMinor.value);
    }
    if (paymentMethod.present) {
      map['payment_method'] = Variable<String>(paymentMethod.value);
    }
    if (cashReceivedMinor.present) {
      map['cash_received_minor'] = Variable<int>(cashReceivedMinor.value);
    }
    if (cashChangeMinor.present) {
      map['cash_change_minor'] = Variable<int>(cashChangeMinor.value);
    }
    if (notes.present) {
      map['notes'] = Variable<String>(notes.value);
    }
    if (openedAt.present) {
      map['opened_at'] = Variable<DateTime>(openedAt.value);
    }
    if (finalizedAt.present) {
      map['finalized_at'] = Variable<DateTime>(finalizedAt.value);
    }
    if (syncedAt.present) {
      map['synced_at'] = Variable<DateTime>(syncedAt.value);
    }
    if (syncError.present) {
      map['sync_error'] = Variable<String>(syncError.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<DateTime>(createdAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalSalesTableCompanion(')
          ..write('id: $id, ')
          ..write('tenantId: $tenantId, ')
          ..write('outletId: $outletId, ')
          ..write('shiftId: $shiftId, ')
          ..write('referenceNumber: $referenceNumber, ')
          ..write('cashierUserId: $cashierUserId, ')
          ..write('customerId: $customerId, ')
          ..write('customerNameSnapshot: $customerNameSnapshot, ')
          ..write('status: $status, ')
          ..write('syncStatus: $syncStatus, ')
          ..write('subtotalMinor: $subtotalMinor, ')
          ..write('discountMinor: $discountMinor, ')
          ..write('taxMinor: $taxMinor, ')
          ..write('totalMinor: $totalMinor, ')
          ..write('paymentMethod: $paymentMethod, ')
          ..write('cashReceivedMinor: $cashReceivedMinor, ')
          ..write('cashChangeMinor: $cashChangeMinor, ')
          ..write('notes: $notes, ')
          ..write('openedAt: $openedAt, ')
          ..write('finalizedAt: $finalizedAt, ')
          ..write('syncedAt: $syncedAt, ')
          ..write('syncError: $syncError, ')
          ..write('createdAt: $createdAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $LocalSaleItemsTableTable extends LocalSaleItemsTable
    with TableInfo<$LocalSaleItemsTableTable, LocalSaleItemsTableData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $LocalSaleItemsTableTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<String> id = GeneratedColumn<String>(
    'id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _saleIdMeta = const VerificationMeta('saleId');
  @override
  late final GeneratedColumn<String> saleId = GeneratedColumn<String>(
    'sale_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _productVariantIdMeta = const VerificationMeta(
    'productVariantId',
  );
  @override
  late final GeneratedColumn<String> productVariantId = GeneratedColumn<String>(
    'product_variant_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _productNameSnapshotMeta =
      const VerificationMeta('productNameSnapshot');
  @override
  late final GeneratedColumn<String> productNameSnapshot =
      GeneratedColumn<String>(
        'product_name_snapshot',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      );
  static const VerificationMeta _variantNameSnapshotMeta =
      const VerificationMeta('variantNameSnapshot');
  @override
  late final GeneratedColumn<String> variantNameSnapshot =
      GeneratedColumn<String>(
        'variant_name_snapshot',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      );
  static const VerificationMeta _quantityMeta = const VerificationMeta(
    'quantity',
  );
  @override
  late final GeneratedColumn<double> quantity = GeneratedColumn<double>(
    'quantity',
    aliasedName,
    false,
    type: DriftSqlType.double,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _unitPriceMinorMeta = const VerificationMeta(
    'unitPriceMinor',
  );
  @override
  late final GeneratedColumn<int> unitPriceMinor = GeneratedColumn<int>(
    'unit_price_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _subtotalMinorMeta = const VerificationMeta(
    'subtotalMinor',
  );
  @override
  late final GeneratedColumn<int> subtotalMinor = GeneratedColumn<int>(
    'subtotal_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _discountMinorMeta = const VerificationMeta(
    'discountMinor',
  );
  @override
  late final GeneratedColumn<int> discountMinor = GeneratedColumn<int>(
    'discount_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _totalMinorMeta = const VerificationMeta(
    'totalMinor',
  );
  @override
  late final GeneratedColumn<int> totalMinor = GeneratedColumn<int>(
    'total_minor',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _notesMeta = const VerificationMeta('notes');
  @override
  late final GeneratedColumn<String> notes = GeneratedColumn<String>(
    'notes',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    saleId,
    productVariantId,
    productNameSnapshot,
    variantNameSnapshot,
    quantity,
    unitPriceMinor,
    subtotalMinor,
    discountMinor,
    totalMinor,
    notes,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'local_sale_items';
  @override
  VerificationContext validateIntegrity(
    Insertable<LocalSaleItemsTableData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    } else if (isInserting) {
      context.missing(_idMeta);
    }
    if (data.containsKey('sale_id')) {
      context.handle(
        _saleIdMeta,
        saleId.isAcceptableOrUnknown(data['sale_id']!, _saleIdMeta),
      );
    } else if (isInserting) {
      context.missing(_saleIdMeta);
    }
    if (data.containsKey('product_variant_id')) {
      context.handle(
        _productVariantIdMeta,
        productVariantId.isAcceptableOrUnknown(
          data['product_variant_id']!,
          _productVariantIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_productVariantIdMeta);
    }
    if (data.containsKey('product_name_snapshot')) {
      context.handle(
        _productNameSnapshotMeta,
        productNameSnapshot.isAcceptableOrUnknown(
          data['product_name_snapshot']!,
          _productNameSnapshotMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_productNameSnapshotMeta);
    }
    if (data.containsKey('variant_name_snapshot')) {
      context.handle(
        _variantNameSnapshotMeta,
        variantNameSnapshot.isAcceptableOrUnknown(
          data['variant_name_snapshot']!,
          _variantNameSnapshotMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_variantNameSnapshotMeta);
    }
    if (data.containsKey('quantity')) {
      context.handle(
        _quantityMeta,
        quantity.isAcceptableOrUnknown(data['quantity']!, _quantityMeta),
      );
    } else if (isInserting) {
      context.missing(_quantityMeta);
    }
    if (data.containsKey('unit_price_minor')) {
      context.handle(
        _unitPriceMinorMeta,
        unitPriceMinor.isAcceptableOrUnknown(
          data['unit_price_minor']!,
          _unitPriceMinorMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_unitPriceMinorMeta);
    }
    if (data.containsKey('subtotal_minor')) {
      context.handle(
        _subtotalMinorMeta,
        subtotalMinor.isAcceptableOrUnknown(
          data['subtotal_minor']!,
          _subtotalMinorMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_subtotalMinorMeta);
    }
    if (data.containsKey('discount_minor')) {
      context.handle(
        _discountMinorMeta,
        discountMinor.isAcceptableOrUnknown(
          data['discount_minor']!,
          _discountMinorMeta,
        ),
      );
    }
    if (data.containsKey('total_minor')) {
      context.handle(
        _totalMinorMeta,
        totalMinor.isAcceptableOrUnknown(data['total_minor']!, _totalMinorMeta),
      );
    } else if (isInserting) {
      context.missing(_totalMinorMeta);
    }
    if (data.containsKey('notes')) {
      context.handle(
        _notesMeta,
        notes.isAcceptableOrUnknown(data['notes']!, _notesMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  LocalSaleItemsTableData map(
    Map<String, dynamic> data, {
    String? tablePrefix,
  }) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return LocalSaleItemsTableData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}id'],
      )!,
      saleId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sale_id'],
      )!,
      productVariantId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_variant_id'],
      )!,
      productNameSnapshot: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}product_name_snapshot'],
      )!,
      variantNameSnapshot: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}variant_name_snapshot'],
      )!,
      quantity: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}quantity'],
      )!,
      unitPriceMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}unit_price_minor'],
      )!,
      subtotalMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}subtotal_minor'],
      )!,
      discountMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}discount_minor'],
      )!,
      totalMinor: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}total_minor'],
      )!,
      notes: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}notes'],
      ),
    );
  }

  @override
  $LocalSaleItemsTableTable createAlias(String alias) {
    return $LocalSaleItemsTableTable(attachedDatabase, alias);
  }
}

class LocalSaleItemsTableData extends DataClass
    implements Insertable<LocalSaleItemsTableData> {
  /// Client-generated UUIDv7 identifier
  final String id;
  final String saleId;
  final String productVariantId;
  final String productNameSnapshot;
  final String variantNameSnapshot;
  final double quantity;
  final int unitPriceMinor;
  final int subtotalMinor;
  final int discountMinor;
  final int totalMinor;
  final String? notes;
  const LocalSaleItemsTableData({
    required this.id,
    required this.saleId,
    required this.productVariantId,
    required this.productNameSnapshot,
    required this.variantNameSnapshot,
    required this.quantity,
    required this.unitPriceMinor,
    required this.subtotalMinor,
    required this.discountMinor,
    required this.totalMinor,
    this.notes,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<String>(id);
    map['sale_id'] = Variable<String>(saleId);
    map['product_variant_id'] = Variable<String>(productVariantId);
    map['product_name_snapshot'] = Variable<String>(productNameSnapshot);
    map['variant_name_snapshot'] = Variable<String>(variantNameSnapshot);
    map['quantity'] = Variable<double>(quantity);
    map['unit_price_minor'] = Variable<int>(unitPriceMinor);
    map['subtotal_minor'] = Variable<int>(subtotalMinor);
    map['discount_minor'] = Variable<int>(discountMinor);
    map['total_minor'] = Variable<int>(totalMinor);
    if (!nullToAbsent || notes != null) {
      map['notes'] = Variable<String>(notes);
    }
    return map;
  }

  LocalSaleItemsTableCompanion toCompanion(bool nullToAbsent) {
    return LocalSaleItemsTableCompanion(
      id: Value(id),
      saleId: Value(saleId),
      productVariantId: Value(productVariantId),
      productNameSnapshot: Value(productNameSnapshot),
      variantNameSnapshot: Value(variantNameSnapshot),
      quantity: Value(quantity),
      unitPriceMinor: Value(unitPriceMinor),
      subtotalMinor: Value(subtotalMinor),
      discountMinor: Value(discountMinor),
      totalMinor: Value(totalMinor),
      notes: notes == null && nullToAbsent
          ? const Value.absent()
          : Value(notes),
    );
  }

  factory LocalSaleItemsTableData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return LocalSaleItemsTableData(
      id: serializer.fromJson<String>(json['id']),
      saleId: serializer.fromJson<String>(json['saleId']),
      productVariantId: serializer.fromJson<String>(json['productVariantId']),
      productNameSnapshot: serializer.fromJson<String>(
        json['productNameSnapshot'],
      ),
      variantNameSnapshot: serializer.fromJson<String>(
        json['variantNameSnapshot'],
      ),
      quantity: serializer.fromJson<double>(json['quantity']),
      unitPriceMinor: serializer.fromJson<int>(json['unitPriceMinor']),
      subtotalMinor: serializer.fromJson<int>(json['subtotalMinor']),
      discountMinor: serializer.fromJson<int>(json['discountMinor']),
      totalMinor: serializer.fromJson<int>(json['totalMinor']),
      notes: serializer.fromJson<String?>(json['notes']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<String>(id),
      'saleId': serializer.toJson<String>(saleId),
      'productVariantId': serializer.toJson<String>(productVariantId),
      'productNameSnapshot': serializer.toJson<String>(productNameSnapshot),
      'variantNameSnapshot': serializer.toJson<String>(variantNameSnapshot),
      'quantity': serializer.toJson<double>(quantity),
      'unitPriceMinor': serializer.toJson<int>(unitPriceMinor),
      'subtotalMinor': serializer.toJson<int>(subtotalMinor),
      'discountMinor': serializer.toJson<int>(discountMinor),
      'totalMinor': serializer.toJson<int>(totalMinor),
      'notes': serializer.toJson<String?>(notes),
    };
  }

  LocalSaleItemsTableData copyWith({
    String? id,
    String? saleId,
    String? productVariantId,
    String? productNameSnapshot,
    String? variantNameSnapshot,
    double? quantity,
    int? unitPriceMinor,
    int? subtotalMinor,
    int? discountMinor,
    int? totalMinor,
    Value<String?> notes = const Value.absent(),
  }) => LocalSaleItemsTableData(
    id: id ?? this.id,
    saleId: saleId ?? this.saleId,
    productVariantId: productVariantId ?? this.productVariantId,
    productNameSnapshot: productNameSnapshot ?? this.productNameSnapshot,
    variantNameSnapshot: variantNameSnapshot ?? this.variantNameSnapshot,
    quantity: quantity ?? this.quantity,
    unitPriceMinor: unitPriceMinor ?? this.unitPriceMinor,
    subtotalMinor: subtotalMinor ?? this.subtotalMinor,
    discountMinor: discountMinor ?? this.discountMinor,
    totalMinor: totalMinor ?? this.totalMinor,
    notes: notes.present ? notes.value : this.notes,
  );
  LocalSaleItemsTableData copyWithCompanion(LocalSaleItemsTableCompanion data) {
    return LocalSaleItemsTableData(
      id: data.id.present ? data.id.value : this.id,
      saleId: data.saleId.present ? data.saleId.value : this.saleId,
      productVariantId: data.productVariantId.present
          ? data.productVariantId.value
          : this.productVariantId,
      productNameSnapshot: data.productNameSnapshot.present
          ? data.productNameSnapshot.value
          : this.productNameSnapshot,
      variantNameSnapshot: data.variantNameSnapshot.present
          ? data.variantNameSnapshot.value
          : this.variantNameSnapshot,
      quantity: data.quantity.present ? data.quantity.value : this.quantity,
      unitPriceMinor: data.unitPriceMinor.present
          ? data.unitPriceMinor.value
          : this.unitPriceMinor,
      subtotalMinor: data.subtotalMinor.present
          ? data.subtotalMinor.value
          : this.subtotalMinor,
      discountMinor: data.discountMinor.present
          ? data.discountMinor.value
          : this.discountMinor,
      totalMinor: data.totalMinor.present
          ? data.totalMinor.value
          : this.totalMinor,
      notes: data.notes.present ? data.notes.value : this.notes,
    );
  }

  @override
  String toString() {
    return (StringBuffer('LocalSaleItemsTableData(')
          ..write('id: $id, ')
          ..write('saleId: $saleId, ')
          ..write('productVariantId: $productVariantId, ')
          ..write('productNameSnapshot: $productNameSnapshot, ')
          ..write('variantNameSnapshot: $variantNameSnapshot, ')
          ..write('quantity: $quantity, ')
          ..write('unitPriceMinor: $unitPriceMinor, ')
          ..write('subtotalMinor: $subtotalMinor, ')
          ..write('discountMinor: $discountMinor, ')
          ..write('totalMinor: $totalMinor, ')
          ..write('notes: $notes')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    saleId,
    productVariantId,
    productNameSnapshot,
    variantNameSnapshot,
    quantity,
    unitPriceMinor,
    subtotalMinor,
    discountMinor,
    totalMinor,
    notes,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is LocalSaleItemsTableData &&
          other.id == this.id &&
          other.saleId == this.saleId &&
          other.productVariantId == this.productVariantId &&
          other.productNameSnapshot == this.productNameSnapshot &&
          other.variantNameSnapshot == this.variantNameSnapshot &&
          other.quantity == this.quantity &&
          other.unitPriceMinor == this.unitPriceMinor &&
          other.subtotalMinor == this.subtotalMinor &&
          other.discountMinor == this.discountMinor &&
          other.totalMinor == this.totalMinor &&
          other.notes == this.notes);
}

class LocalSaleItemsTableCompanion
    extends UpdateCompanion<LocalSaleItemsTableData> {
  final Value<String> id;
  final Value<String> saleId;
  final Value<String> productVariantId;
  final Value<String> productNameSnapshot;
  final Value<String> variantNameSnapshot;
  final Value<double> quantity;
  final Value<int> unitPriceMinor;
  final Value<int> subtotalMinor;
  final Value<int> discountMinor;
  final Value<int> totalMinor;
  final Value<String?> notes;
  final Value<int> rowid;
  const LocalSaleItemsTableCompanion({
    this.id = const Value.absent(),
    this.saleId = const Value.absent(),
    this.productVariantId = const Value.absent(),
    this.productNameSnapshot = const Value.absent(),
    this.variantNameSnapshot = const Value.absent(),
    this.quantity = const Value.absent(),
    this.unitPriceMinor = const Value.absent(),
    this.subtotalMinor = const Value.absent(),
    this.discountMinor = const Value.absent(),
    this.totalMinor = const Value.absent(),
    this.notes = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  LocalSaleItemsTableCompanion.insert({
    required String id,
    required String saleId,
    required String productVariantId,
    required String productNameSnapshot,
    required String variantNameSnapshot,
    required double quantity,
    required int unitPriceMinor,
    required int subtotalMinor,
    this.discountMinor = const Value.absent(),
    required int totalMinor,
    this.notes = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : id = Value(id),
       saleId = Value(saleId),
       productVariantId = Value(productVariantId),
       productNameSnapshot = Value(productNameSnapshot),
       variantNameSnapshot = Value(variantNameSnapshot),
       quantity = Value(quantity),
       unitPriceMinor = Value(unitPriceMinor),
       subtotalMinor = Value(subtotalMinor),
       totalMinor = Value(totalMinor);
  static Insertable<LocalSaleItemsTableData> custom({
    Expression<String>? id,
    Expression<String>? saleId,
    Expression<String>? productVariantId,
    Expression<String>? productNameSnapshot,
    Expression<String>? variantNameSnapshot,
    Expression<double>? quantity,
    Expression<int>? unitPriceMinor,
    Expression<int>? subtotalMinor,
    Expression<int>? discountMinor,
    Expression<int>? totalMinor,
    Expression<String>? notes,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (saleId != null) 'sale_id': saleId,
      if (productVariantId != null) 'product_variant_id': productVariantId,
      if (productNameSnapshot != null)
        'product_name_snapshot': productNameSnapshot,
      if (variantNameSnapshot != null)
        'variant_name_snapshot': variantNameSnapshot,
      if (quantity != null) 'quantity': quantity,
      if (unitPriceMinor != null) 'unit_price_minor': unitPriceMinor,
      if (subtotalMinor != null) 'subtotal_minor': subtotalMinor,
      if (discountMinor != null) 'discount_minor': discountMinor,
      if (totalMinor != null) 'total_minor': totalMinor,
      if (notes != null) 'notes': notes,
      if (rowid != null) 'rowid': rowid,
    });
  }

  LocalSaleItemsTableCompanion copyWith({
    Value<String>? id,
    Value<String>? saleId,
    Value<String>? productVariantId,
    Value<String>? productNameSnapshot,
    Value<String>? variantNameSnapshot,
    Value<double>? quantity,
    Value<int>? unitPriceMinor,
    Value<int>? subtotalMinor,
    Value<int>? discountMinor,
    Value<int>? totalMinor,
    Value<String?>? notes,
    Value<int>? rowid,
  }) {
    return LocalSaleItemsTableCompanion(
      id: id ?? this.id,
      saleId: saleId ?? this.saleId,
      productVariantId: productVariantId ?? this.productVariantId,
      productNameSnapshot: productNameSnapshot ?? this.productNameSnapshot,
      variantNameSnapshot: variantNameSnapshot ?? this.variantNameSnapshot,
      quantity: quantity ?? this.quantity,
      unitPriceMinor: unitPriceMinor ?? this.unitPriceMinor,
      subtotalMinor: subtotalMinor ?? this.subtotalMinor,
      discountMinor: discountMinor ?? this.discountMinor,
      totalMinor: totalMinor ?? this.totalMinor,
      notes: notes ?? this.notes,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<String>(id.value);
    }
    if (saleId.present) {
      map['sale_id'] = Variable<String>(saleId.value);
    }
    if (productVariantId.present) {
      map['product_variant_id'] = Variable<String>(productVariantId.value);
    }
    if (productNameSnapshot.present) {
      map['product_name_snapshot'] = Variable<String>(
        productNameSnapshot.value,
      );
    }
    if (variantNameSnapshot.present) {
      map['variant_name_snapshot'] = Variable<String>(
        variantNameSnapshot.value,
      );
    }
    if (quantity.present) {
      map['quantity'] = Variable<double>(quantity.value);
    }
    if (unitPriceMinor.present) {
      map['unit_price_minor'] = Variable<int>(unitPriceMinor.value);
    }
    if (subtotalMinor.present) {
      map['subtotal_minor'] = Variable<int>(subtotalMinor.value);
    }
    if (discountMinor.present) {
      map['discount_minor'] = Variable<int>(discountMinor.value);
    }
    if (totalMinor.present) {
      map['total_minor'] = Variable<int>(totalMinor.value);
    }
    if (notes.present) {
      map['notes'] = Variable<String>(notes.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('LocalSaleItemsTableCompanion(')
          ..write('id: $id, ')
          ..write('saleId: $saleId, ')
          ..write('productVariantId: $productVariantId, ')
          ..write('productNameSnapshot: $productNameSnapshot, ')
          ..write('variantNameSnapshot: $variantNameSnapshot, ')
          ..write('quantity: $quantity, ')
          ..write('unitPriceMinor: $unitPriceMinor, ')
          ..write('subtotalMinor: $subtotalMinor, ')
          ..write('discountMinor: $discountMinor, ')
          ..write('totalMinor: $totalMinor, ')
          ..write('notes: $notes, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

abstract class _$AppDatabase extends GeneratedDatabase {
  _$AppDatabase(QueryExecutor e) : super(e);
  $AppDatabaseManager get managers => $AppDatabaseManager(this);
  late final $SyncOutboxTableTable syncOutboxTable = $SyncOutboxTableTable(
    this,
  );
  late final $SessionStateTableTable sessionStateTable =
      $SessionStateTableTable(this);
  late final $SyncMetadataTableTable syncMetadataTable =
      $SyncMetadataTableTable(this);
  late final $LocalProductSnapshotsTableTable localProductSnapshotsTable =
      $LocalProductSnapshotsTableTable(this);
  late final $LocalProductVariantSnapshotsTableTable
  localProductVariantSnapshotsTable = $LocalProductVariantSnapshotsTableTable(
    this,
  );
  late final $LocalPriceSnapshotsTableTable localPriceSnapshotsTable =
      $LocalPriceSnapshotsTableTable(this);
  late final $LocalInventorySnapshotsTableTable localInventorySnapshotsTable =
      $LocalInventorySnapshotsTableTable(this);
  late final $LocalCustomerSnapshotsTableTable localCustomerSnapshotsTable =
      $LocalCustomerSnapshotsTableTable(this);
  late final $LocalCartsTableTable localCartsTable = $LocalCartsTableTable(
    this,
  );
  late final $LocalCartItemsTableTable localCartItemsTable =
      $LocalCartItemsTableTable(this);
  late final $LocalSalesTableTable localSalesTable = $LocalSalesTableTable(
    this,
  );
  late final $LocalSaleItemsTableTable localSaleItemsTable =
      $LocalSaleItemsTableTable(this);
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [
    syncOutboxTable,
    sessionStateTable,
    syncMetadataTable,
    localProductSnapshotsTable,
    localProductVariantSnapshotsTable,
    localPriceSnapshotsTable,
    localInventorySnapshotsTable,
    localCustomerSnapshotsTable,
    localCartsTable,
    localCartItemsTable,
    localSalesTable,
    localSaleItemsTable,
  ];
}

typedef $$SyncOutboxTableTableCreateCompanionBuilder =
    SyncOutboxTableCompanion Function({
      required String id,
      required String commandType,
      required String aggregateType,
      required String aggregateId,
      required String payload,
      required DateTime createdAt,
      Value<int> attemptCount,
      Value<DateTime?> nextAttemptAt,
      Value<String> state,
      Value<String?> lastError,
      Value<String?> causationId,
      Value<int> sequenceNumber,
      Value<int> rowid,
    });
typedef $$SyncOutboxTableTableUpdateCompanionBuilder =
    SyncOutboxTableCompanion Function({
      Value<String> id,
      Value<String> commandType,
      Value<String> aggregateType,
      Value<String> aggregateId,
      Value<String> payload,
      Value<DateTime> createdAt,
      Value<int> attemptCount,
      Value<DateTime?> nextAttemptAt,
      Value<String> state,
      Value<String?> lastError,
      Value<String?> causationId,
      Value<int> sequenceNumber,
      Value<int> rowid,
    });

class $$SyncOutboxTableTableFilterComposer
    extends Composer<_$AppDatabase, $SyncOutboxTableTable> {
  $$SyncOutboxTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get commandType => $composableBuilder(
    column: $table.commandType,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get aggregateType => $composableBuilder(
    column: $table.aggregateType,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get aggregateId => $composableBuilder(
    column: $table.aggregateId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get payload => $composableBuilder(
    column: $table.payload,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get attemptCount => $composableBuilder(
    column: $table.attemptCount,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get nextAttemptAt => $composableBuilder(
    column: $table.nextAttemptAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get state => $composableBuilder(
    column: $table.state,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get lastError => $composableBuilder(
    column: $table.lastError,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get causationId => $composableBuilder(
    column: $table.causationId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get sequenceNumber => $composableBuilder(
    column: $table.sequenceNumber,
    builder: (column) => ColumnFilters(column),
  );
}

class $$SyncOutboxTableTableOrderingComposer
    extends Composer<_$AppDatabase, $SyncOutboxTableTable> {
  $$SyncOutboxTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get commandType => $composableBuilder(
    column: $table.commandType,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get aggregateType => $composableBuilder(
    column: $table.aggregateType,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get aggregateId => $composableBuilder(
    column: $table.aggregateId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get payload => $composableBuilder(
    column: $table.payload,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get attemptCount => $composableBuilder(
    column: $table.attemptCount,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get nextAttemptAt => $composableBuilder(
    column: $table.nextAttemptAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get state => $composableBuilder(
    column: $table.state,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get lastError => $composableBuilder(
    column: $table.lastError,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get causationId => $composableBuilder(
    column: $table.causationId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get sequenceNumber => $composableBuilder(
    column: $table.sequenceNumber,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$SyncOutboxTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $SyncOutboxTableTable> {
  $$SyncOutboxTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get commandType => $composableBuilder(
    column: $table.commandType,
    builder: (column) => column,
  );

  GeneratedColumn<String> get aggregateType => $composableBuilder(
    column: $table.aggregateType,
    builder: (column) => column,
  );

  GeneratedColumn<String> get aggregateId => $composableBuilder(
    column: $table.aggregateId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get payload =>
      $composableBuilder(column: $table.payload, builder: (column) => column);

  GeneratedColumn<DateTime> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);

  GeneratedColumn<int> get attemptCount => $composableBuilder(
    column: $table.attemptCount,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get nextAttemptAt => $composableBuilder(
    column: $table.nextAttemptAt,
    builder: (column) => column,
  );

  GeneratedColumn<String> get state =>
      $composableBuilder(column: $table.state, builder: (column) => column);

  GeneratedColumn<String> get lastError =>
      $composableBuilder(column: $table.lastError, builder: (column) => column);

  GeneratedColumn<String> get causationId => $composableBuilder(
    column: $table.causationId,
    builder: (column) => column,
  );

  GeneratedColumn<int> get sequenceNumber => $composableBuilder(
    column: $table.sequenceNumber,
    builder: (column) => column,
  );
}

class $$SyncOutboxTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $SyncOutboxTableTable,
          SyncOutboxTableData,
          $$SyncOutboxTableTableFilterComposer,
          $$SyncOutboxTableTableOrderingComposer,
          $$SyncOutboxTableTableAnnotationComposer,
          $$SyncOutboxTableTableCreateCompanionBuilder,
          $$SyncOutboxTableTableUpdateCompanionBuilder,
          (
            SyncOutboxTableData,
            BaseReferences<
              _$AppDatabase,
              $SyncOutboxTableTable,
              SyncOutboxTableData
            >,
          ),
          SyncOutboxTableData,
          PrefetchHooks Function()
        > {
  $$SyncOutboxTableTableTableManager(
    _$AppDatabase db,
    $SyncOutboxTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$SyncOutboxTableTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$SyncOutboxTableTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$SyncOutboxTableTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> commandType = const Value.absent(),
                Value<String> aggregateType = const Value.absent(),
                Value<String> aggregateId = const Value.absent(),
                Value<String> payload = const Value.absent(),
                Value<DateTime> createdAt = const Value.absent(),
                Value<int> attemptCount = const Value.absent(),
                Value<DateTime?> nextAttemptAt = const Value.absent(),
                Value<String> state = const Value.absent(),
                Value<String?> lastError = const Value.absent(),
                Value<String?> causationId = const Value.absent(),
                Value<int> sequenceNumber = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => SyncOutboxTableCompanion(
                id: id,
                commandType: commandType,
                aggregateType: aggregateType,
                aggregateId: aggregateId,
                payload: payload,
                createdAt: createdAt,
                attemptCount: attemptCount,
                nextAttemptAt: nextAttemptAt,
                state: state,
                lastError: lastError,
                causationId: causationId,
                sequenceNumber: sequenceNumber,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String commandType,
                required String aggregateType,
                required String aggregateId,
                required String payload,
                required DateTime createdAt,
                Value<int> attemptCount = const Value.absent(),
                Value<DateTime?> nextAttemptAt = const Value.absent(),
                Value<String> state = const Value.absent(),
                Value<String?> lastError = const Value.absent(),
                Value<String?> causationId = const Value.absent(),
                Value<int> sequenceNumber = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => SyncOutboxTableCompanion.insert(
                id: id,
                commandType: commandType,
                aggregateType: aggregateType,
                aggregateId: aggregateId,
                payload: payload,
                createdAt: createdAt,
                attemptCount: attemptCount,
                nextAttemptAt: nextAttemptAt,
                state: state,
                lastError: lastError,
                causationId: causationId,
                sequenceNumber: sequenceNumber,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$SyncOutboxTableTable, SyncOutboxTableData>(
                    table,
                  ),
                  BaseReferences<
                    _$AppDatabase,
                    $SyncOutboxTableTable,
                    SyncOutboxTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$SyncOutboxTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $SyncOutboxTableTable,
      SyncOutboxTableData,
      $$SyncOutboxTableTableFilterComposer,
      $$SyncOutboxTableTableOrderingComposer,
      $$SyncOutboxTableTableAnnotationComposer,
      $$SyncOutboxTableTableCreateCompanionBuilder,
      $$SyncOutboxTableTableUpdateCompanionBuilder,
      (
        SyncOutboxTableData,
        BaseReferences<
          _$AppDatabase,
          $SyncOutboxTableTable,
          SyncOutboxTableData
        >,
      ),
      SyncOutboxTableData,
      PrefetchHooks Function()
    >;
typedef $$SessionStateTableTableCreateCompanionBuilder =
    SessionStateTableCompanion Function({
      required String key,
      required String value,
      required DateTime updatedAt,
      Value<int> rowid,
    });
typedef $$SessionStateTableTableUpdateCompanionBuilder =
    SessionStateTableCompanion Function({
      Value<String> key,
      Value<String> value,
      Value<DateTime> updatedAt,
      Value<int> rowid,
    });

class $$SessionStateTableTableFilterComposer
    extends Composer<_$AppDatabase, $SessionStateTableTable> {
  $$SessionStateTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$SessionStateTableTableOrderingComposer
    extends Composer<_$AppDatabase, $SessionStateTableTable> {
  $$SessionStateTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get value => $composableBuilder(
    column: $table.value,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$SessionStateTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $SessionStateTableTable> {
  $$SessionStateTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get key =>
      $composableBuilder(column: $table.key, builder: (column) => column);

  GeneratedColumn<String> get value =>
      $composableBuilder(column: $table.value, builder: (column) => column);

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$SessionStateTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $SessionStateTableTable,
          SessionStateTableData,
          $$SessionStateTableTableFilterComposer,
          $$SessionStateTableTableOrderingComposer,
          $$SessionStateTableTableAnnotationComposer,
          $$SessionStateTableTableCreateCompanionBuilder,
          $$SessionStateTableTableUpdateCompanionBuilder,
          (
            SessionStateTableData,
            BaseReferences<
              _$AppDatabase,
              $SessionStateTableTable,
              SessionStateTableData
            >,
          ),
          SessionStateTableData,
          PrefetchHooks Function()
        > {
  $$SessionStateTableTableTableManager(
    _$AppDatabase db,
    $SessionStateTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$SessionStateTableTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$SessionStateTableTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$SessionStateTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> key = const Value.absent(),
                Value<String> value = const Value.absent(),
                Value<DateTime> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => SessionStateTableCompanion(
                key: key,
                value: value,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String key,
                required String value,
                required DateTime updatedAt,
                Value<int> rowid = const Value.absent(),
              }) => SessionStateTableCompanion.insert(
                key: key,
                value: value,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$SessionStateTableTable, SessionStateTableData>(
                    table,
                  ),
                  BaseReferences<
                    _$AppDatabase,
                    $SessionStateTableTable,
                    SessionStateTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$SessionStateTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $SessionStateTableTable,
      SessionStateTableData,
      $$SessionStateTableTableFilterComposer,
      $$SessionStateTableTableOrderingComposer,
      $$SessionStateTableTableAnnotationComposer,
      $$SessionStateTableTableCreateCompanionBuilder,
      $$SessionStateTableTableUpdateCompanionBuilder,
      (
        SessionStateTableData,
        BaseReferences<
          _$AppDatabase,
          $SessionStateTableTable,
          SessionStateTableData
        >,
      ),
      SessionStateTableData,
      PrefetchHooks Function()
    >;
typedef $$SyncMetadataTableTableCreateCompanionBuilder =
    SyncMetadataTableCompanion Function({
      required String key,
      Value<DateTime?> lastSyncedAt,
      Value<String?> cursor,
      Value<int> rowid,
    });
typedef $$SyncMetadataTableTableUpdateCompanionBuilder =
    SyncMetadataTableCompanion Function({
      Value<String> key,
      Value<DateTime?> lastSyncedAt,
      Value<String?> cursor,
      Value<int> rowid,
    });

class $$SyncMetadataTableTableFilterComposer
    extends Composer<_$AppDatabase, $SyncMetadataTableTable> {
  $$SyncMetadataTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get lastSyncedAt => $composableBuilder(
    column: $table.lastSyncedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get cursor => $composableBuilder(
    column: $table.cursor,
    builder: (column) => ColumnFilters(column),
  );
}

class $$SyncMetadataTableTableOrderingComposer
    extends Composer<_$AppDatabase, $SyncMetadataTableTable> {
  $$SyncMetadataTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get key => $composableBuilder(
    column: $table.key,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get lastSyncedAt => $composableBuilder(
    column: $table.lastSyncedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get cursor => $composableBuilder(
    column: $table.cursor,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$SyncMetadataTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $SyncMetadataTableTable> {
  $$SyncMetadataTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get key =>
      $composableBuilder(column: $table.key, builder: (column) => column);

  GeneratedColumn<DateTime> get lastSyncedAt => $composableBuilder(
    column: $table.lastSyncedAt,
    builder: (column) => column,
  );

  GeneratedColumn<String> get cursor =>
      $composableBuilder(column: $table.cursor, builder: (column) => column);
}

class $$SyncMetadataTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $SyncMetadataTableTable,
          SyncMetadataTableData,
          $$SyncMetadataTableTableFilterComposer,
          $$SyncMetadataTableTableOrderingComposer,
          $$SyncMetadataTableTableAnnotationComposer,
          $$SyncMetadataTableTableCreateCompanionBuilder,
          $$SyncMetadataTableTableUpdateCompanionBuilder,
          (
            SyncMetadataTableData,
            BaseReferences<
              _$AppDatabase,
              $SyncMetadataTableTable,
              SyncMetadataTableData
            >,
          ),
          SyncMetadataTableData,
          PrefetchHooks Function()
        > {
  $$SyncMetadataTableTableTableManager(
    _$AppDatabase db,
    $SyncMetadataTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$SyncMetadataTableTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$SyncMetadataTableTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$SyncMetadataTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> key = const Value.absent(),
                Value<DateTime?> lastSyncedAt = const Value.absent(),
                Value<String?> cursor = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => SyncMetadataTableCompanion(
                key: key,
                lastSyncedAt: lastSyncedAt,
                cursor: cursor,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String key,
                Value<DateTime?> lastSyncedAt = const Value.absent(),
                Value<String?> cursor = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => SyncMetadataTableCompanion.insert(
                key: key,
                lastSyncedAt: lastSyncedAt,
                cursor: cursor,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$SyncMetadataTableTable, SyncMetadataTableData>(
                    table,
                  ),
                  BaseReferences<
                    _$AppDatabase,
                    $SyncMetadataTableTable,
                    SyncMetadataTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$SyncMetadataTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $SyncMetadataTableTable,
      SyncMetadataTableData,
      $$SyncMetadataTableTableFilterComposer,
      $$SyncMetadataTableTableOrderingComposer,
      $$SyncMetadataTableTableAnnotationComposer,
      $$SyncMetadataTableTableCreateCompanionBuilder,
      $$SyncMetadataTableTableUpdateCompanionBuilder,
      (
        SyncMetadataTableData,
        BaseReferences<
          _$AppDatabase,
          $SyncMetadataTableTable,
          SyncMetadataTableData
        >,
      ),
      SyncMetadataTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalProductSnapshotsTableTableCreateCompanionBuilder =
    LocalProductSnapshotsTableCompanion Function({
      required String id,
      required String tenantId,
      Value<String?> categoryId,
      Value<String?> categoryName,
      required String name,
      Value<String?> description,
      Value<String> status,
      required DateTime updatedAt,
      Value<int> rowid,
    });
typedef $$LocalProductSnapshotsTableTableUpdateCompanionBuilder =
    LocalProductSnapshotsTableCompanion Function({
      Value<String> id,
      Value<String> tenantId,
      Value<String?> categoryId,
      Value<String?> categoryName,
      Value<String> name,
      Value<String?> description,
      Value<String> status,
      Value<DateTime> updatedAt,
      Value<int> rowid,
    });

class $$LocalProductSnapshotsTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalProductSnapshotsTableTable> {
  $$LocalProductSnapshotsTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get tenantId => $composableBuilder(
    column: $table.tenantId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get categoryId => $composableBuilder(
    column: $table.categoryId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get categoryName => $composableBuilder(
    column: $table.categoryName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalProductSnapshotsTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalProductSnapshotsTableTable> {
  $$LocalProductSnapshotsTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get tenantId => $composableBuilder(
    column: $table.tenantId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get categoryId => $composableBuilder(
    column: $table.categoryId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get categoryName => $composableBuilder(
    column: $table.categoryName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalProductSnapshotsTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalProductSnapshotsTableTable> {
  $$LocalProductSnapshotsTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get tenantId =>
      $composableBuilder(column: $table.tenantId, builder: (column) => column);

  GeneratedColumn<String> get categoryId => $composableBuilder(
    column: $table.categoryId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get categoryName => $composableBuilder(
    column: $table.categoryName,
    builder: (column) => column,
  );

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get description => $composableBuilder(
    column: $table.description,
    builder: (column) => column,
  );

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$LocalProductSnapshotsTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalProductSnapshotsTableTable,
          LocalProductSnapshotsTableData,
          $$LocalProductSnapshotsTableTableFilterComposer,
          $$LocalProductSnapshotsTableTableOrderingComposer,
          $$LocalProductSnapshotsTableTableAnnotationComposer,
          $$LocalProductSnapshotsTableTableCreateCompanionBuilder,
          $$LocalProductSnapshotsTableTableUpdateCompanionBuilder,
          (
            LocalProductSnapshotsTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalProductSnapshotsTableTable,
              LocalProductSnapshotsTableData
            >,
          ),
          LocalProductSnapshotsTableData,
          PrefetchHooks Function()
        > {
  $$LocalProductSnapshotsTableTableTableManager(
    _$AppDatabase db,
    $LocalProductSnapshotsTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalProductSnapshotsTableTableFilterComposer(
                $db: db,
                $table: table,
              ),
          createOrderingComposer: () =>
              $$LocalProductSnapshotsTableTableOrderingComposer(
                $db: db,
                $table: table,
              ),
          createComputedFieldComposer: () =>
              $$LocalProductSnapshotsTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> tenantId = const Value.absent(),
                Value<String?> categoryId = const Value.absent(),
                Value<String?> categoryName = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> description = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<DateTime> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalProductSnapshotsTableCompanion(
                id: id,
                tenantId: tenantId,
                categoryId: categoryId,
                categoryName: categoryName,
                name: name,
                description: description,
                status: status,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String tenantId,
                Value<String?> categoryId = const Value.absent(),
                Value<String?> categoryName = const Value.absent(),
                required String name,
                Value<String?> description = const Value.absent(),
                Value<String> status = const Value.absent(),
                required DateTime updatedAt,
                Value<int> rowid = const Value.absent(),
              }) => LocalProductSnapshotsTableCompanion.insert(
                id: id,
                tenantId: tenantId,
                categoryId: categoryId,
                categoryName: categoryName,
                name: name,
                description: description,
                status: status,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<
                    $LocalProductSnapshotsTableTable,
                    LocalProductSnapshotsTableData
                  >(table),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalProductSnapshotsTableTable,
                    LocalProductSnapshotsTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalProductSnapshotsTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalProductSnapshotsTableTable,
      LocalProductSnapshotsTableData,
      $$LocalProductSnapshotsTableTableFilterComposer,
      $$LocalProductSnapshotsTableTableOrderingComposer,
      $$LocalProductSnapshotsTableTableAnnotationComposer,
      $$LocalProductSnapshotsTableTableCreateCompanionBuilder,
      $$LocalProductSnapshotsTableTableUpdateCompanionBuilder,
      (
        LocalProductSnapshotsTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalProductSnapshotsTableTable,
          LocalProductSnapshotsTableData
        >,
      ),
      LocalProductSnapshotsTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalProductVariantSnapshotsTableTableCreateCompanionBuilder =
    LocalProductVariantSnapshotsTableCompanion Function({
      required String id,
      required String productId,
      required String name,
      Value<String?> sku,
      Value<bool> isDefault,
      Value<String> status,
      Value<int> rowid,
    });
typedef $$LocalProductVariantSnapshotsTableTableUpdateCompanionBuilder =
    LocalProductVariantSnapshotsTableCompanion Function({
      Value<String> id,
      Value<String> productId,
      Value<String> name,
      Value<String?> sku,
      Value<bool> isDefault,
      Value<String> status,
      Value<int> rowid,
    });

class $$LocalProductVariantSnapshotsTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalProductVariantSnapshotsTableTable> {
  $$LocalProductVariantSnapshotsTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get productId => $composableBuilder(
    column: $table.productId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get sku => $composableBuilder(
    column: $table.sku,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get isDefault => $composableBuilder(
    column: $table.isDefault,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalProductVariantSnapshotsTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalProductVariantSnapshotsTableTable> {
  $$LocalProductVariantSnapshotsTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get productId => $composableBuilder(
    column: $table.productId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get sku => $composableBuilder(
    column: $table.sku,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get isDefault => $composableBuilder(
    column: $table.isDefault,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalProductVariantSnapshotsTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalProductVariantSnapshotsTableTable> {
  $$LocalProductVariantSnapshotsTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get productId =>
      $composableBuilder(column: $table.productId, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get sku =>
      $composableBuilder(column: $table.sku, builder: (column) => column);

  GeneratedColumn<bool> get isDefault =>
      $composableBuilder(column: $table.isDefault, builder: (column) => column);

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);
}

class $$LocalProductVariantSnapshotsTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalProductVariantSnapshotsTableTable,
          LocalProductVariantSnapshotsTableData,
          $$LocalProductVariantSnapshotsTableTableFilterComposer,
          $$LocalProductVariantSnapshotsTableTableOrderingComposer,
          $$LocalProductVariantSnapshotsTableTableAnnotationComposer,
          $$LocalProductVariantSnapshotsTableTableCreateCompanionBuilder,
          $$LocalProductVariantSnapshotsTableTableUpdateCompanionBuilder,
          (
            LocalProductVariantSnapshotsTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalProductVariantSnapshotsTableTable,
              LocalProductVariantSnapshotsTableData
            >,
          ),
          LocalProductVariantSnapshotsTableData,
          PrefetchHooks Function()
        > {
  $$LocalProductVariantSnapshotsTableTableTableManager(
    _$AppDatabase db,
    $LocalProductVariantSnapshotsTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalProductVariantSnapshotsTableTableFilterComposer(
                $db: db,
                $table: table,
              ),
          createOrderingComposer: () =>
              $$LocalProductVariantSnapshotsTableTableOrderingComposer(
                $db: db,
                $table: table,
              ),
          createComputedFieldComposer: () =>
              $$LocalProductVariantSnapshotsTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> productId = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> sku = const Value.absent(),
                Value<bool> isDefault = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalProductVariantSnapshotsTableCompanion(
                id: id,
                productId: productId,
                name: name,
                sku: sku,
                isDefault: isDefault,
                status: status,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String productId,
                required String name,
                Value<String?> sku = const Value.absent(),
                Value<bool> isDefault = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalProductVariantSnapshotsTableCompanion.insert(
                id: id,
                productId: productId,
                name: name,
                sku: sku,
                isDefault: isDefault,
                status: status,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<
                    $LocalProductVariantSnapshotsTableTable,
                    LocalProductVariantSnapshotsTableData
                  >(table),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalProductVariantSnapshotsTableTable,
                    LocalProductVariantSnapshotsTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalProductVariantSnapshotsTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalProductVariantSnapshotsTableTable,
      LocalProductVariantSnapshotsTableData,
      $$LocalProductVariantSnapshotsTableTableFilterComposer,
      $$LocalProductVariantSnapshotsTableTableOrderingComposer,
      $$LocalProductVariantSnapshotsTableTableAnnotationComposer,
      $$LocalProductVariantSnapshotsTableTableCreateCompanionBuilder,
      $$LocalProductVariantSnapshotsTableTableUpdateCompanionBuilder,
      (
        LocalProductVariantSnapshotsTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalProductVariantSnapshotsTableTable,
          LocalProductVariantSnapshotsTableData
        >,
      ),
      LocalProductVariantSnapshotsTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalPriceSnapshotsTableTableCreateCompanionBuilder =
    LocalPriceSnapshotsTableCompanion Function({
      required String id,
      required String variantId,
      Value<String?> outletId,
      required int amountMinor,
      Value<String> currency,
      Value<int> rowid,
    });
typedef $$LocalPriceSnapshotsTableTableUpdateCompanionBuilder =
    LocalPriceSnapshotsTableCompanion Function({
      Value<String> id,
      Value<String> variantId,
      Value<String?> outletId,
      Value<int> amountMinor,
      Value<String> currency,
      Value<int> rowid,
    });

class $$LocalPriceSnapshotsTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalPriceSnapshotsTableTable> {
  $$LocalPriceSnapshotsTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get variantId => $composableBuilder(
    column: $table.variantId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get outletId => $composableBuilder(
    column: $table.outletId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get amountMinor => $composableBuilder(
    column: $table.amountMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get currency => $composableBuilder(
    column: $table.currency,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalPriceSnapshotsTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalPriceSnapshotsTableTable> {
  $$LocalPriceSnapshotsTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get variantId => $composableBuilder(
    column: $table.variantId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get outletId => $composableBuilder(
    column: $table.outletId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get amountMinor => $composableBuilder(
    column: $table.amountMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get currency => $composableBuilder(
    column: $table.currency,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalPriceSnapshotsTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalPriceSnapshotsTableTable> {
  $$LocalPriceSnapshotsTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get variantId =>
      $composableBuilder(column: $table.variantId, builder: (column) => column);

  GeneratedColumn<String> get outletId =>
      $composableBuilder(column: $table.outletId, builder: (column) => column);

  GeneratedColumn<int> get amountMinor => $composableBuilder(
    column: $table.amountMinor,
    builder: (column) => column,
  );

  GeneratedColumn<String> get currency =>
      $composableBuilder(column: $table.currency, builder: (column) => column);
}

class $$LocalPriceSnapshotsTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalPriceSnapshotsTableTable,
          LocalPriceSnapshotsTableData,
          $$LocalPriceSnapshotsTableTableFilterComposer,
          $$LocalPriceSnapshotsTableTableOrderingComposer,
          $$LocalPriceSnapshotsTableTableAnnotationComposer,
          $$LocalPriceSnapshotsTableTableCreateCompanionBuilder,
          $$LocalPriceSnapshotsTableTableUpdateCompanionBuilder,
          (
            LocalPriceSnapshotsTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalPriceSnapshotsTableTable,
              LocalPriceSnapshotsTableData
            >,
          ),
          LocalPriceSnapshotsTableData,
          PrefetchHooks Function()
        > {
  $$LocalPriceSnapshotsTableTableTableManager(
    _$AppDatabase db,
    $LocalPriceSnapshotsTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalPriceSnapshotsTableTableFilterComposer(
                $db: db,
                $table: table,
              ),
          createOrderingComposer: () =>
              $$LocalPriceSnapshotsTableTableOrderingComposer(
                $db: db,
                $table: table,
              ),
          createComputedFieldComposer: () =>
              $$LocalPriceSnapshotsTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> variantId = const Value.absent(),
                Value<String?> outletId = const Value.absent(),
                Value<int> amountMinor = const Value.absent(),
                Value<String> currency = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalPriceSnapshotsTableCompanion(
                id: id,
                variantId: variantId,
                outletId: outletId,
                amountMinor: amountMinor,
                currency: currency,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String variantId,
                Value<String?> outletId = const Value.absent(),
                required int amountMinor,
                Value<String> currency = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalPriceSnapshotsTableCompanion.insert(
                id: id,
                variantId: variantId,
                outletId: outletId,
                amountMinor: amountMinor,
                currency: currency,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<
                    $LocalPriceSnapshotsTableTable,
                    LocalPriceSnapshotsTableData
                  >(table),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalPriceSnapshotsTableTable,
                    LocalPriceSnapshotsTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalPriceSnapshotsTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalPriceSnapshotsTableTable,
      LocalPriceSnapshotsTableData,
      $$LocalPriceSnapshotsTableTableFilterComposer,
      $$LocalPriceSnapshotsTableTableOrderingComposer,
      $$LocalPriceSnapshotsTableTableAnnotationComposer,
      $$LocalPriceSnapshotsTableTableCreateCompanionBuilder,
      $$LocalPriceSnapshotsTableTableUpdateCompanionBuilder,
      (
        LocalPriceSnapshotsTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalPriceSnapshotsTableTable,
          LocalPriceSnapshotsTableData
        >,
      ),
      LocalPriceSnapshotsTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalInventorySnapshotsTableTableCreateCompanionBuilder =
    LocalInventorySnapshotsTableCompanion Function({
      required String id,
      required String inventoryItemId,
      required String locationId,
      required String outletId,
      required String name,
      Value<String?> sku,
      Value<double> onHand,
      Value<double> reserved,
      Value<String> unit,
      Value<int> rowid,
    });
typedef $$LocalInventorySnapshotsTableTableUpdateCompanionBuilder =
    LocalInventorySnapshotsTableCompanion Function({
      Value<String> id,
      Value<String> inventoryItemId,
      Value<String> locationId,
      Value<String> outletId,
      Value<String> name,
      Value<String?> sku,
      Value<double> onHand,
      Value<double> reserved,
      Value<String> unit,
      Value<int> rowid,
    });

class $$LocalInventorySnapshotsTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalInventorySnapshotsTableTable> {
  $$LocalInventorySnapshotsTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get inventoryItemId => $composableBuilder(
    column: $table.inventoryItemId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get outletId => $composableBuilder(
    column: $table.outletId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get sku => $composableBuilder(
    column: $table.sku,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get onHand => $composableBuilder(
    column: $table.onHand,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get reserved => $composableBuilder(
    column: $table.reserved,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get unit => $composableBuilder(
    column: $table.unit,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalInventorySnapshotsTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalInventorySnapshotsTableTable> {
  $$LocalInventorySnapshotsTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get inventoryItemId => $composableBuilder(
    column: $table.inventoryItemId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get outletId => $composableBuilder(
    column: $table.outletId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get sku => $composableBuilder(
    column: $table.sku,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get onHand => $composableBuilder(
    column: $table.onHand,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get reserved => $composableBuilder(
    column: $table.reserved,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get unit => $composableBuilder(
    column: $table.unit,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalInventorySnapshotsTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalInventorySnapshotsTableTable> {
  $$LocalInventorySnapshotsTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get inventoryItemId => $composableBuilder(
    column: $table.inventoryItemId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get locationId => $composableBuilder(
    column: $table.locationId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get outletId =>
      $composableBuilder(column: $table.outletId, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get sku =>
      $composableBuilder(column: $table.sku, builder: (column) => column);

  GeneratedColumn<double> get onHand =>
      $composableBuilder(column: $table.onHand, builder: (column) => column);

  GeneratedColumn<double> get reserved =>
      $composableBuilder(column: $table.reserved, builder: (column) => column);

  GeneratedColumn<String> get unit =>
      $composableBuilder(column: $table.unit, builder: (column) => column);
}

class $$LocalInventorySnapshotsTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalInventorySnapshotsTableTable,
          LocalInventorySnapshotsTableData,
          $$LocalInventorySnapshotsTableTableFilterComposer,
          $$LocalInventorySnapshotsTableTableOrderingComposer,
          $$LocalInventorySnapshotsTableTableAnnotationComposer,
          $$LocalInventorySnapshotsTableTableCreateCompanionBuilder,
          $$LocalInventorySnapshotsTableTableUpdateCompanionBuilder,
          (
            LocalInventorySnapshotsTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalInventorySnapshotsTableTable,
              LocalInventorySnapshotsTableData
            >,
          ),
          LocalInventorySnapshotsTableData,
          PrefetchHooks Function()
        > {
  $$LocalInventorySnapshotsTableTableTableManager(
    _$AppDatabase db,
    $LocalInventorySnapshotsTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalInventorySnapshotsTableTableFilterComposer(
                $db: db,
                $table: table,
              ),
          createOrderingComposer: () =>
              $$LocalInventorySnapshotsTableTableOrderingComposer(
                $db: db,
                $table: table,
              ),
          createComputedFieldComposer: () =>
              $$LocalInventorySnapshotsTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> inventoryItemId = const Value.absent(),
                Value<String> locationId = const Value.absent(),
                Value<String> outletId = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> sku = const Value.absent(),
                Value<double> onHand = const Value.absent(),
                Value<double> reserved = const Value.absent(),
                Value<String> unit = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalInventorySnapshotsTableCompanion(
                id: id,
                inventoryItemId: inventoryItemId,
                locationId: locationId,
                outletId: outletId,
                name: name,
                sku: sku,
                onHand: onHand,
                reserved: reserved,
                unit: unit,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String inventoryItemId,
                required String locationId,
                required String outletId,
                required String name,
                Value<String?> sku = const Value.absent(),
                Value<double> onHand = const Value.absent(),
                Value<double> reserved = const Value.absent(),
                Value<String> unit = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalInventorySnapshotsTableCompanion.insert(
                id: id,
                inventoryItemId: inventoryItemId,
                locationId: locationId,
                outletId: outletId,
                name: name,
                sku: sku,
                onHand: onHand,
                reserved: reserved,
                unit: unit,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<
                    $LocalInventorySnapshotsTableTable,
                    LocalInventorySnapshotsTableData
                  >(table),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalInventorySnapshotsTableTable,
                    LocalInventorySnapshotsTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalInventorySnapshotsTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalInventorySnapshotsTableTable,
      LocalInventorySnapshotsTableData,
      $$LocalInventorySnapshotsTableTableFilterComposer,
      $$LocalInventorySnapshotsTableTableOrderingComposer,
      $$LocalInventorySnapshotsTableTableAnnotationComposer,
      $$LocalInventorySnapshotsTableTableCreateCompanionBuilder,
      $$LocalInventorySnapshotsTableTableUpdateCompanionBuilder,
      (
        LocalInventorySnapshotsTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalInventorySnapshotsTableTable,
          LocalInventorySnapshotsTableData
        >,
      ),
      LocalInventorySnapshotsTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalCustomerSnapshotsTableTableCreateCompanionBuilder =
    LocalCustomerSnapshotsTableCompanion Function({
      required String id,
      required String tenantId,
      required String name,
      Value<String?> phone,
      Value<String?> email,
      Value<int> rowid,
    });
typedef $$LocalCustomerSnapshotsTableTableUpdateCompanionBuilder =
    LocalCustomerSnapshotsTableCompanion Function({
      Value<String> id,
      Value<String> tenantId,
      Value<String> name,
      Value<String?> phone,
      Value<String?> email,
      Value<int> rowid,
    });

class $$LocalCustomerSnapshotsTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalCustomerSnapshotsTableTable> {
  $$LocalCustomerSnapshotsTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get tenantId => $composableBuilder(
    column: $table.tenantId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get phone => $composableBuilder(
    column: $table.phone,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get email => $composableBuilder(
    column: $table.email,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalCustomerSnapshotsTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalCustomerSnapshotsTableTable> {
  $$LocalCustomerSnapshotsTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get tenantId => $composableBuilder(
    column: $table.tenantId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get phone => $composableBuilder(
    column: $table.phone,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get email => $composableBuilder(
    column: $table.email,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalCustomerSnapshotsTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalCustomerSnapshotsTableTable> {
  $$LocalCustomerSnapshotsTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get tenantId =>
      $composableBuilder(column: $table.tenantId, builder: (column) => column);

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<String> get phone =>
      $composableBuilder(column: $table.phone, builder: (column) => column);

  GeneratedColumn<String> get email =>
      $composableBuilder(column: $table.email, builder: (column) => column);
}

class $$LocalCustomerSnapshotsTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalCustomerSnapshotsTableTable,
          LocalCustomerSnapshotsTableData,
          $$LocalCustomerSnapshotsTableTableFilterComposer,
          $$LocalCustomerSnapshotsTableTableOrderingComposer,
          $$LocalCustomerSnapshotsTableTableAnnotationComposer,
          $$LocalCustomerSnapshotsTableTableCreateCompanionBuilder,
          $$LocalCustomerSnapshotsTableTableUpdateCompanionBuilder,
          (
            LocalCustomerSnapshotsTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalCustomerSnapshotsTableTable,
              LocalCustomerSnapshotsTableData
            >,
          ),
          LocalCustomerSnapshotsTableData,
          PrefetchHooks Function()
        > {
  $$LocalCustomerSnapshotsTableTableTableManager(
    _$AppDatabase db,
    $LocalCustomerSnapshotsTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalCustomerSnapshotsTableTableFilterComposer(
                $db: db,
                $table: table,
              ),
          createOrderingComposer: () =>
              $$LocalCustomerSnapshotsTableTableOrderingComposer(
                $db: db,
                $table: table,
              ),
          createComputedFieldComposer: () =>
              $$LocalCustomerSnapshotsTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> tenantId = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<String?> phone = const Value.absent(),
                Value<String?> email = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalCustomerSnapshotsTableCompanion(
                id: id,
                tenantId: tenantId,
                name: name,
                phone: phone,
                email: email,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String tenantId,
                required String name,
                Value<String?> phone = const Value.absent(),
                Value<String?> email = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalCustomerSnapshotsTableCompanion.insert(
                id: id,
                tenantId: tenantId,
                name: name,
                phone: phone,
                email: email,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<
                    $LocalCustomerSnapshotsTableTable,
                    LocalCustomerSnapshotsTableData
                  >(table),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalCustomerSnapshotsTableTable,
                    LocalCustomerSnapshotsTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalCustomerSnapshotsTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalCustomerSnapshotsTableTable,
      LocalCustomerSnapshotsTableData,
      $$LocalCustomerSnapshotsTableTableFilterComposer,
      $$LocalCustomerSnapshotsTableTableOrderingComposer,
      $$LocalCustomerSnapshotsTableTableAnnotationComposer,
      $$LocalCustomerSnapshotsTableTableCreateCompanionBuilder,
      $$LocalCustomerSnapshotsTableTableUpdateCompanionBuilder,
      (
        LocalCustomerSnapshotsTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalCustomerSnapshotsTableTable,
          LocalCustomerSnapshotsTableData
        >,
      ),
      LocalCustomerSnapshotsTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalCartsTableTableCreateCompanionBuilder =
    LocalCartsTableCompanion Function({
      required String id,
      required String outletId,
      Value<String?> customerId,
      Value<int> discountMinor,
      Value<int> taxMinor,
      Value<String?> notes,
      required DateTime updatedAt,
      Value<int> rowid,
    });
typedef $$LocalCartsTableTableUpdateCompanionBuilder =
    LocalCartsTableCompanion Function({
      Value<String> id,
      Value<String> outletId,
      Value<String?> customerId,
      Value<int> discountMinor,
      Value<int> taxMinor,
      Value<String?> notes,
      Value<DateTime> updatedAt,
      Value<int> rowid,
    });

class $$LocalCartsTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalCartsTableTable> {
  $$LocalCartsTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get outletId => $composableBuilder(
    column: $table.outletId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get taxMinor => $composableBuilder(
    column: $table.taxMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalCartsTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalCartsTableTable> {
  $$LocalCartsTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get outletId => $composableBuilder(
    column: $table.outletId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get taxMinor => $composableBuilder(
    column: $table.taxMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalCartsTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalCartsTableTable> {
  $$LocalCartsTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get outletId =>
      $composableBuilder(column: $table.outletId, builder: (column) => column);

  GeneratedColumn<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => column,
  );

  GeneratedColumn<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => column,
  );

  GeneratedColumn<int> get taxMinor =>
      $composableBuilder(column: $table.taxMinor, builder: (column) => column);

  GeneratedColumn<String> get notes =>
      $composableBuilder(column: $table.notes, builder: (column) => column);

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$LocalCartsTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalCartsTableTable,
          LocalCartsTableData,
          $$LocalCartsTableTableFilterComposer,
          $$LocalCartsTableTableOrderingComposer,
          $$LocalCartsTableTableAnnotationComposer,
          $$LocalCartsTableTableCreateCompanionBuilder,
          $$LocalCartsTableTableUpdateCompanionBuilder,
          (
            LocalCartsTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalCartsTableTable,
              LocalCartsTableData
            >,
          ),
          LocalCartsTableData,
          PrefetchHooks Function()
        > {
  $$LocalCartsTableTableTableManager(
    _$AppDatabase db,
    $LocalCartsTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalCartsTableTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$LocalCartsTableTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$LocalCartsTableTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> outletId = const Value.absent(),
                Value<String?> customerId = const Value.absent(),
                Value<int> discountMinor = const Value.absent(),
                Value<int> taxMinor = const Value.absent(),
                Value<String?> notes = const Value.absent(),
                Value<DateTime> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalCartsTableCompanion(
                id: id,
                outletId: outletId,
                customerId: customerId,
                discountMinor: discountMinor,
                taxMinor: taxMinor,
                notes: notes,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String outletId,
                Value<String?> customerId = const Value.absent(),
                Value<int> discountMinor = const Value.absent(),
                Value<int> taxMinor = const Value.absent(),
                Value<String?> notes = const Value.absent(),
                required DateTime updatedAt,
                Value<int> rowid = const Value.absent(),
              }) => LocalCartsTableCompanion.insert(
                id: id,
                outletId: outletId,
                customerId: customerId,
                discountMinor: discountMinor,
                taxMinor: taxMinor,
                notes: notes,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$LocalCartsTableTable, LocalCartsTableData>(
                    table,
                  ),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalCartsTableTable,
                    LocalCartsTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalCartsTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalCartsTableTable,
      LocalCartsTableData,
      $$LocalCartsTableTableFilterComposer,
      $$LocalCartsTableTableOrderingComposer,
      $$LocalCartsTableTableAnnotationComposer,
      $$LocalCartsTableTableCreateCompanionBuilder,
      $$LocalCartsTableTableUpdateCompanionBuilder,
      (
        LocalCartsTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalCartsTableTable,
          LocalCartsTableData
        >,
      ),
      LocalCartsTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalCartItemsTableTableCreateCompanionBuilder =
    LocalCartItemsTableCompanion Function({
      required String id,
      required String cartId,
      required String productVariantId,
      required String productName,
      required String variantName,
      required int unitPriceMinor,
      Value<int> quantity,
      Value<String?> notes,
      Value<int> rowid,
    });
typedef $$LocalCartItemsTableTableUpdateCompanionBuilder =
    LocalCartItemsTableCompanion Function({
      Value<String> id,
      Value<String> cartId,
      Value<String> productVariantId,
      Value<String> productName,
      Value<String> variantName,
      Value<int> unitPriceMinor,
      Value<int> quantity,
      Value<String?> notes,
      Value<int> rowid,
    });

class $$LocalCartItemsTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalCartItemsTableTable> {
  $$LocalCartItemsTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get cartId => $composableBuilder(
    column: $table.cartId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get productVariantId => $composableBuilder(
    column: $table.productVariantId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get productName => $composableBuilder(
    column: $table.productName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get variantName => $composableBuilder(
    column: $table.variantName,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get unitPriceMinor => $composableBuilder(
    column: $table.unitPriceMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get quantity => $composableBuilder(
    column: $table.quantity,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalCartItemsTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalCartItemsTableTable> {
  $$LocalCartItemsTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get cartId => $composableBuilder(
    column: $table.cartId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get productVariantId => $composableBuilder(
    column: $table.productVariantId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get productName => $composableBuilder(
    column: $table.productName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get variantName => $composableBuilder(
    column: $table.variantName,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get unitPriceMinor => $composableBuilder(
    column: $table.unitPriceMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get quantity => $composableBuilder(
    column: $table.quantity,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalCartItemsTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalCartItemsTableTable> {
  $$LocalCartItemsTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get cartId =>
      $composableBuilder(column: $table.cartId, builder: (column) => column);

  GeneratedColumn<String> get productVariantId => $composableBuilder(
    column: $table.productVariantId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get productName => $composableBuilder(
    column: $table.productName,
    builder: (column) => column,
  );

  GeneratedColumn<String> get variantName => $composableBuilder(
    column: $table.variantName,
    builder: (column) => column,
  );

  GeneratedColumn<int> get unitPriceMinor => $composableBuilder(
    column: $table.unitPriceMinor,
    builder: (column) => column,
  );

  GeneratedColumn<int> get quantity =>
      $composableBuilder(column: $table.quantity, builder: (column) => column);

  GeneratedColumn<String> get notes =>
      $composableBuilder(column: $table.notes, builder: (column) => column);
}

class $$LocalCartItemsTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalCartItemsTableTable,
          LocalCartItemsTableData,
          $$LocalCartItemsTableTableFilterComposer,
          $$LocalCartItemsTableTableOrderingComposer,
          $$LocalCartItemsTableTableAnnotationComposer,
          $$LocalCartItemsTableTableCreateCompanionBuilder,
          $$LocalCartItemsTableTableUpdateCompanionBuilder,
          (
            LocalCartItemsTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalCartItemsTableTable,
              LocalCartItemsTableData
            >,
          ),
          LocalCartItemsTableData,
          PrefetchHooks Function()
        > {
  $$LocalCartItemsTableTableTableManager(
    _$AppDatabase db,
    $LocalCartItemsTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalCartItemsTableTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$LocalCartItemsTableTableOrderingComposer(
                $db: db,
                $table: table,
              ),
          createComputedFieldComposer: () =>
              $$LocalCartItemsTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> cartId = const Value.absent(),
                Value<String> productVariantId = const Value.absent(),
                Value<String> productName = const Value.absent(),
                Value<String> variantName = const Value.absent(),
                Value<int> unitPriceMinor = const Value.absent(),
                Value<int> quantity = const Value.absent(),
                Value<String?> notes = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalCartItemsTableCompanion(
                id: id,
                cartId: cartId,
                productVariantId: productVariantId,
                productName: productName,
                variantName: variantName,
                unitPriceMinor: unitPriceMinor,
                quantity: quantity,
                notes: notes,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String cartId,
                required String productVariantId,
                required String productName,
                required String variantName,
                required int unitPriceMinor,
                Value<int> quantity = const Value.absent(),
                Value<String?> notes = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalCartItemsTableCompanion.insert(
                id: id,
                cartId: cartId,
                productVariantId: productVariantId,
                productName: productName,
                variantName: variantName,
                unitPriceMinor: unitPriceMinor,
                quantity: quantity,
                notes: notes,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<
                    $LocalCartItemsTableTable,
                    LocalCartItemsTableData
                  >(table),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalCartItemsTableTable,
                    LocalCartItemsTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalCartItemsTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalCartItemsTableTable,
      LocalCartItemsTableData,
      $$LocalCartItemsTableTableFilterComposer,
      $$LocalCartItemsTableTableOrderingComposer,
      $$LocalCartItemsTableTableAnnotationComposer,
      $$LocalCartItemsTableTableCreateCompanionBuilder,
      $$LocalCartItemsTableTableUpdateCompanionBuilder,
      (
        LocalCartItemsTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalCartItemsTableTable,
          LocalCartItemsTableData
        >,
      ),
      LocalCartItemsTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalSalesTableTableCreateCompanionBuilder =
    LocalSalesTableCompanion Function({
      required String id,
      required String tenantId,
      required String outletId,
      Value<String?> shiftId,
      required String referenceNumber,
      required String cashierUserId,
      Value<String?> customerId,
      Value<String?> customerNameSnapshot,
      Value<String> status,
      Value<String> syncStatus,
      Value<int> subtotalMinor,
      Value<int> discountMinor,
      Value<int> taxMinor,
      Value<int> totalMinor,
      Value<String?> paymentMethod,
      Value<int?> cashReceivedMinor,
      Value<int?> cashChangeMinor,
      Value<String?> notes,
      required DateTime openedAt,
      Value<DateTime?> finalizedAt,
      Value<DateTime?> syncedAt,
      Value<String?> syncError,
      required DateTime createdAt,
      Value<int> rowid,
    });
typedef $$LocalSalesTableTableUpdateCompanionBuilder =
    LocalSalesTableCompanion Function({
      Value<String> id,
      Value<String> tenantId,
      Value<String> outletId,
      Value<String?> shiftId,
      Value<String> referenceNumber,
      Value<String> cashierUserId,
      Value<String?> customerId,
      Value<String?> customerNameSnapshot,
      Value<String> status,
      Value<String> syncStatus,
      Value<int> subtotalMinor,
      Value<int> discountMinor,
      Value<int> taxMinor,
      Value<int> totalMinor,
      Value<String?> paymentMethod,
      Value<int?> cashReceivedMinor,
      Value<int?> cashChangeMinor,
      Value<String?> notes,
      Value<DateTime> openedAt,
      Value<DateTime?> finalizedAt,
      Value<DateTime?> syncedAt,
      Value<String?> syncError,
      Value<DateTime> createdAt,
      Value<int> rowid,
    });

class $$LocalSalesTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalSalesTableTable> {
  $$LocalSalesTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get tenantId => $composableBuilder(
    column: $table.tenantId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get outletId => $composableBuilder(
    column: $table.outletId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get shiftId => $composableBuilder(
    column: $table.shiftId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get referenceNumber => $composableBuilder(
    column: $table.referenceNumber,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get cashierUserId => $composableBuilder(
    column: $table.cashierUserId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get customerNameSnapshot => $composableBuilder(
    column: $table.customerNameSnapshot,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get syncStatus => $composableBuilder(
    column: $table.syncStatus,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get subtotalMinor => $composableBuilder(
    column: $table.subtotalMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get taxMinor => $composableBuilder(
    column: $table.taxMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get totalMinor => $composableBuilder(
    column: $table.totalMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get paymentMethod => $composableBuilder(
    column: $table.paymentMethod,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get cashReceivedMinor => $composableBuilder(
    column: $table.cashReceivedMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get cashChangeMinor => $composableBuilder(
    column: $table.cashChangeMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get openedAt => $composableBuilder(
    column: $table.openedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get finalizedAt => $composableBuilder(
    column: $table.finalizedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get syncedAt => $composableBuilder(
    column: $table.syncedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get syncError => $composableBuilder(
    column: $table.syncError,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalSalesTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalSalesTableTable> {
  $$LocalSalesTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get tenantId => $composableBuilder(
    column: $table.tenantId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get outletId => $composableBuilder(
    column: $table.outletId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get shiftId => $composableBuilder(
    column: $table.shiftId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get referenceNumber => $composableBuilder(
    column: $table.referenceNumber,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get cashierUserId => $composableBuilder(
    column: $table.cashierUserId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get customerNameSnapshot => $composableBuilder(
    column: $table.customerNameSnapshot,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get status => $composableBuilder(
    column: $table.status,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get syncStatus => $composableBuilder(
    column: $table.syncStatus,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get subtotalMinor => $composableBuilder(
    column: $table.subtotalMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get taxMinor => $composableBuilder(
    column: $table.taxMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get totalMinor => $composableBuilder(
    column: $table.totalMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get paymentMethod => $composableBuilder(
    column: $table.paymentMethod,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get cashReceivedMinor => $composableBuilder(
    column: $table.cashReceivedMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get cashChangeMinor => $composableBuilder(
    column: $table.cashChangeMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get openedAt => $composableBuilder(
    column: $table.openedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get finalizedAt => $composableBuilder(
    column: $table.finalizedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get syncedAt => $composableBuilder(
    column: $table.syncedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get syncError => $composableBuilder(
    column: $table.syncError,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalSalesTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalSalesTableTable> {
  $$LocalSalesTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get tenantId =>
      $composableBuilder(column: $table.tenantId, builder: (column) => column);

  GeneratedColumn<String> get outletId =>
      $composableBuilder(column: $table.outletId, builder: (column) => column);

  GeneratedColumn<String> get shiftId =>
      $composableBuilder(column: $table.shiftId, builder: (column) => column);

  GeneratedColumn<String> get referenceNumber => $composableBuilder(
    column: $table.referenceNumber,
    builder: (column) => column,
  );

  GeneratedColumn<String> get cashierUserId => $composableBuilder(
    column: $table.cashierUserId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get customerId => $composableBuilder(
    column: $table.customerId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get customerNameSnapshot => $composableBuilder(
    column: $table.customerNameSnapshot,
    builder: (column) => column,
  );

  GeneratedColumn<String> get status =>
      $composableBuilder(column: $table.status, builder: (column) => column);

  GeneratedColumn<String> get syncStatus => $composableBuilder(
    column: $table.syncStatus,
    builder: (column) => column,
  );

  GeneratedColumn<int> get subtotalMinor => $composableBuilder(
    column: $table.subtotalMinor,
    builder: (column) => column,
  );

  GeneratedColumn<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => column,
  );

  GeneratedColumn<int> get taxMinor =>
      $composableBuilder(column: $table.taxMinor, builder: (column) => column);

  GeneratedColumn<int> get totalMinor => $composableBuilder(
    column: $table.totalMinor,
    builder: (column) => column,
  );

  GeneratedColumn<String> get paymentMethod => $composableBuilder(
    column: $table.paymentMethod,
    builder: (column) => column,
  );

  GeneratedColumn<int> get cashReceivedMinor => $composableBuilder(
    column: $table.cashReceivedMinor,
    builder: (column) => column,
  );

  GeneratedColumn<int> get cashChangeMinor => $composableBuilder(
    column: $table.cashChangeMinor,
    builder: (column) => column,
  );

  GeneratedColumn<String> get notes =>
      $composableBuilder(column: $table.notes, builder: (column) => column);

  GeneratedColumn<DateTime> get openedAt =>
      $composableBuilder(column: $table.openedAt, builder: (column) => column);

  GeneratedColumn<DateTime> get finalizedAt => $composableBuilder(
    column: $table.finalizedAt,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get syncedAt =>
      $composableBuilder(column: $table.syncedAt, builder: (column) => column);

  GeneratedColumn<String> get syncError =>
      $composableBuilder(column: $table.syncError, builder: (column) => column);

  GeneratedColumn<DateTime> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);
}

class $$LocalSalesTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalSalesTableTable,
          LocalSalesTableData,
          $$LocalSalesTableTableFilterComposer,
          $$LocalSalesTableTableOrderingComposer,
          $$LocalSalesTableTableAnnotationComposer,
          $$LocalSalesTableTableCreateCompanionBuilder,
          $$LocalSalesTableTableUpdateCompanionBuilder,
          (
            LocalSalesTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalSalesTableTable,
              LocalSalesTableData
            >,
          ),
          LocalSalesTableData,
          PrefetchHooks Function()
        > {
  $$LocalSalesTableTableTableManager(
    _$AppDatabase db,
    $LocalSalesTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalSalesTableTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$LocalSalesTableTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$LocalSalesTableTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> tenantId = const Value.absent(),
                Value<String> outletId = const Value.absent(),
                Value<String?> shiftId = const Value.absent(),
                Value<String> referenceNumber = const Value.absent(),
                Value<String> cashierUserId = const Value.absent(),
                Value<String?> customerId = const Value.absent(),
                Value<String?> customerNameSnapshot = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<String> syncStatus = const Value.absent(),
                Value<int> subtotalMinor = const Value.absent(),
                Value<int> discountMinor = const Value.absent(),
                Value<int> taxMinor = const Value.absent(),
                Value<int> totalMinor = const Value.absent(),
                Value<String?> paymentMethod = const Value.absent(),
                Value<int?> cashReceivedMinor = const Value.absent(),
                Value<int?> cashChangeMinor = const Value.absent(),
                Value<String?> notes = const Value.absent(),
                Value<DateTime> openedAt = const Value.absent(),
                Value<DateTime?> finalizedAt = const Value.absent(),
                Value<DateTime?> syncedAt = const Value.absent(),
                Value<String?> syncError = const Value.absent(),
                Value<DateTime> createdAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalSalesTableCompanion(
                id: id,
                tenantId: tenantId,
                outletId: outletId,
                shiftId: shiftId,
                referenceNumber: referenceNumber,
                cashierUserId: cashierUserId,
                customerId: customerId,
                customerNameSnapshot: customerNameSnapshot,
                status: status,
                syncStatus: syncStatus,
                subtotalMinor: subtotalMinor,
                discountMinor: discountMinor,
                taxMinor: taxMinor,
                totalMinor: totalMinor,
                paymentMethod: paymentMethod,
                cashReceivedMinor: cashReceivedMinor,
                cashChangeMinor: cashChangeMinor,
                notes: notes,
                openedAt: openedAt,
                finalizedAt: finalizedAt,
                syncedAt: syncedAt,
                syncError: syncError,
                createdAt: createdAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String tenantId,
                required String outletId,
                Value<String?> shiftId = const Value.absent(),
                required String referenceNumber,
                required String cashierUserId,
                Value<String?> customerId = const Value.absent(),
                Value<String?> customerNameSnapshot = const Value.absent(),
                Value<String> status = const Value.absent(),
                Value<String> syncStatus = const Value.absent(),
                Value<int> subtotalMinor = const Value.absent(),
                Value<int> discountMinor = const Value.absent(),
                Value<int> taxMinor = const Value.absent(),
                Value<int> totalMinor = const Value.absent(),
                Value<String?> paymentMethod = const Value.absent(),
                Value<int?> cashReceivedMinor = const Value.absent(),
                Value<int?> cashChangeMinor = const Value.absent(),
                Value<String?> notes = const Value.absent(),
                required DateTime openedAt,
                Value<DateTime?> finalizedAt = const Value.absent(),
                Value<DateTime?> syncedAt = const Value.absent(),
                Value<String?> syncError = const Value.absent(),
                required DateTime createdAt,
                Value<int> rowid = const Value.absent(),
              }) => LocalSalesTableCompanion.insert(
                id: id,
                tenantId: tenantId,
                outletId: outletId,
                shiftId: shiftId,
                referenceNumber: referenceNumber,
                cashierUserId: cashierUserId,
                customerId: customerId,
                customerNameSnapshot: customerNameSnapshot,
                status: status,
                syncStatus: syncStatus,
                subtotalMinor: subtotalMinor,
                discountMinor: discountMinor,
                taxMinor: taxMinor,
                totalMinor: totalMinor,
                paymentMethod: paymentMethod,
                cashReceivedMinor: cashReceivedMinor,
                cashChangeMinor: cashChangeMinor,
                notes: notes,
                openedAt: openedAt,
                finalizedAt: finalizedAt,
                syncedAt: syncedAt,
                syncError: syncError,
                createdAt: createdAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$LocalSalesTableTable, LocalSalesTableData>(
                    table,
                  ),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalSalesTableTable,
                    LocalSalesTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalSalesTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalSalesTableTable,
      LocalSalesTableData,
      $$LocalSalesTableTableFilterComposer,
      $$LocalSalesTableTableOrderingComposer,
      $$LocalSalesTableTableAnnotationComposer,
      $$LocalSalesTableTableCreateCompanionBuilder,
      $$LocalSalesTableTableUpdateCompanionBuilder,
      (
        LocalSalesTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalSalesTableTable,
          LocalSalesTableData
        >,
      ),
      LocalSalesTableData,
      PrefetchHooks Function()
    >;
typedef $$LocalSaleItemsTableTableCreateCompanionBuilder =
    LocalSaleItemsTableCompanion Function({
      required String id,
      required String saleId,
      required String productVariantId,
      required String productNameSnapshot,
      required String variantNameSnapshot,
      required double quantity,
      required int unitPriceMinor,
      required int subtotalMinor,
      Value<int> discountMinor,
      required int totalMinor,
      Value<String?> notes,
      Value<int> rowid,
    });
typedef $$LocalSaleItemsTableTableUpdateCompanionBuilder =
    LocalSaleItemsTableCompanion Function({
      Value<String> id,
      Value<String> saleId,
      Value<String> productVariantId,
      Value<String> productNameSnapshot,
      Value<String> variantNameSnapshot,
      Value<double> quantity,
      Value<int> unitPriceMinor,
      Value<int> subtotalMinor,
      Value<int> discountMinor,
      Value<int> totalMinor,
      Value<String?> notes,
      Value<int> rowid,
    });

class $$LocalSaleItemsTableTableFilterComposer
    extends Composer<_$AppDatabase, $LocalSaleItemsTableTable> {
  $$LocalSaleItemsTableTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get saleId => $composableBuilder(
    column: $table.saleId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get productVariantId => $composableBuilder(
    column: $table.productVariantId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get productNameSnapshot => $composableBuilder(
    column: $table.productNameSnapshot,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get variantNameSnapshot => $composableBuilder(
    column: $table.variantNameSnapshot,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get quantity => $composableBuilder(
    column: $table.quantity,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get unitPriceMinor => $composableBuilder(
    column: $table.unitPriceMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get subtotalMinor => $composableBuilder(
    column: $table.subtotalMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get totalMinor => $composableBuilder(
    column: $table.totalMinor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnFilters(column),
  );
}

class $$LocalSaleItemsTableTableOrderingComposer
    extends Composer<_$AppDatabase, $LocalSaleItemsTableTable> {
  $$LocalSaleItemsTableTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get saleId => $composableBuilder(
    column: $table.saleId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get productVariantId => $composableBuilder(
    column: $table.productVariantId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get productNameSnapshot => $composableBuilder(
    column: $table.productNameSnapshot,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get variantNameSnapshot => $composableBuilder(
    column: $table.variantNameSnapshot,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get quantity => $composableBuilder(
    column: $table.quantity,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get unitPriceMinor => $composableBuilder(
    column: $table.unitPriceMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get subtotalMinor => $composableBuilder(
    column: $table.subtotalMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get totalMinor => $composableBuilder(
    column: $table.totalMinor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get notes => $composableBuilder(
    column: $table.notes,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$LocalSaleItemsTableTableAnnotationComposer
    extends Composer<_$AppDatabase, $LocalSaleItemsTableTable> {
  $$LocalSaleItemsTableTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get saleId =>
      $composableBuilder(column: $table.saleId, builder: (column) => column);

  GeneratedColumn<String> get productVariantId => $composableBuilder(
    column: $table.productVariantId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get productNameSnapshot => $composableBuilder(
    column: $table.productNameSnapshot,
    builder: (column) => column,
  );

  GeneratedColumn<String> get variantNameSnapshot => $composableBuilder(
    column: $table.variantNameSnapshot,
    builder: (column) => column,
  );

  GeneratedColumn<double> get quantity =>
      $composableBuilder(column: $table.quantity, builder: (column) => column);

  GeneratedColumn<int> get unitPriceMinor => $composableBuilder(
    column: $table.unitPriceMinor,
    builder: (column) => column,
  );

  GeneratedColumn<int> get subtotalMinor => $composableBuilder(
    column: $table.subtotalMinor,
    builder: (column) => column,
  );

  GeneratedColumn<int> get discountMinor => $composableBuilder(
    column: $table.discountMinor,
    builder: (column) => column,
  );

  GeneratedColumn<int> get totalMinor => $composableBuilder(
    column: $table.totalMinor,
    builder: (column) => column,
  );

  GeneratedColumn<String> get notes =>
      $composableBuilder(column: $table.notes, builder: (column) => column);
}

class $$LocalSaleItemsTableTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $LocalSaleItemsTableTable,
          LocalSaleItemsTableData,
          $$LocalSaleItemsTableTableFilterComposer,
          $$LocalSaleItemsTableTableOrderingComposer,
          $$LocalSaleItemsTableTableAnnotationComposer,
          $$LocalSaleItemsTableTableCreateCompanionBuilder,
          $$LocalSaleItemsTableTableUpdateCompanionBuilder,
          (
            LocalSaleItemsTableData,
            BaseReferences<
              _$AppDatabase,
              $LocalSaleItemsTableTable,
              LocalSaleItemsTableData
            >,
          ),
          LocalSaleItemsTableData,
          PrefetchHooks Function()
        > {
  $$LocalSaleItemsTableTableTableManager(
    _$AppDatabase db,
    $LocalSaleItemsTableTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$LocalSaleItemsTableTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$LocalSaleItemsTableTableOrderingComposer(
                $db: db,
                $table: table,
              ),
          createComputedFieldComposer: () =>
              $$LocalSaleItemsTableTableAnnotationComposer(
                $db: db,
                $table: table,
              ),
          updateCompanionCallback:
              ({
                Value<String> id = const Value.absent(),
                Value<String> saleId = const Value.absent(),
                Value<String> productVariantId = const Value.absent(),
                Value<String> productNameSnapshot = const Value.absent(),
                Value<String> variantNameSnapshot = const Value.absent(),
                Value<double> quantity = const Value.absent(),
                Value<int> unitPriceMinor = const Value.absent(),
                Value<int> subtotalMinor = const Value.absent(),
                Value<int> discountMinor = const Value.absent(),
                Value<int> totalMinor = const Value.absent(),
                Value<String?> notes = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalSaleItemsTableCompanion(
                id: id,
                saleId: saleId,
                productVariantId: productVariantId,
                productNameSnapshot: productNameSnapshot,
                variantNameSnapshot: variantNameSnapshot,
                quantity: quantity,
                unitPriceMinor: unitPriceMinor,
                subtotalMinor: subtotalMinor,
                discountMinor: discountMinor,
                totalMinor: totalMinor,
                notes: notes,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String id,
                required String saleId,
                required String productVariantId,
                required String productNameSnapshot,
                required String variantNameSnapshot,
                required double quantity,
                required int unitPriceMinor,
                required int subtotalMinor,
                Value<int> discountMinor = const Value.absent(),
                required int totalMinor,
                Value<String?> notes = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => LocalSaleItemsTableCompanion.insert(
                id: id,
                saleId: saleId,
                productVariantId: productVariantId,
                productNameSnapshot: productNameSnapshot,
                variantNameSnapshot: variantNameSnapshot,
                quantity: quantity,
                unitPriceMinor: unitPriceMinor,
                subtotalMinor: subtotalMinor,
                discountMinor: discountMinor,
                totalMinor: totalMinor,
                notes: notes,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<
                    $LocalSaleItemsTableTable,
                    LocalSaleItemsTableData
                  >(table),
                  BaseReferences<
                    _$AppDatabase,
                    $LocalSaleItemsTableTable,
                    LocalSaleItemsTableData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$LocalSaleItemsTableTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $LocalSaleItemsTableTable,
      LocalSaleItemsTableData,
      $$LocalSaleItemsTableTableFilterComposer,
      $$LocalSaleItemsTableTableOrderingComposer,
      $$LocalSaleItemsTableTableAnnotationComposer,
      $$LocalSaleItemsTableTableCreateCompanionBuilder,
      $$LocalSaleItemsTableTableUpdateCompanionBuilder,
      (
        LocalSaleItemsTableData,
        BaseReferences<
          _$AppDatabase,
          $LocalSaleItemsTableTable,
          LocalSaleItemsTableData
        >,
      ),
      LocalSaleItemsTableData,
      PrefetchHooks Function()
    >;

class $AppDatabaseManager {
  final _$AppDatabase _db;
  $AppDatabaseManager(this._db);
  $$SyncOutboxTableTableTableManager get syncOutboxTable =>
      $$SyncOutboxTableTableTableManager(_db, _db.syncOutboxTable);
  $$SessionStateTableTableTableManager get sessionStateTable =>
      $$SessionStateTableTableTableManager(_db, _db.sessionStateTable);
  $$SyncMetadataTableTableTableManager get syncMetadataTable =>
      $$SyncMetadataTableTableTableManager(_db, _db.syncMetadataTable);
  $$LocalProductSnapshotsTableTableTableManager
  get localProductSnapshotsTable =>
      $$LocalProductSnapshotsTableTableTableManager(
        _db,
        _db.localProductSnapshotsTable,
      );
  $$LocalProductVariantSnapshotsTableTableTableManager
  get localProductVariantSnapshotsTable =>
      $$LocalProductVariantSnapshotsTableTableTableManager(
        _db,
        _db.localProductVariantSnapshotsTable,
      );
  $$LocalPriceSnapshotsTableTableTableManager get localPriceSnapshotsTable =>
      $$LocalPriceSnapshotsTableTableTableManager(
        _db,
        _db.localPriceSnapshotsTable,
      );
  $$LocalInventorySnapshotsTableTableTableManager
  get localInventorySnapshotsTable =>
      $$LocalInventorySnapshotsTableTableTableManager(
        _db,
        _db.localInventorySnapshotsTable,
      );
  $$LocalCustomerSnapshotsTableTableTableManager
  get localCustomerSnapshotsTable =>
      $$LocalCustomerSnapshotsTableTableTableManager(
        _db,
        _db.localCustomerSnapshotsTable,
      );
  $$LocalCartsTableTableTableManager get localCartsTable =>
      $$LocalCartsTableTableTableManager(_db, _db.localCartsTable);
  $$LocalCartItemsTableTableTableManager get localCartItemsTable =>
      $$LocalCartItemsTableTableTableManager(_db, _db.localCartItemsTable);
  $$LocalSalesTableTableTableManager get localSalesTable =>
      $$LocalSalesTableTableTableManager(_db, _db.localSalesTable);
  $$LocalSaleItemsTableTableTableManager get localSaleItemsTable =>
      $$LocalSaleItemsTableTableTableManager(_db, _db.localSaleItemsTable);
}
