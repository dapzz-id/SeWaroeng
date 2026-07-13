// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'pos_transaction_hive_model.dart';

// **************************************************************************
// TypeAdapterGenerator
// **************************************************************************

class PosTransactionHiveModelAdapter
    extends TypeAdapter<PosTransactionHiveModel> {
  @override
  final int typeId = 1;

  @override
  PosTransactionHiveModel read(BinaryReader reader) {
    final numOfFields = reader.readByte();
    final fields = <int, dynamic>{
      for (int i = 0; i < numOfFields; i++) reader.readByte(): reader.read(),
    };
    return PosTransactionHiveModel(
      id: fields[0] as String,
      tenantId: fields[1] as String,
      staffId: fields[2] as String,
      total: fields[3] as double,
      amountPaid: fields[4] as double,
      changeDue: fields[5] as double,
      method: fields[6] as String,
      notes: fields[7] as String?,
      items: (fields[8] as List).cast<PosTransactionItemHiveModel>(),
      createdAt: fields[9] as DateTime,
      isSynced: fields[10] as bool,
    );
  }

  @override
  void write(BinaryWriter writer, PosTransactionHiveModel obj) {
    writer
      ..writeByte(11)
      ..writeByte(0)
      ..write(obj.id)
      ..writeByte(1)
      ..write(obj.tenantId)
      ..writeByte(2)
      ..write(obj.staffId)
      ..writeByte(3)
      ..write(obj.total)
      ..writeByte(4)
      ..write(obj.amountPaid)
      ..writeByte(5)
      ..write(obj.changeDue)
      ..writeByte(6)
      ..write(obj.method)
      ..writeByte(7)
      ..write(obj.notes)
      ..writeByte(8)
      ..write(obj.items)
      ..writeByte(9)
      ..write(obj.createdAt)
      ..writeByte(10)
      ..write(obj.isSynced);
  }

  @override
  int get hashCode => typeId.hashCode;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PosTransactionHiveModelAdapter &&
          runtimeType == other.runtimeType &&
          typeId == other.typeId;
}

class PosTransactionItemHiveModelAdapter
    extends TypeAdapter<PosTransactionItemHiveModel> {
  @override
  final int typeId = 2;

  @override
  PosTransactionItemHiveModel read(BinaryReader reader) {
    final numOfFields = reader.readByte();
    final fields = <int, dynamic>{
      for (int i = 0; i < numOfFields; i++) reader.readByte(): reader.read(),
    };
    return PosTransactionItemHiveModel(
      productId: fields[0] as String,
      productName: fields[1] as String,
      price: fields[2] as double,
      quantity: fields[3] as int,
      subtotal: fields[4] as double,
    );
  }

  @override
  void write(BinaryWriter writer, PosTransactionItemHiveModel obj) {
    writer
      ..writeByte(5)
      ..writeByte(0)
      ..write(obj.productId)
      ..writeByte(1)
      ..write(obj.productName)
      ..writeByte(2)
      ..write(obj.price)
      ..writeByte(3)
      ..write(obj.quantity)
      ..writeByte(4)
      ..write(obj.subtotal);
  }

  @override
  int get hashCode => typeId.hashCode;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PosTransactionItemHiveModelAdapter &&
          runtimeType == other.runtimeType &&
          typeId == other.typeId;
}
