import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../../l10n/app_localizations.dart';
import '../../models/ride_model.dart';
import '../../providers/auth_provider.dart';
import '../../services/ride_service.dart';
import '../../services/api_service.dart';
import '../../utils/app_theme.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
// ─── Palette helpers ────────────────────────────────────────────────────────
const _kGold = AppTheme.accent;
const _kGoldDark = AppTheme.accentDark;
const _kNavy = AppTheme.primary;
const _kCard = AppTheme.darkCard;
const _kSurface = AppTheme.darkSurface;
const _kBorder = Color(0xFF1E3A5F);
const _kGreen = AppTheme.success;
const _kRed = AppTheme.danger;
const _kMuted = Color(0xFF8A9BB5);
const _kWhite = Colors.white;

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen>
    with SingleTickerProviderStateMixin {
  int _navIndex = 0;
  int _selectedRoute = 0;
  late AnimationController _pulseCtrl;

  List<RideModel> _rides = [];
  bool _loadingRides = false;
  int _unreadNotifications = 0;

  // Recommendations
  List<RideModel> _recPersonalized = [];
  List<Map<String, dynamic>> _recTrending = [];
  List<RideModel> _recNearby = [];
  bool _loadingRec = false;

  // Route chip data → (from, to) filter pairs (labels are translated at build time)
  static const _quickRouteData = [
    ('', ''),
    ('Tunis', 'Sousse'),
    ('Tunis', 'Sfax'),
    ('Ariana', 'Bizerte'),
    ('La Marsa', 'Nabeul'),
  ];

  static const _quickRouteLabels = [
    '',                        // replaced by l10n.all at build time
    'Tunis → Sousse',
    'Tunis → Sfax',
    'Ariana → Bizerte',
    'La Marsa → Nabeul',
  ];

  @override
  void initState() {
    super.initState();
    _pulseCtrl = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 2),
    )..repeat(reverse: true);
    _loadRides();
    _loadUnreadCount();
    _loadRecommendations();
  }

  @override
  void dispose() {
    _pulseCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadRides({String from = '', String to = ''}) async {
    setState(() => _loadingRides = true);
    try {
      final rides = await context.read<RideService>().getAvailableRides(
            from: from.isEmpty ? null : from,
            to: to.isEmpty ? null : to,
          );
      if (mounted) setState(() => _rides = rides);
    } catch (_) {
      // silently fail — show empty state
    } finally {
      if (mounted) setState(() => _loadingRides = false);
    }
  }

  Future<void> _loadUnreadCount() async {
    if (!mounted) return;
    try {
      final res = await context.read<ApiService>().get('/notifications/');
      if (mounted) {
        setState(() => _unreadNotifications = res['unread_count'] as int? ?? 0);
      }
    } catch (_) {}
  }

  Future<void> _loadRecommendations() async {
    if (!mounted) return;
    setState(() => _loadingRec = true);
    try {
      final res = await context.read<ApiService>().get('/recommendations/');
      if (!mounted) return;
      setState(() {
        _recPersonalized = (res['personalized'] as List? ?? [])
            .map((e) => RideModel.fromJson(e as Map<String, dynamic>))
            .toList();
        _recTrending = List<Map<String, dynamic>>.from(
            res['trending'] as List? ?? []);
        _recNearby = (res['nearby'] as List? ?? [])
            .map((e) => RideModel.fromJson(e as Map<String, dynamic>))
            .toList();
      });
    } catch (_) {
      // silently fail — recommendations are non-critical
    } finally {
      if (mounted) setState(() => _loadingRec = false);
    }
  }

  void _onRouteChip(int i) {
    setState(() => _selectedRoute = i);
    final (from, to) = _quickRouteData[i];
    _loadRides(from: from, to: to);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final size = MediaQuery.of(context).size;
    final px = size.width * 0.055;

    // First chip label is translated; rest are proper-noun routes
    final routeLabels = [
      l10n.all,
      ..._quickRouteLabels.skip(1),
    ];

    return Scaffold(
      backgroundColor: _kNavy,
      body: SafeArea(
        child: CustomScrollView(
          physics: const BouncingScrollPhysics(),
          slivers: [
            // ── Top Bar ──────────────────────────────────────
            SliverToBoxAdapter(
              child: _TopBar(px: px, unreadCount: _unreadNotifications,
                  onNotificationTap: () => context.push('/notifications')
                      .then((_) => _loadUnreadCount())),
            ),

            // ── Hero Search Card ─────────────────────────────
            SliverToBoxAdapter(
              child: _HeroSearchCard(px: px, pulseCtrl: _pulseCtrl),
            ),

            // ── Quick Actions ────────────────────────────────
            SliverToBoxAdapter(child: _QuickActions(px: px)),

            // ── Stats Banner ─────────────────────────────────
            SliverToBoxAdapter(child: _StatsBanner(px: px)),

            // ── Recommended for You ───────────────────────────
            if (_loadingRec || _recPersonalized.isNotEmpty)
              SliverToBoxAdapter(
                child: _RecommendedSection(
                  px: px,
                  rides: _recPersonalized,
                  loading: _loadingRec,
                ),
              ),

            // ── Trending Routes ───────────────────────────────
            if (!_loadingRec && _recTrending.isNotEmpty)
              SliverToBoxAdapter(
                child: _TrendingSection(px: px, routes: _recTrending),
              ),

            // ── Near You ──────────────────────────────────────
            if (!_loadingRec && _recNearby.isNotEmpty)
              SliverToBoxAdapter(
                child: _NearbySection(px: px, rides: _recNearby),
              ),

            // ── Route Chips ──────────────────────────────────
            SliverToBoxAdapter(
              child: _RouteChips(
                routes: routeLabels,
                selected: _selectedRoute,
                onSelect: _onRouteChip,
                px: px,
              ),
            ),

            // ── Section Header ───────────────────────────────
            SliverToBoxAdapter(
              child: _SectionHeader(
                title: l10n.availableRides,
                px: px,
                onSeeAll: () => context.go('/dashboard'),
              ),
            ),

            // ── Ride Cards ───────────────────────────────────
            if (_loadingRides)
              const SliverToBoxAdapter(
                child: Padding(
                  padding: EdgeInsets.symmetric(vertical: 40),
                  child: Center(
                      child: CircularProgressIndicator(color: _kGold)),
                ),
              )
            else if (_rides.isEmpty)
              SliverToBoxAdapter(
                child: Padding(
                  padding: EdgeInsets.fromLTRB(px, 20, px, 32),
                  child: Container(
                    padding: const EdgeInsets.all(24),
                    decoration: BoxDecoration(
                      color: _kCard,
                      borderRadius: BorderRadius.circular(18),
                      border: Border.all(color: _kBorder),
                    ),
                    child: Column(children: [
                      const Icon(Icons.directions_car_outlined,
                          color: _kMuted, size: 40),
                      const SizedBox(height: 12),
                      Text(
                        l10n.noRidesAvailable,
                        style: const TextStyle(
                          color: _kWhite,
                          fontWeight: FontWeight.w700,
                          fontSize: 15,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        l10n.tryDifferentRoute,
                        style: const TextStyle(color: _kMuted, fontSize: 12),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 16),
                      GestureDetector(
                        onTap: () => context.go('/dashboard'),
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 20, vertical: 10),
                          decoration: BoxDecoration(
                            color: _kGold.withOpacity(0.12),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                                color: _kGold.withOpacity(0.3)),
                          ),
                          child: Text(
                            l10n.searchAllRides,
                            style: const TextStyle(
                              color: _kGold,
                              fontWeight: FontWeight.w700,
                              fontSize: 13,
                            ),
                          ),
                        ),
                      ),
                    ]),
                  ),
                ),
              )
            else
              SliverPadding(
                padding: EdgeInsets.fromLTRB(px, 0, px, 24),
                sliver: SliverList(
                  delegate: SliverChildBuilderDelegate(
                    (context, i) {
                      if (i.isOdd) return const SizedBox(height: 14);
                      final ride = _rides[i ~/ 2];
                      return _LiveRideCard(ride: ride);
                    },
                    childCount: _rides.length * 2 - 1,
                    addAutomaticKeepAlives: false,
                  ),
                ),
              ),
          ],
        ),
      ),
      bottomNavigationBar: _BottomNav(
        currentIndex: _navIndex,
        onTap: (i) {
          if (i == 0) {
            setState(() => _navIndex = 0);
            _loadRides();
            return;
          }
          setState(() => _navIndex = i);
          switch (i) {
            case 1:
              context
                  .push('/dashboard')
                  .then((_) => setState(() => _navIndex = 0));
              break;
            case 2:
              context
                  .push('/feed')
                  .then((_) => setState(() => _navIndex = 0));
              break;
            case 3:
              context
                  .push('/my-rides')
                  .then((_) => setState(() => _navIndex = 0));
              break;
            case 4:
              context
                  .push('/settings')
                  .then((_) => setState(() => _navIndex = 0));
              break;
          }
        },
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Top Bar
// ─────────────────────────────────────────────────────────────────────────────
class _TopBar extends StatelessWidget {
  final double px;
  final int unreadCount;
  final VoidCallback onNotificationTap;
  const _TopBar({required this.px, required this.unreadCount, required this.onNotificationTap});

  String _greeting(AppLocalizations l10n) {
    final h = DateTime.now().hour;
    if (h < 12) return l10n.goodMorning;
    if (h < 17) return l10n.goodAfternoon;
    return l10n.goodEvening;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final auth = context.watch<AuthProvider>();
    final name = auth.user?.username ?? 'User';

    return Padding(
      padding: EdgeInsets.fromLTRB(px, 12, px, 0),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(
                      '${_greeting(l10n)} ',
                      style: const TextStyle(color: _kMuted, fontSize: 13),
                    ),
                    const Text('👋', style: TextStyle(fontSize: 13)),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  name,
                  style: const TextStyle(
                    color: _kWhite,
                    fontSize: 22,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.3,
                  ),
                ),
              ],
            ),
          ),
          _IconBtn(
            icon: Icons.notifications_outlined,
            badge: unreadCount > 0,
            onTap: onNotificationTap,
          ),
          const SizedBox(width: 8),
          _AvatarMenu(username: name),
        ],
      ),
    );
  }
}

