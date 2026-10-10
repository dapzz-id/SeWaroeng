/// Represents the distinct states of the application's authentication lifecycle.
enum AuthStatus {
  unknown,
  unauthenticated,
  authenticating,
  authenticated,
  sessionExpired,
  loggingOut,
  error,
}

class AuthUser {
  final String id;
  final String name;
  final String email;
  final String? phoneNumber;
  final bool isActive;

  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    this.phoneNumber,
    this.isActive = true,
  });

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    return AuthUser(
      id: json['id'] as String,
      name: json['name'] as String,
      email: json['email'] as String,
      phoneNumber: json['phone_number'] as String?,
      isActive: json['is_active'] as bool? ?? true,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'email': email,
    'phone_number': phoneNumber,
    'is_active': isActive,
  };
}

class AuthState {
  final AuthStatus status;
  final AuthUser? user;
  final String? token;
  final String? errorMessage;
  final String? errorCode;

  const AuthState({
    required this.status,
    this.user,
    this.token,
    this.errorMessage,
    this.errorCode,
  });

  factory AuthState.unknown() => const AuthState(status: AuthStatus.unknown);

  factory AuthState.unauthenticated() =>
      const AuthState(status: AuthStatus.unauthenticated);

  factory AuthState.authenticating() =>
      const AuthState(status: AuthStatus.authenticating);

  factory AuthState.authenticated({
    required AuthUser user,
    required String token,
  }) => AuthState(status: AuthStatus.authenticated, user: user, token: token);

  factory AuthState.sessionExpired({String? message}) => AuthState(
    status: AuthStatus.sessionExpired,
    errorMessage: message ?? 'Session expired. Please sign in again.',
  );

  factory AuthState.loggingOut() =>
      const AuthState(status: AuthStatus.loggingOut);

  factory AuthState.error({required String message, String? code}) => AuthState(
    status: AuthStatus.error,
    errorMessage: message,
    errorCode: code,
  );

  bool get isAuthenticated => status == AuthStatus.authenticated;
}
