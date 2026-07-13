// Neura Cakrawira Solusi | SeWaroeng
// AppRouter — go_router configuration with shell routes and protected route guards.
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/presentation/pages/login_page.dart';
import '../../features/auth/presentation/pages/register_page.dart';
import '../../features/home/presentation/pages/home_page.dart';
import '../../features/cart/presentation/pages/cart_page.dart';
import '../../features/profile/presentation/pages/profile_page.dart';

class SplashPage extends StatelessWidget { const SplashPage({super.key}); @override Widget build(BuildContext context) => const Scaffold(body: Center(child: Text('SeWaroeng - Loading...'))); }

// Note: In a real app, this provider would check the auth state from a repository.
final authStateProvider = StateProvider<bool>((ref) => false);

final goRouterProvider = Provider<GoRouter>((ref) {
  final isAuthenticated = ref.watch(authStateProvider);

  return GoRouter(
    initialLocation: '/',
    redirect: (context, state) {
      final isGoingToLogin = state.matchedLocation == '/login';
      final isGoingToRegister = state.matchedLocation == '/register';
      
      // Protected routes logic
      if (!isAuthenticated && !isGoingToLogin && !isGoingToRegister && state.matchedLocation != '/') {
        return '/login'; // Redirect unauthenticated users to login
      }
      
      if (isAuthenticated && (isGoingToLogin || isGoingToRegister)) {
        return '/home'; // Redirect authenticated users away from auth pages
      }
      
      return null;
    },
    routes: [
      GoRoute(
        path: '/',
        builder: (context, state) => const SplashPage(),
        // Real app would check init and redirect
        redirect: (context, state) => isAuthenticated ? '/home' : '/login',
      ),
      GoRoute(
        path: '/login',
        builder: (context, state) => const LoginPage(),
      ),
      GoRoute(
        path: '/register',
        builder: (context, state) => const RegisterPage(),
      ),
      // ShellRoute for BottomNavigationBar (Home, Cart, Profile)
      ShellRoute(
        builder: (context, state, child) {
          return Scaffold(
            body: child,
            bottomNavigationBar: BottomNavigationBar(
              currentIndex: _calculateSelectedIndex(state.matchedLocation),
              onTap: (index) {
                switch (index) {
                  case 0: context.go('/home'); break;
                  case 1: context.go('/cart'); break;
                  case 2: context.go('/profile'); break;
                }
              },
              items: const [
                BottomNavigationBarItem(icon: Icon(Icons.home), label: 'Home'),
                BottomNavigationBarItem(icon: Icon(Icons.shopping_cart), label: 'Cart'),
                BottomNavigationBarItem(icon: Icon(Icons.person), label: 'Profile'),
              ],
            ),
          );
        },
        routes: [
          GoRoute(
            path: '/home',
            builder: (context, state) => const HomePage(),
          ),
          GoRoute(
            path: '/cart',
            builder: (context, state) => const CartPage(),
          ),
          GoRoute(
            path: '/profile',
            builder: (context, state) => const ProfilePage(),
          ),
        ],
      ),
    ],
  );
});

int _calculateSelectedIndex(String location) {
  if (location.startsWith('/home')) return 0;
  if (location.startsWith('/cart')) return 1;
  if (location.startsWith('/profile')) return 2;
  return 0;
}
