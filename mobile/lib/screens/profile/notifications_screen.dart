import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../services/user_service.dart';
import '../../models/notification_model.dart';
import '../../utils/app_theme.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<NotificationModel> _notifications = [];
  int _unread = 0;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final result = await context.read<UserService>().getNotifications();
      setState(() { _notifications = result.notifications; _unread = result.unread; });
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      setState(() => _loading = false);
    }
  }

  Future<void> _markAllRead() async {
    await context.read<UserService>().markAllNotificationsRead();
    _load();
  }

  IconData _iconFor(String type) => switch (type) {
    'booking_request' => Icons.people,
    'booking_confirmed' => Icons.check_circle,
    'booking_cancelled' => Icons.cancel,
    'payment' => Icons.payments,
    'rating' => Icons.star,
    _ => Icons.notifications,
  };

  bool _canMessage(NotificationModel n) =>
      n.relatedUserId != null &&
      (n.type == 'booking_request' ||
          n.type == 'booking_confirmed' ||
          n.type == 'ride_update');

  void _openChat(NotificationModel n) {
    context.go('/messages', extra: {
      'userId': n.relatedUserId,
      'userName': n.relatedUserName ?? 'Passenger',
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Notifications${_unread > 0 ? ' ($_unread)' : ''}'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          if (_unread > 0)
            TextButton(onPressed: _markAllRead, child: const Text('Mark all read', style: TextStyle(color: AppTheme.accent))),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _notifications.isEmpty
              ? const Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(Icons.notifications_none, size: 64, color: AppTheme.textSecondary),
                  SizedBox(height: 12),
                  Text('No notifications yet', style: TextStyle(color: AppTheme.textSecondary)),
                ]))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.builder(
                    itemCount: _notifications.length,
                    itemBuilder: (ctx, i) {
                      final n = _notifications[i];
                      return Container(
                        color: n.isRead ? null : AppTheme.primary.withOpacity(0.04),
                        child: ListTile(
                          leading: Container(
                            width: 44, height: 44,
                            decoration: BoxDecoration(
                              color: AppTheme.primary.withOpacity(0.1),
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Icon(_iconFor(n.type), color: AppTheme.primary, size: 20),
                          ),
                          title: Text(n.title, style: TextStyle(fontWeight: n.isRead ? FontWeight.normal : FontWeight.w700, fontSize: 14)),
                          subtitle: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(n.body, style: const TextStyle(fontSize: 13, color: AppTheme.textSecondary)),
                              Text(DateFormat('MMM d, yyyy • h:mm a').format(n.createdAt), style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                              if (_canMessage(n)) ...[
                                const SizedBox(height: 6),
                                SizedBox(
                                  height: 28,
                                  child: OutlinedButton.icon(
                                    onPressed: () => _openChat(n),
                                    icon: const Icon(Icons.chat_bubble_outline, size: 13),
                                    label: Text(
                                      'Message ${n.relatedUserName ?? "Passenger"}',
                                      style: const TextStyle(fontSize: 11),
                                    ),
                                    style: OutlinedButton.styleFrom(
                                      foregroundColor: AppTheme.primary,
                                      side: const BorderSide(color: AppTheme.primary),
                                      padding: const EdgeInsets.symmetric(horizontal: 10),
                                      visualDensity: VisualDensity.compact,
                                    ),
                                  ),
                                ),
                              ],
                            ],
                          ),
                          isThreeLine: true,
                          trailing: !n.isRead ? Container(width: 8, height: 8, decoration: const BoxDecoration(color: AppTheme.primary, shape: BoxShape.circle)) : null,
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}
