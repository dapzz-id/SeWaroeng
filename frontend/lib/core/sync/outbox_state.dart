/// Conceptual lifecycle states of an offline sync outbox command.
///
/// Follows SeWaroeng ADR-0004:
/// - [pending]: Locally committed; awaiting upload by sync worker.
/// - [uploading]: Currently in flight over the network; locked to prevent duplicate upload.
/// - [synced]: Server acknowledged and committed authoritative domain transaction (HTTP 200).
/// - [failed]: Network or transient 5xx failure; scheduled for exponential backoff retry.
/// - [conflict]: Business collision on server; requires domain-specific reconciliation.
/// - [requiresAction]: Irreconcilable conflict requiring human cashier/manager review.
enum OutboxState {
  pending,
  uploading,
  synced,
  failed,
  conflict,
  requiresAction;

  String toValue() {
    switch (this) {
      case OutboxState.pending:
        return 'pending';
      case OutboxState.uploading:
        return 'uploading';
      case OutboxState.synced:
        return 'synced';
      case OutboxState.failed:
        return 'failed';
      case OutboxState.conflict:
        return 'conflict';
      case OutboxState.requiresAction:
        return 'requires_action';
    }
  }

  static OutboxState fromValue(String value) {
    switch (value) {
      case 'pending':
        return OutboxState.pending;
      case 'uploading':
        return OutboxState.uploading;
      case 'synced':
        return OutboxState.synced;
      case 'failed':
        return OutboxState.failed;
      case 'conflict':
        return OutboxState.conflict;
      case 'requires_action':
        return OutboxState.requiresAction;
      default:
        throw ArgumentError('Unknown OutboxState: $value');
    }
  }
}
