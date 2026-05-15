import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../utils/app_theme.dart';
import '../config/api_config.dart';

class AppNavBar extends StatelessWidget implements PreferredSizeWidget {
  const AppNavBar({super.key});

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    return AppBar(
      backgroundColor: AppTheme.primary,
      title: GestureDetector(
        onTap: () => context.go('/home'),
        child: Row(
          children: [
            Container(
              width: 32, height: 32,
              decoration: BoxDecoration(color: AppTheme.accent, borderRadius: BorderRadius.circular(8)),
              child: const Icon(Icons.directions_car, color: AppTheme.primary, size: 18),
            ),
            const SizedBox(width: 10),
            const Text('ForsaDrive', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 20)),
          ],
        ),
      ),
      actions: auth.isLoggedIn
          ? [
              _NavBtn(label: 'Dashboard', route: '/dashboard'),
              _NavBtn(label: 'My Rides', route: '/my-rides'),
              _NavBtn(label: 'Messages', route: '/messages'),
              _NavBtn(label: 'Support', route: '/helpdesk'),
              if (auth.isAdmin) _NavBtn(label: 'Admin', route: '/admin'),
              const SizedBox(width: 8),
              _UserMenu(),
            ]
          : [
              TextButton(
                onPressed: () => context.go('/login'),
                child: const Text('Login', style: TextStyle(color: Colors.white70)),
              ),
              const SizedBox(width: 4),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
                child: ElevatedButton(
                  onPressed: () => context.go('/signup'),
                  child: const Text('Sign Up'),
                ),
              ),
            ],
    );
  }
}

class _NavBtn extends StatelessWidget {
  final String label;
  final String route;
  const _NavBtn({required this.label, required this.route});

  @override
  Widget build(BuildContext context) {
    final isActive = GoRouterState.of(context).uri.path.startsWith(route);
    return TextButton(
      onPressed: () => context.go(route),
      child: Text(
        label,
        style: TextStyle(
          color: isActive ? AppTheme.accent : Colors.white70,
          fontWeight: isActive ? FontWeight.w700 : FontWeight.normal,
        ),
      ),
    );
  }
}

class _UserMenu extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user!;
    return Padding(
      padding: const EdgeInsets.only(right: 16),
      child: PopupMenuButton<String>(
        offset: const Offset(0, 50),
        child: Row(
          children: [
            CircleAvatar(
              radius: 16,
              backgroundImage: NetworkImage(ApiConfig.profilePicture(user.picture)),
              onBackgroundImageError: (_, __) {},
            ),
            const SizedBox(width: 6),
            Text(user.username, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
            const Icon(Icons.arrow_drop_down, color: Colors.white),
          ],
        ),
        onSelected: (val) {
          switch (val) {
            case 'settings': context.go('/settings'); break;
            case 'notifications': context.go('/notifications'); break;
            case 'payments': context.go('/payments'); break;
            case 'vehicles': context.go('/vehicles'); break;
            case 'organizations': context.go('/organizations'); break;
            case 'helpdesk': context.go('/helpdesk'); break;
            case 'logout': context.read<AuthProvider>().logout().then((_) {
              if (context.mounted) context.go('/login');
            }); break;
          }
        },
        itemBuilder: (_) => [
          const PopupMenuItem(value: 'settings', child: ListTile(leading: Icon(Icons.person), title: Text('Profile'))),
          const PopupMenuItem(value: 'notifications', child: ListTile(leading: Icon(Icons.notifications), title: Text('Notifications'))),
          const PopupMenuItem(value: 'payments', child: ListTile(leading: Icon(Icons.account_balance_wallet), title: Text('Wallet'))),
          if (context.read<AuthProvider>().isDriver)
            const PopupMenuItem(value: 'vehicles', child: ListTile(leading: Icon(Icons.directions_car), title: Text('My Vehicles'))),
          const PopupMenuItem(value: 'organizations', child: ListTile(leading: Icon(Icons.business_rounded), title: Text('Organizations'))),
          const PopupMenuItem(value: 'helpdesk', child: ListTile(leading: Icon(Icons.support_agent), title: Text('Support'))),
          const PopupMenuDivider(),
          const PopupMenuItem(value: 'logout', child: ListTile(leading: Icon(Icons.logout, color: Colors.red), title: Text('Logout', style: TextStyle(color: Colors.red)))),
        ],
      ),
    );
  }
}
