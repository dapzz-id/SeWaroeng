// Neura Cakrawira Solusi | SeWaroeng
// Provider for POS State Management.

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';
import '../../data/models/pos_transaction_hive_model.dart';
import '../../domain/models/pos_transaction_model.dart';
import '../../domain/repositories/pos_repository.dart';

// State definition
class PosState {
  final List<PosTransactionItemHiveModel> cartItems;
  final String paymentMethod;
  final double discount;
  final double amountPaid;
  final String? notes;

  PosState({
    this.cartItems = const [],
    this.paymentMethod = 'cash',
    this.discount = 0.0,
    this.amountPaid = 0.0,
    this.notes,
  });

  double get subtotal => cartItems.fold(0, (sum, item) => sum + item.subtotal);
  double get tax => 0.0; // Assuming tax is inclusive or 0 for now
  double get total => subtotal - discount + tax;
  double get changeDue => amountPaid >= total ? amountPaid - total : 0;

  PosState copyWith({
    List<PosTransactionItemHiveModel>? cartItems,
    String? paymentMethod,
    double? discount,
    double? amountPaid,
    String? notes,
  }) {
    return PosState(
      cartItems: cartItems ?? this.cartItems,
      paymentMethod: paymentMethod ?? this.paymentMethod,
      discount: discount ?? this.discount,
      amountPaid: amountPaid ?? this.amountPaid,
      notes: notes ?? this.notes,
    );
  }
}

// Notifier
class PosNotifier extends StateNotifier<PosState> {
  final PosRepository _repository;
  final _uuid = const Uuid();

  PosNotifier(this._repository) : super(PosState());

  void addItem(String productId, String name, double price, int quantity) {
    final items = List<PosTransactionItemHiveModel>.from(state.cartItems);
    final index = items.indexWhere((item) => item.productId == productId);

    if (index >= 0) {
      final existing = items[index];
      final newQuantity = existing.quantity + quantity;
      items[index] = PosTransactionItemHiveModel(
        productId: existing.productId,
        productName: existing.productName,
        price: existing.price,
        quantity: newQuantity,
        subtotal: newQuantity * existing.price,
      );
    } else {
      items.add(PosTransactionItemHiveModel(
        productId: productId,
        productName: name,
        price: price,
        quantity: quantity,
        subtotal: price * quantity,
      ));
    }
    state = state.copyWith(cartItems: items);
  }

  void updateQuantity(String productId, int newQuantity) {
    final items = List<PosTransactionItemHiveModel>.from(state.cartItems);
    final index = items.indexWhere((item) => item.productId == productId);

    if (index >= 0) {
      if (newQuantity <= 0) {
        items.removeAt(index);
      } else {
        final existing = items[index];
        items[index] = PosTransactionItemHiveModel(
          productId: existing.productId,
          productName: existing.productName,
          price: existing.price,
          quantity: newQuantity,
          subtotal: newQuantity * existing.price,
        );
      }
      state = state.copyWith(cartItems: items);
    }
  }

  void setPaymentMethod(String method) {
    state = state.copyWith(paymentMethod: method);
  }

  void setAmountPaid(double amount) {
    state = state.copyWith(amountPaid: amount);
  }

  void setNotes(String notes) {
    state = state.copyWith(notes: notes);
  }

  void clearCart() {
    state = PosState(); // Reset everything
  }

  Future<void> checkout({required String tenantId, required String staffId}) async {
    if (state.cartItems.isEmpty) return;
    if (state.amountPaid < state.total && state.paymentMethod == 'cash') {
      throw Exception("Amount paid is less than total");
    }

    final transaction = PosTransactionModel(
      id: _uuid.v7(),
      tenantId: tenantId,
      staffId: staffId,
      total: state.total,
      amountPaid: state.amountPaid,
      changeDue: state.changeDue,
      method: state.paymentMethod,
      notes: state.notes,
      items: state.cartItems
          .map((item) => PosTransactionItemModel(
                productId: item.productId,
                productName: item.productName,
                price: item.price,
                quantity: item.quantity,
                subtotal: item.subtotal,
              ))
          .toList(),
      createdAt: DateTime.now(),
      isSynced: false,
    );

    // Save via repository (offline-first; will auto-sync if online)
    await _repository.saveTransaction(transaction);

    clearCart();
  }

  Future<void> syncPending() => _repository.syncPending();
}

// Provider
final posProvider = StateNotifierProvider<PosNotifier, PosState>((ref) {
  final repository = ref.watch(posRepositoryProvider);
  return PosNotifier(repository);
});
