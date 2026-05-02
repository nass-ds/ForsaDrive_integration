import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'providers/auth_provider.dart';
import 'providers/locale_provider.dart';
import 'services/api_service.dart';
import 'services/auth_service.dart';
import 'services/notification_service.dart';
import 'services/ride_service.dart';
import 'services/user_service.dart';
import 'services/message_service.dart';
import 'services/organization_service.dart';
import 'app.dart';

// Web needs explicit FirebaseOptions; Android reads from google-services.json
const _webOptions = FirebaseOptions(
  apiKey: 'AIzaSyAzY1N_NsQXX7bpus4A3BzEaMj-zWJLnFE',
  authDomain: 'forsadriver.firebaseapp.com',
  projectId: 'forsadriver',
  storageBucket: 'forsadriver.firebasestorage.app',
  messagingSenderId: '427860841533',
  appId: '1:427860841533:web:c13be2915d3395d9d60654',
  measurementId: 'G-032TBDP0SC',
);

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  try {
    await Firebase.initializeApp(options: kIsWeb ? _webOptions : null);
    if (!kIsWeb) {
      final apiService = ApiService();
      await NotificationService.instance.init(apiService);
    }
  } catch (e) {
    debugPrint('[Firebase] Init failed: $e');
  }

  final apiService  = ApiService();
  final authService = AuthService(apiService);

  runApp(AppBootstrap(apiService: apiService, authService: authService));
}

class AppBootstrap extends StatelessWidget {
  final ApiService apiService;
  final AuthService authService;

  const AppBootstrap({
    super.key,
    required this.apiService,
    required this.authService,
  });

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider(
          create: (_) {
            final provider = AuthProvider(authService);
            provider.init();
            apiService.onUnauthorized = () => provider.logout();
            return provider;
          },
        ),
        ChangeNotifierProvider(
          create: (_) {
            final provider = LocaleProvider();
            provider.init();
            return provider;
          },
        ),
        Provider<ApiService>.value(value: apiService),
        Provider<AuthService>.value(value: authService),
        Provider(create: (_) => RideService(apiService)),
        Provider(create: (_) => UserService(apiService)),
        Provider(create: (_) => MessageService(apiService)),
        Provider(create: (_) => OrganizationService(apiService)),
      ],
      child: const ForsaDriveApp(),
    );
  }
}