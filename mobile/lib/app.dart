import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'services/notification_service.dart';

import 'l10n/app_localizations.dart';
import 'providers/auth_provider.dart';
import 'providers/locale_provider.dart';
import 'utils/app_theme.dart';

import 'screens/landing/landing_screen.dart';  // exports HomeScreen
import 'screens/auth/login_screen.dart';
import 'screens/auth/signup_screen.dart';
import 'screens/dashboard/dashboard_screen.dart';
import 'screens/rides/my_rides_screen.dart';
import 'screens/rides/offer_ride_screen.dart';
import 'screens/driver/booking_requests_screen.dart';
import 'screens/driver/driver_dashboard_screen.dart';
import 'screens/driver/vehicles_screen.dart';
import 'screens/payments/payments_screen.dart';
import 'screens/messages/messages_screen.dart';
import 'screens/helpdesk/helpdesk_screen.dart';
import 'screens/profile/settings_screen.dart';
import 'screens/profile/student_verification_screen.dart';
import 'screens/profile/notifications_screen.dart';
import 'screens/rides/ride_detail_screen.dart';
import 'screens/rides/group_booking_screen.dart';
import 'screens/ratings/ratings_screen.dart';
import 'screens/complaints/complaints_screen.dart';
import 'screens/admin/admin_screen.dart';
import 'screens/driver/ride_confirmation_screen.dart';
import 'screens/rides/trip_receipt_screen.dart';
import 'screens/feed/feed_screen.dart';
import 'screens/organization/organization_screen.dart';

GoRouter _buildRouter(AuthProvider auth) {
  return GoRouter(
    refreshListenable: auth,
    redirect: (context, state) {
      final loggedIn = auth.isLoggedIn;
      final path = state.uri.path;
      final isAuth = path == '/login' || path == '/signup';

      if (auth.status == AuthStatus.unknown) return null;
      // Not logged in → send to login (unless already there)
      if (!loggedIn && !isAuth) return '/login';
      // Logged in but on auth page → send to home
      if (loggedIn && isAuth) return '/home';
      return null;
    },
    routes: [
      GoRoute(path: '/', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/home', builder: (_, __) => const HomeScreen()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/signup', builder: (_, __) => const SignupScreen()),
      GoRoute(path: '/dashboard', builder: (_, __) => const DashboardScreen()),
      GoRoute(path: '/my-rides', builder: (_, __) => const MyRidesScreen()),
      GoRoute(
        path: '/offer-ride',
        redirect: (ctx, state) {
          if (!ctx.read<AuthProvider>().isDriver) return '/home';
          return null;
        },
        builder: (_, __) => const OfferRideScreen(),
      ),
      GoRoute(path: '/booking-requests', builder: (_, __) => const BookingRequestsScreen()),
      GoRoute(path: '/driver-dashboard', builder: (_, __) => const DriverDashboardScreen()),
      GoRoute(path: '/vehicles', builder: (_, __) => const VehiclesScreen()),
      GoRoute(path: '/payments', builder: (_, __) => const PaymentsScreen()),
      GoRoute(
        path: '/messages',
        builder: (_, state) {
          final extra = state.extra as Map<String, dynamic>?;
          return MessagesScreen(
            initialUserId: extra?['userId'] as int?,
            initialUserName: extra?['userName'] as String?,
          );
        },
      ),
      GoRoute(path: '/helpdesk', builder: (_, __) => const HelpdeskScreen()),
      GoRoute(path: '/settings', builder: (_, __) => const SettingsScreen()),
      GoRoute(path: '/student-verify', builder: (_, __) => const StudentVerificationScreen()),
      GoRoute(
        path: '/ride/:id',
        builder: (_, state) => RideDetailScreen(
          rideId: int.parse(state.pathParameters['id']!),
        ),
        routes: [
          GoRoute(
            path: 'group',
            builder: (_, state) => GroupBookingScreen(
              rideId: int.parse(state.pathParameters['id']!),
            ),
          ),
          GoRoute(
            path: 'confirm',
            builder: (_, state) => RideConfirmationScreen(
              rideId: int.parse(state.pathParameters['id']!),
            ),
          ),
          GoRoute(
            path: 'receipt',
            builder: (_, state) => TripReceiptScreen(
              rideId: int.parse(state.pathParameters['id']!),
            ),
          ),
        ],
      ),
      GoRoute(path: '/feed', builder: (_, __) => const FeedScreen()),
      GoRoute(path: '/notifications', builder: (_, __) => const NotificationsScreen()),
      GoRoute(path: '/ratings', builder: (_, __) => const RatingsScreen()),
      GoRoute(path: '/complaints', builder: (_, __) => const ComplaintsScreen()),
      GoRoute(path: '/organizations', builder: (_, __) => const OrganizationScreen()),
      GoRoute(
        path: '/admin',
        redirect: (ctx, state) {
          if (!ctx.read<AuthProvider>().isAdmin) return '/dashboard';
          return null;
        },
        builder: (_, __) => const AdminScreen(),
      ),
    ],
  );
}

class ForsaDriveApp extends StatefulWidget {
  const ForsaDriveApp({super.key});

  @override
  State<ForsaDriveApp> createState() => _ForsaDriveAppState();
}

class _ForsaDriveAppState extends State<ForsaDriveApp> {
  GoRouter? _router;
  StreamSubscription<String>? _notifSub;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_router == null) {
      _router = _buildRouter(context.read<AuthProvider>());
      // Listen for notification taps and navigate the router
      _notifSub = NotificationService.instance.onNotificationTap.listen((route) {
        _router?.go(route);
      });
    }
  }

  @override
  void dispose() {
    _notifSub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>().locale;
    return MaterialApp.router(
      title: 'ForsaDrive',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.theme,
      routerConfig: _router!,
      locale: locale,
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
    );
  }
}