class _IconBtn extends StatelessWidget {
  final IconData icon;
  final bool badge;
  final VoidCallback onTap;
  const _IconBtn(
      {required this.icon, this.badge = false, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 42,
        height: 42,
        decoration: BoxDecoration(
          color: _kSurface,
          borderRadius: BorderRadius.circular(13),
          border: Border.all(color: _kBorder),
        ),
        child: Stack(
          alignment: Alignment.center,
          children: [
            Icon(icon, color: _kMuted, size: 22),
            if (badge)
              Positioned(
                top: 9,
                right: 10,
                child: Container(
                  width: 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: _kRed,
                    shape: BoxShape.circle,
                    border: Border.all(color: _kNavy, width: 1.5),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _AvatarMenu extends StatelessWidget {
  final String username;
  const _AvatarMenu({required this.username});

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<String>(
      offset: const Offset(0, 52),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      color: _kSurface,
      elevation: 16,
      shadowColor: Colors.black54,
      child: Container(
        width: 42,
        height: 42,
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [_kGold, _kGoldDark],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(13),
          boxShadow: [
            BoxShadow(
                color: _kGold.withOpacity(0.35),
                blurRadius: 10,
                offset: const Offset(0, 4)),
          ],
        ),
        child: Center(
          child: Text(
            username[0].toUpperCase(),
            style: const TextStyle(
              color: _kNavy,
              fontSize: 17,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
      ),
      onSelected: (val) {
        switch (val) {
          case 'dashboard':
            context.go('/dashboard');
            break;
          case 'profile':
            context.go('/settings');
            break;
          case 'logout':
            context.read<AuthProvider>().logout().then((_) {
              if (context.mounted) context.go('/login');
            });
            break;
        }
      },
      itemBuilder: (ctx) {
        final l10n = AppLocalizations.of(ctx);
        return [
          _menuHeader(username),
          const PopupMenuDivider(height: 1),
          _menuItem('dashboard', Icons.grid_view_rounded, l10n.dashboard,
              _kGold, _kGold.withOpacity(0.15)),
          _menuItem('profile', Icons.person_outline, l10n.editProfile,
              _kMuted, _kMuted.withOpacity(0.12)),
          const PopupMenuDivider(height: 1),
          _menuItem('logout', Icons.logout_rounded, l10n.logout, _kRed,
              _kRed.withOpacity(0.12)),
        ];
      },
    );
  }

  PopupMenuEntry<String> _menuHeader(String name) =>
      PopupMenuItem<String>(
        enabled: false,
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(name,
                style: const TextStyle(
                    color: _kWhite,
                    fontSize: 15,
                    fontWeight: FontWeight.w700)),
            const SizedBox(height: 3),
            Text('${name.toLowerCase()}@forsadrive.tn',
                style: const TextStyle(color: _kMuted, fontSize: 12)),
          ],
        ),
      );

  PopupMenuEntry<String> _menuItem(
    String value,
    IconData icon,
    String label,
    Color color,
    Color bg,
  ) =>
      PopupMenuItem<String>(
        value: value,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        child: Row(
          children: [
            Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                  color: bg, borderRadius: BorderRadius.circular(9)),
              child: Icon(icon, color: color, size: 18),
            ),
            const SizedBox(width: 12),
            Text(
              label,
              style: TextStyle(
                color: value == 'logout' ? _kRed : _kWhite,
                fontSize: 14,
                fontWeight: FontWeight.w500,
              ),
            ),
          ],
        ),
      );
}

// ─────────────────────────────────────────────────────────────────────────────
// Hero Search Card
// ─────────────────────────────────────────────────────────────────────────────
class _HeroSearchCard extends StatelessWidget {
  final double px;
  final AnimationController pulseCtrl;
  const _HeroSearchCard({required this.px, required this.pulseCtrl});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(px, 20, px, 0),
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(24),
          gradient: const LinearGradient(
            colors: [Color(0xFF112240), Color(0xFF0D1B35)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          border: Border.all(color: _kBorder),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withOpacity(0.4),
                blurRadius: 24,
                offset: const Offset(0, 8)),
          ],
        ),
        child: Stack(
          children: [
            Positioned(
              right: -20,
              top: -20,
              child: _GlowCircle(
                  size: 120, color: _kGold.withOpacity(0.07)),
            ),
            Positioned(
              left: -30,
              bottom: -30,
              child: _GlowCircle(
                  size: 100, color: AppTheme.info.withOpacity(0.06)),
            ),
            Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Location pill
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: _kGold.withOpacity(0.12),
                      borderRadius: BorderRadius.circular(20),
                      border:
                          Border.all(color: _kGold.withOpacity(0.3)),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        AnimatedBuilder(
                          animation: pulseCtrl,
                          builder: (_, __) => Container(
                            width: 7,
                            height: 7,
                            decoration: BoxDecoration(
                              color: _kGold,
                              shape: BoxShape.circle,
                              boxShadow: [
                                BoxShadow(
                                  color: _kGold
                                      .withOpacity(0.6 * pulseCtrl.value),
                                  blurRadius: 6,
                                  spreadRadius: 2,
                                ),
                              ],
                            ),
                          ),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          l10n.tunisLocation,
                          style: const TextStyle(
                              color: _kGold,
                              fontSize: 12,
                              fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),

                  Text(
                    l10n.whereAreYouHeading,
                    style: const TextStyle(
                      color: _kWhite,
                      fontSize: 26,
                      fontWeight: FontWeight.w800,
                      height: 1.2,
                      letterSpacing: -0.5,
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Search field
                  GestureDetector(
                    onTap: () => context.go('/dashboard'),
                    child: Container(
                      height: 52,
                      decoration: BoxDecoration(
                        color: const Color(0xFF0A1628),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: _kBorder),
                      ),
                      child: Row(
                        children: [
                          const SizedBox(width: 14),
                          const Icon(Icons.search_rounded,
                              color: _kMuted, size: 20),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              l10n.searchDestination,
                              style: const TextStyle(
                                  color: _kMuted, fontSize: 14),
                            ),
                          ),
                          Padding(
                            padding: const EdgeInsets.all(8),
                            child: Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(
                                gradient: const LinearGradient(
                                  colors: [_kGold, _kGoldDark],
                                ),
                                borderRadius: BorderRadius.circular(10),
                                boxShadow: [
                                  BoxShadow(
                                      color: _kGold.withOpacity(0.4),
                                      blurRadius: 8,
                                      offset: const Offset(0, 3)),
                                ],
                              ),
                              child: const Icon(Icons.tune_rounded,
                                  color: _kNavy, size: 18),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _GlowCircle extends StatelessWidget {
  final double size;
  final Color color;
  const _GlowCircle({required this.size, required this.color});

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(shape: BoxShape.circle, color: color),
      );
}

// ─────────────────────────────────────────────────────────────────────────────
// Quick Actions
// ─────────────────────────────────────────────────────────────────────────────
class _QuickActions extends StatelessWidget {
  final double px;
  const _QuickActions({required this.px});

  List<_ActionData> _buildActions(AppLocalizations l10n) => [
        _ActionData(Icons.directions_car_rounded, l10n.findRide, _kGold,
            const Color(0xFFF0C060), '/dashboard'),
        _ActionData(Icons.add_road_rounded, l10n.offerRide,
            const Color(0xFF6366F1), const Color(0xFF818CF8), '/offer-ride'),
        _ActionData(Icons.history_rounded, l10n.myTrips,
            const Color(0xFF14B8A6), const Color(0xFF2DD4BF), '/my-rides'),
        _ActionData(Icons.account_balance_wallet_rounded, l10n.payments,
            const Color(0xFFEC4899), const Color(0xFFF472B6), '/payments'),
      ];

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final isDriver = context.watch<AuthProvider>().isDriver;
    final allActions = _buildActions(l10n);
    final actions = isDriver
        ? allActions
        : allActions.where((a) => a.route != '/offer-ride').toList();
    return Padding(
      padding: EdgeInsets.fromLTRB(px, 24, px, 0),
      child: Row(
        children: actions
            .map((a) => Expanded(
                  child: Padding(
                    padding: EdgeInsets.only(
                        right: a == actions.last ? 0 : 10),
                    child: _ActionTile(data: a),
                  ),
                ))
            .toList(),
      ),
    );
  }
}

class _ActionData {
  final IconData icon;
  final String label;
  final Color color;
  final Color light;
  final String route;
  const _ActionData(
      this.icon, this.label, this.color, this.light, this.route);
}

class _ActionTile extends StatelessWidget {
  final _ActionData data;
  const _ActionTile({required this.data});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => context.go(data.route),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 16),
        decoration: BoxDecoration(
          color: _kCard,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: _kBorder),
        ),
        child: Column(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: data.color.withOpacity(0.15),
                borderRadius: BorderRadius.circular(13),
              ),
              child: Icon(data.icon, color: data.color, size: 22),
            ),
            const SizedBox(height: 10),
            Text(
              data.label,
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: _kWhite,
                fontSize: 11,
                fontWeight: FontWeight.w600,
                height: 1.3,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Stats Banner
// ─────────────────────────────────────────────────────────────────────────────
class _StatsBanner extends StatelessWidget {
  final double px;
  const _StatsBanner({required this.px});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(px, 20, px, 0),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 8),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [
              _kGold.withOpacity(0.12),
              _kGold.withOpacity(0.04)
            ],
            begin: Alignment.centerLeft,
            end: Alignment.centerRight,
          ),
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: _kGold.withOpacity(0.2)),
        ),
        child: Row(
          children: [
            _StatItem(value: '12K+', label: l10n.statDrivers),
            const _StatDivider(),
            _StatItem(value: '98%', label: l10n.statOnTime),
            const _StatDivider(),
            _StatItem(value: '4.8★', label: l10n.statRating),
            const _StatDivider(),
            _StatItem(value: '50K', label: l10n.statRides),
          ],
        ),
      ),
    );
  }
}

class _StatItem extends StatelessWidget {
  final String value;
  final String label;
  const _StatItem({required this.value, required this.label});

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(
          children: [
            Text(
              value,
              style: const TextStyle(
                color: _kGold,
                fontSize: 16,
                fontWeight: FontWeight.w800,
                letterSpacing: -0.3,
              ),
            ),
            const SizedBox(height: 3),
            Text(label,
                style: const TextStyle(color: _kMuted, fontSize: 11)),
          ],
        ),
      );
}

