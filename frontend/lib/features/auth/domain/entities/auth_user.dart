// Neura Cakrawira Solusi | SeWaroeng
// Auth feature - Domain Layer
import 'package:equatable/equatable.dart';

class AuthUser extends Equatable {
  final String id;
  final String fullName;
  final String email;
  final String role;
  final String? tenantId;

  const AuthUser({
    required this.id,
    required this.fullName,
    required this.email,
    required this.role,
    this.tenantId,
  });

  @override
  List<Object?> get props => [id, fullName, email, role, tenantId];
}

class LoginCredentials extends Equatable {
  final String email;
  final String password;

  const LoginCredentials({required this.email, required this.password});

  @override
  List<Object?> get props => [email, password];
}
