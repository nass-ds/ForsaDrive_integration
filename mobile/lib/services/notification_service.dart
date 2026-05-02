import 'dart:async';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'api_service.dart';

// ── Background handler — must be a top-level function ────────────────────────
// Called in a separate isolate when app is killed. Keep it minimal.
@pragma('vm:entry-point')
Future<void> onFirebaseBackgroundMessage(RemoteMessage message) async {
  debugPrint('[FCM] Background: ${message.notification?.title}');
}

// ── NotificationService ───────────────────────────────────────────────────────

class NotificationService {
  NotificationService._();
  static final instance = NotificationService._();

  final _fcm = FirebaseMessaging.instance;
  final _localNotif = FlutterLocalNotificationsPlugin();

  // Must match android:value in AndroidManifest.xml → default_notification_channel_id
  static const _channelId = 'high_importance_channel';
  static const _channel = AndroidNotificationChannel(
    _channelId,
    'ForsaDrive',
    description: 'Booking confirmations, ride updates and messages',
    importance: Importance.high,
  );

  // Emits a route string whenever the user taps a notification
  final _routeCtrl = StreamController<String>.broadcast();
  Stream<String> get onNotificationTap => _routeCtrl.stream;

  Future<void> init(ApiService apiService) async {
    // Web doesn't support local notifications — skip local notif setup
    if (!kIsWeb) {
      // Register background handler before any other listener
      FirebaseMessaging.onBackgroundMessage(onFirebaseBackgroundMessage);

      // Create high-importance channel on Android
      await _localNotif
          .resolvePlatformSpecificImplementation<
              AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(_channel);

      // Initialise local notifications plugin
      await _localNotif.initialize(
        const InitializationSettings(
          android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        ),
        onDidReceiveNotificationResponse: (details) {
          final payload = details.payload;
          if (payload != null && payload.isNotEmpty) {
            _routeCtrl.add(payload);
          }
        },
      );
    }

    // Request permission (Android 13+, iOS)
    final settings = await _fcm.requestPermission(
      alert: true,
      badge: true,
      sound: true,
      provisional: false,
    );

    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      debugPrint('[FCM] Permission denied');
      return;
    }

    // Foreground: app is open → show local notification banner
    FirebaseMessaging.onMessage.listen(_handleForeground);

    // Background → app re-opened via notification tap
    FirebaseMessaging.onMessageOpenedApp.listen(_handleTap);

    // Terminated → app launched via notification tap
    final initial = await _fcm.getInitialMessage();
    if (initial != null) _handleTap(initial);

    // Get token and sync to backend
    final token = await _fcm.getToken();
    if (token != null) await _syncToken(apiService, token);

    // Keep token fresh
    _fcm.onTokenRefresh.listen((t) => _syncToken(apiService, t));
  }

  // ── Foreground handler ──────────────────────────────────────────────────────

  void _handleForeground(RemoteMessage message) {
    if (kIsWeb) return; // Web handles its own push display
    final n = message.notification;
    if (n == null) return;

    _localNotif.show(
      // Use hashCode as a stable-ish unique int ID
      message.messageId.hashCode,
      n.title,
      n.body,
      NotificationDetails(
        android: AndroidNotificationDetails(
          _channel.id,
          _channel.name,
          channelDescription: _channel.description,
          importance: Importance.high,
          priority: Priority.high,
          icon: '@mipmap/ic_launcher',
          styleInformation: n.body != null
              ? BigTextStyleInformation(n.body!)
              : null,
        ),
      ),
      // payload is the route — emitted when the user taps the banner
      payload: _routeFromData(message.data),
    );
  }

  // ── Tap handler ─────────────────────────────────────────────────────────────

  void _handleTap(RemoteMessage message) {
    final route = _routeFromData(message.data);
    _routeCtrl.add(route);
  }

  // ── Route resolver ──────────────────────────────────────────────────────────
  // Your PHP backend should send `data: { type: '...', id: '...' }` in the
  // FCM payload alongside the `notification` block.

  String _routeFromData(Map<String, dynamic> data) {
    final type = data['type'] as String?;
    final id   = data['id']   as String?;

    switch (type) {
      case 'booking_confirmed':   return '/my-rides';
      case 'booking_request':     return '/booking-requests';
      case 'ride_update':         return id != null ? '/ride/$id' : '/home';
      case 'ride_departed':       return id != null ? '/ride/$id' : '/home';
      case 'ride_arrived':        return id != null ? '/ride/$id/confirm' : '/home';
      case 'message':             return '/messages';
      case 'payment':             return '/payments';
      default:                    return '/home';
    }
  }

  // ── Token sync ──────────────────────────────────────────────────────────────

  Future<void> _syncToken(ApiService apiService, String token) async {
    debugPrint('[FCM] Token: ${token.substring(0, 20)}...');
    try {
      await apiService.post('/users/fcm-token', {'fcm_token': token});
    } catch (e) {
      // Non-fatal — will retry on next token refresh
      debugPrint('[FCM] Token sync failed: $e');
    }
  }
}