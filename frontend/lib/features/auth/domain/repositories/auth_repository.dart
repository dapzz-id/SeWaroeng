// Neura Cakrawira Solusi | SeWaroeng
// Auth feature - Domain Layer Repositories
import '../entities/auth_user.dart';

abstract class AuthRepository {
  Future<AuthUser> login(LoginCredentials credentials);
  Future<void> logout();
  Future<AuthUser?> checkAuthStatus();
}