class _StatDivider extends StatelessWidget {
  const _StatDivider();

  @override
  Widget build(BuildContext context) => Container(
        width: 1, height: 28, color: _kGold.withOpacity(0.2));
}

// ─────────────────────────────────────────────────────────────────────────────
// Route Chips
// ─────────────────────────────────────────────────────────────────────────────
class _RouteChips extends StatelessWidget {
  final List<String> routes;
  final int selected;
  final ValueChanged<int> onSelect;
  final double px;
  const _RouteChips({
    required this.routes,
    required this.selected,
    required this.onSelect,
    required this.px,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 44,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.fromLTRB(px, 0, px, 0),
        itemCount: routes.length,
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (_, i) {
          final active = i == selected;
          return GestureDetector(
            onTap: () => onSelect(i),
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              padding: const EdgeInsets.symmetric(horizontal: 16),
              decoration: BoxDecoration(
                color: active ? _kGold : _kCard,
                borderRadius: BorderRadius.circular(22),
                border: Border.all(
                    color: active ? _kGold : _kBorder),
                boxShadow: active
                    ? [
                        BoxShadow(
                            color: _kGold.withOpacity(0.3),
                            blurRadius: 10,
                            offset: const Offset(0, 3))
                      ]
                    : [],
              ),
              child: Center(
                child: Text(
                  routes[i],
                  style: TextStyle(
                    color: active ? _kNavy : _kMuted,
                    fontSize: 12.5,
                    fontWeight: active
                        ? FontWeight.w700
                        : FontWeight.w500,
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Section Header
// ─────────────────────────────────────────────────────────────────────────────
class _SectionHeader extends StatelessWidget {
  final String title;
  final double px;
  final VoidCallback onSeeAll;
  const _SectionHeader(
      {required this.title, required this.px, required this.onSeeAll});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(px, 22, px, 12),
      child: Row(
        children: [
          Expanded(
            child: Text(
              title,
              style: const TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: _kWhite,
                letterSpacing: -0.3,
              ),
            ),
          ),
          GestureDetector(
            onTap: onSeeAll,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              decoration: BoxDecoration(
                color: _kGold.withOpacity(0.12),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: _kGold.withOpacity(0.25)),
              ),
              child: Text(
                l10n.seeAll,
                style: const TextStyle(
                    fontSize: 12,
                    color: _kGold,
                    fontWeight: FontWeight.w600),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Live Ride Card
// ─────────────────────────────────────────────────────────────────────────────
class _LiveRideCard extends StatelessWidget {
  final RideModel ride;
  const _LiveRideCard({required this.ride});

  static const _avatarColors = [
    Color(0xFF6366F1),
    Color(0xFFEC4899),
    Color(0xFF14B8A6),
    Color(0xFFF59E0B),
    Color(0xFF8B5CF6),
    Color(0xFF06B6D4),
  ];

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final name = ride.driverName ?? 'Driver';
    final score = ride.driverScore ?? 5.0;
    final seatsLeft = ride.seatsLeft ?? ride.availableSeats;
    final urgency = seatsLeft <= 2;
    final dateStr = DateFormat('MMM d').format(ride.departureTime);
    final timeStr = DateFormat('HH:mm').format(ride.departureTime);
    final colorIndex = name.codeUnitAt(0) % _avatarColors.length;
    final avatarColor = _avatarColors[colorIndex];

    return GestureDetector(
      onTap: () => context.push('/ride/${ride.id}'),
      child: Container(
        decoration: BoxDecoration(
          color: _kCard,
          borderRadius: BorderRadius.circular(22),
          border: Border.all(color: _kBorder),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withOpacity(0.25),
                blurRadius: 16,
                offset: const Offset(0, 6))
          ],
        ),
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  // Driver row
                  Row(
                    children: [
                      Container(
                        width: 46,
                        height: 46,
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            colors: [
                              avatarColor,
                              avatarColor.withOpacity(0.7)
                            ],
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                          ),
                          borderRadius: BorderRadius.circular(14),
                          boxShadow: [
                            BoxShadow(
                                color: avatarColor.withOpacity(0.35),
                                blurRadius: 10,
                                offset: const Offset(0, 4))
                          ],
                        ),
                        child: Center(
                          child: Text(
                            name[0].toUpperCase(),
                            style: const TextStyle(
                                color: _kWhite,
                                fontSize: 18,
                                fontWeight: FontWeight.w800),
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              name,
                              style: const TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w700,
                                  color: _kWhite),
                              overflow: TextOverflow.ellipsis,
                            ),
                            const SizedBox(height: 4),
                            Row(children: [
                              const Icon(Icons.star_rounded,
                                  color: _kGold, size: 14),
                              const SizedBox(width: 3),
                              Text(
                                score.toStringAsFixed(1),
                                style: const TextStyle(
                                    color: _kGold,
                                    fontSize: 12,
                                    fontWeight: FontWeight.w600),
                              ),
                            ]),
                          ],
                        ),
                      ),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(
                            '${ride.price.toStringAsFixed(2)} TND',
                            style: const TextStyle(
                                fontSize: 19,
                                fontWeight: FontWeight.w800,
                                color: _kGold,
                                letterSpacing: -0.5),
                          ),
                          Text(
                            l10n.perSeat,
                            style: const TextStyle(
                                fontSize: 10, color: _kMuted),
                          ),
                        ],
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  // Route visual
                  IntrinsicHeight(
                    child: Row(
                      children: [
                        Column(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            _RouteDot(color: _kGreen),
                            Expanded(
                              child: Container(
                                width: 2,
                                margin: const EdgeInsets.symmetric(
                                    vertical: 6),
                                decoration: BoxDecoration(
                                  gradient: LinearGradient(
                                    colors: [_kGreen, _kRed],
                                    begin: Alignment.topCenter,
                                    end: Alignment.bottomCenter,
                                  ),
                                  borderRadius: BorderRadius.circular(1),
                                ),
                              ),
                            ),
                            _RouteDot(color: _kRed),
                          ],
                        ),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisAlignment:
                                MainAxisAlignment.spaceBetween,
                            children: [
                              _RouteLabel(
                                  text: ride.fromLocation,
                                  sub: l10n.departure),
                              const SizedBox(height: 16),
                              _RouteLabel(
                                  text: ride.toLocation,
                                  sub: l10n.arrival),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            // Footer
            Container(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
              decoration: BoxDecoration(
                color: const Color(0xFF0A1628).withOpacity(0.5),
                borderRadius: const BorderRadius.only(
                  bottomLeft: Radius.circular(22),
                  bottomRight: Radius.circular(22),
                ),
              ),
              child: Row(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: _kSurface,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: _kBorder),
                    ),
                    child: Row(children: [
                      const Icon(Icons.calendar_today_rounded,
                          size: 13, color: _kMuted),
                      const SizedBox(width: 5),
                      Text(dateStr,
                          style: const TextStyle(
                              fontSize: 12,
                              color: _kMuted,
                              fontWeight: FontWeight.w500)),
                      const SizedBox(width: 6),
                      const Icon(Icons.access_time_rounded,
                          size: 13, color: _kMuted),
                      const SizedBox(width: 5),
                      Text(timeStr,
                          style: const TextStyle(
                              fontSize: 12,
                              color: _kMuted,
                              fontWeight: FontWeight.w500)),
                    ]),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 9, vertical: 6),
                    decoration: BoxDecoration(
                      color: urgency
                          ? _kRed.withOpacity(0.1)
                          : _kGreen.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(
                          color: (urgency ? _kRed : _kGreen)
                              .withOpacity(0.3)),
                    ),
                    child: Text(
                      l10n.seatsLeft(seatsLeft),
                      style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: urgency ? _kRed : _kGreen),
                    ),
                  ),
                  const Spacer(),
                  GestureDetector(
                    onTap: () => context.push('/ride/${ride.id}'),
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 18, vertical: 8),
                      decoration: BoxDecoration(
                        gradient: const LinearGradient(
                            colors: [_kGold, _kGoldDark]),
                        borderRadius: BorderRadius.circular(12),
                        boxShadow: [
                          BoxShadow(
                              color: _kGold.withOpacity(0.35),
                              blurRadius: 10,
                              offset: const Offset(0, 4))
                        ],
                      ),
                      child: Text(
                        l10n.book,
                        style: const TextStyle(
                            color: _kNavy,
                            fontSize: 13,
                            fontWeight: FontWeight.w800),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RouteDot extends StatelessWidget {
  final Color color;
  const _RouteDot({required this.color});

  @override
  Widget build(BuildContext context) => Container(
        width: 11,
        height: 11,
        decoration: BoxDecoration(
          color: color,
          shape: BoxShape.circle,
          boxShadow: [
            BoxShadow(
                color: color.withOpacity(0.5),
                blurRadius: 6,
                spreadRadius: 1)
          ],
        ),
      );
}

class _RouteLabel extends StatelessWidget {
  final String text;
  final String sub;
  const _RouteLabel({required this.text, required this.sub});

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(sub,
              style: const TextStyle(fontSize: 10, color: _kMuted)),
          const SizedBox(height: 2),
          Text(text,
              style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  color: _kWhite)),
        ],
      );
}

// ─────────────────────────────────────────────────────────────────────────────
// Recommended for You Section
// ─────────────────────────────────────────────────────────────────────────────
class _RecommendedSection extends StatelessWidget {
  final double px;
  final List<RideModel> rides;
  final bool loading;
  const _RecommendedSection(
      {required this.px, required this.rides, required this.loading});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(px, 22, px, 10),
          child: Row(
            children: [
              Container(
                width: 4,
                height: 18,
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [_kGold, _kGoldDark],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(width: 8),
              const Text(
                'Recommended for You',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: _kWhite,
                  letterSpacing: -0.3,
                ),
              ),
              const SizedBox(width: 6),
              const Text('🤖', style: TextStyle(fontSize: 13)),
            ],
          ),
        ),
        SizedBox(
          height: 164,
          child: loading
              ? const Center(
                  child: CircularProgressIndicator(
                    color: _kGold,
                    strokeWidth: 2,
                  ),
                )
              : ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: EdgeInsets.fromLTRB(px, 0, px, 0),
                  itemCount: rides.length,
                  separatorBuilder: (_, __) => const SizedBox(width: 12),
                  itemBuilder: (_, i) => _MiniRideCard(
                    ride: rides[i],
                    accentColor: _kGold,
                  ),
                ),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Trending Routes Section
// ─────────────────────────────────────────────────────────────────────────────
class _TrendingSection extends StatelessWidget {
  final double px;
  final List<Map<String, dynamic>> routes;
  const _TrendingSection({required this.px, required this.routes});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(px, 22, px, 10),
          child: Row(
            children: [
              Container(
                width: 4,
                height: 18,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      const Color(0xFFEC4899),
                      const Color(0xFFF472B6)
                    ],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(width: 8),
              const Text(
                'Trending Routes',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: _kWhite,
                  letterSpacing: -0.3,
                ),
              ),
              const SizedBox(width: 6),
              const Text('🔥', style: TextStyle(fontSize: 13)),
            ],
          ),
        ),
        SizedBox(
          height: 48,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.fromLTRB(px, 0, px, 0),
            itemCount: routes.length,
            separatorBuilder: (_, __) => const SizedBox(width: 8),
            itemBuilder: (_, i) {
              final r = routes[i];
              final from = r['from_location'] as String? ?? '';
              final to = r['to_location'] as String? ?? '';
              final count = r['booking_count'] as int? ?? 0;
              final minPrice = (r['min_price'] as num?)?.toDouble() ?? 0;
              return GestureDetector(
                onTap: () => context.go(
                  '/dashboard',
                ),
                child: Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 14, vertical: 0),
                  decoration: BoxDecoration(
                    color: _kCard,
                    borderRadius: BorderRadius.circular(24),
                    border: Border.all(color: _kBorder),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.trending_up_rounded,
                          color: Color(0xFFEC4899), size: 14),
                      const SizedBox(width: 6),
                      Text(
                        '$from → $to',
                        style: const TextStyle(
                          color: _kWhite,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 7, vertical: 2),
                        decoration: BoxDecoration(
                          color:
                              const Color(0xFFEC4899).withOpacity(0.15),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Text(
                          '${count}x  •  from ${minPrice.toStringAsFixed(1)} TND',
                          style: const TextStyle(
                            color: Color(0xFFEC4899),
                            fontSize: 10,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Near You Section
// ─────────────────────────────────────────────────────────────────────────────
class _NearbySection extends StatelessWidget {
  final double px;
  final List<RideModel> rides;
  const _NearbySection({required this.px, required this.rides});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(px, 22, px, 10),
          child: Row(
            children: [
              Container(
                width: 4,
                height: 18,
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [Color(0xFF14B8A6), Color(0xFF2DD4BF)],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(width: 8),
              const Text(
                'Near You',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: _kWhite,
                  letterSpacing: -0.3,
                ),
              ),
              const SizedBox(width: 6),
              const Text('📍', style: TextStyle(fontSize: 13)),
            ],
          ),
        ),
        SizedBox(
          height: 164,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.fromLTRB(px, 0, px, 0),
            itemCount: rides.length,
            separatorBuilder: (_, __) => const SizedBox(width: 12),
            itemBuilder: (_, i) => _MiniRideCard(
              ride: rides[i],
              accentColor: const Color(0xFF14B8A6),
            ),
          ),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Mini Ride Card (used in horizontal scrolling sections)
// ─────────────────────────────────────────────────────────────────────────────
class _MiniRideCard extends StatelessWidget {
  final RideModel ride;
  final Color accentColor;
  const _MiniRideCard({required this.ride, required this.accentColor});

  @override
  Widget build(BuildContext context) {
    final name = ride.driverName ?? 'Driver';
    final seatsLeft = ride.seatsLeft ?? ride.availableSeats;
    final timeStr = DateFormat('MMM d  HH:mm').format(ride.departureTime);

    return GestureDetector(
      onTap: () => context.push('/ride/${ride.id}'),
      child: Container(
        width: 220,
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: _kCard,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: _kBorder),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.2),
              blurRadius: 12,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Route
            Row(
              children: [
                Icon(Icons.trip_origin,
                    size: 10, color: _kGreen),
                const SizedBox(width: 5),
                Expanded(
                  child: Text(
                    ride.fromLocation,
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: _kWhite,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.only(left: 4),
              child: Container(width: 2, height: 12, color: _kBorder),
            ),
            Row(
              children: [
                const Icon(Icons.location_on,
                    size: 10, color: _kRed),
                const SizedBox(width: 5),
                Expanded(
                  child: Text(
                    ride.toLocation,
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: _kWhite,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            // Price + seats
            Row(
              children: [
                Text(
                  '${ride.price.toStringAsFixed(1)} TND',
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: accentColor,
                  ),
                ),
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 7, vertical: 2),
                  decoration: BoxDecoration(
                    color: (seatsLeft <= 2 ? _kRed : _kGreen)
                        .withOpacity(0.12),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    '$seatsLeft left',
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w700,
                      color: seatsLeft <= 2 ? _kRed : _kGreen,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            // Driver + time
            Row(
              children: [
                Container(
                  width: 22,
                  height: 22,
                  decoration: BoxDecoration(
                    color: accentColor.withOpacity(0.2),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Center(
                    child: Text(
                      name[0].toUpperCase(),
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        color: accentColor,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 5),
                Expanded(
                  child: Text(
                    name,
                    style: const TextStyle(
                        fontSize: 11,
                        color: _kMuted,
                        fontWeight: FontWeight.w500),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 5),
            Row(
              children: [
                const Icon(Icons.access_time_rounded,
                    size: 11, color: _kMuted),
                const SizedBox(width: 3),
                Text(
                  timeStr,
                  style:
                      const TextStyle(fontSize: 10, color: _kMuted),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Bottom Navigation
// ─────────────────────────────────────────────────────────────────────────────
class _BottomNav extends StatelessWidget {
  final int currentIndex;
  final ValueChanged<int> onTap;
  const _BottomNav({required this.currentIndex, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF060E1A),
        border: Border(top: BorderSide(color: _kBorder, width: 1)),
        boxShadow: [
          BoxShadow(
              color: Colors.black.withOpacity(0.4),
              blurRadius: 20,
              offset: const Offset(0, -4)),
        ],
      ),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 60,
          child: Row(
            children: [
              _NavItem(
                  icon: Icons.home_rounded,
                  label: l10n.home,
                  index: 0,
                  current: currentIndex,
                  onTap: onTap),
              _NavItem(
                  icon: Icons.search_rounded,
                  label: l10n.search,
                  index: 1,
                  current: currentIndex,
                  onTap: onTap),
              _NavItem(
                  icon: Icons.dynamic_feed_rounded,
                  label: 'Feed',
                  index: 2,
                  current: currentIndex,
                  onTap: onTap),
              _NavItem(
                  icon: Icons.directions_car_rounded,
                  label: l10n.rides,
                  index: 3,
                  current: currentIndex,
                  onTap: onTap),
              _NavItem(
                  icon: Icons.person_outline_rounded,
                  label: l10n.account,
                  index: 4,
                  current: currentIndex,
                  onTap: onTap),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final int index;
  final int current;
  final ValueChanged<int> onTap;
  const _NavItem({
    required this.icon,
    required this.label,
    required this.index,
    required this.current,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final active = index == current;
    return Expanded(
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: () => onTap(index),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
              decoration: BoxDecoration(
                color: active
                    ? _kGold.withOpacity(0.12)
                    : Colors.transparent,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(icon,
                  color: active ? _kGold : _kMuted, size: 22),
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: TextStyle(
                fontSize: 10,
                color: active ? _kGold : _kMuted,
                fontWeight:
                    active ? FontWeight.w700 : FontWeight.w400,
              ),
            ),
          ],
        ),
      ),
    );
  }
}