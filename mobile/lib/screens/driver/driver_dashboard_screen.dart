import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart' hide TextDirection;
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../services/ride_service.dart';
import '../../models/ride_model.dart';
import '../../utils/app_theme.dart';
import '../../widgets/boost_tier_picker.dart';

// ── Palette ───────────────────────────────────────────────────────────────────
const _kNavy    = AppTheme.primary;
const _kCard    = AppTheme.darkCard;
const _kSurface = AppTheme.darkSurface;
const _kBorder  = Color(0xFF1E3A5F);
const _kGold    = AppTheme.accent;
const _kGreen   = AppTheme.success;
const _kRed     = AppTheme.danger;
const _kMuted   = Color(0xFF8A9BB5);
const _kWhite   = Colors.white;

class DriverDashboardScreen extends StatefulWidget {
  const DriverDashboardScreen({super.key});
  @override
  State<DriverDashboardScreen> createState() => _DriverDashboardScreenState();
}

class _DriverDashboardScreenState extends State<DriverDashboardScreen>
    with SingleTickerProviderStateMixin {
  // Analytics data
  Map<String, dynamic>? _analytics;
  bool _loadingAnalytics = false;

  // Rides / requests
  List<RideModel> _rides = [];
  List<dynamic> _requests = [];
  bool _loadingRides = false;

  late TabController _tabCtrl;

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: 2, vsync: this);
    _loadAnalytics();
    _loadRides();
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadAnalytics() async {
    setState(() => _loadingAnalytics = true);
    try {
      final res = await context.read<ApiService>().get('/analytics/driver');
      if (mounted) setState(() => _analytics = res);
    } catch (_) {
    } finally {
      if (mounted) setState(() => _loadingAnalytics = false);
    }
  }

  Future<void> _loadRides() async {
    setState(() => _loadingRides = true);
    try {
      final svc = context.read<RideService>();
      final results = await Future.wait([
        svc.getMyRides(asDriver: true),
        svc.getBookingRequests(),
      ]);
      if (mounted) {
        setState(() {
          _rides = results[0] as List<RideModel>;
          _requests = results[1] as List<dynamic>;
        });
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: _kRed),
        );
      }
    } finally {
      if (mounted) setState(() => _loadingRides = false);
    }
  }

  Future<void> _refreshAll() async {
    await Future.wait([_loadAnalytics(), _loadRides()]);
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user!;

    return Scaffold(
      backgroundColor: _kNavy,
      appBar: AppBar(
        backgroundColor: AppTheme.primaryDark,
        foregroundColor: _kWhite,
        elevation: 0,
        title: const Text(
          'Driver Dashboard',
          style: TextStyle(fontWeight: FontWeight.w800),
        ),
        actions: [
          TextButton.icon(
            onPressed: () => context.go('/offer-ride'),
            icon: const Icon(Icons.add_circle_outline_rounded, color: _kGold, size: 18),
            label: const Text('New Ride', style: TextStyle(color: _kGold, fontWeight: FontWeight.w700)),
          ),
        ],
        bottom: TabBar(
          controller: _tabCtrl,
          indicatorColor: _kGold,
          labelColor: _kGold,
          unselectedLabelColor: _kMuted,
          labelStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
          tabs: const [
            Tab(icon: Icon(Icons.analytics_rounded, size: 18), text: 'Analytics'),
            Tab(icon: Icon(Icons.directions_car_rounded, size: 18), text: 'Rides'),
          ],
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _refreshAll,
        color: _kGold,
        backgroundColor: _kCard,
        child: TabBarView(
          controller: _tabCtrl,
          children: [
            _AnalyticsTab(
              user: user,
              analytics: _analytics,
              loading: _loadingAnalytics,
            ),
            _RidesTab(
              rides: _rides,
              requests: _requests,
              loading: _loadingRides,
              onRefresh: _loadRides,
              onRespond: (id, action) async {
                await context.read<RideService>().respondToBooking(id, action);
                _loadRides();
              },
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Analytics Tab
// ─────────────────────────────────────────────────────────────────────────────
class _AnalyticsTab extends StatelessWidget {
  final dynamic user;
  final Map<String, dynamic>? analytics;
  final bool loading;
  const _AnalyticsTab({required this.user, required this.analytics, required this.loading});

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Center(child: CircularProgressIndicator(color: _kGold));
    }
    if (analytics == null) {
      return const Center(
        child: Text('Analytics unavailable', style: TextStyle(color: _kMuted)),
      );
    }

    final stats          = analytics!['stats'] as Map<String, dynamic>;
    final dist           = List<Map<String, dynamic>>.from(analytics!['rating_distribution'] as List);
    final ratingTrend    = List<Map<String, dynamic>>.from(analytics!['rating_trend'] as List);
    final revenueTrend   = List<Map<String, dynamic>>.from(analytics!['revenue_trend'] as List);
    final reliability    = analytics!['reliability'] as Map<String, dynamic>;
    final recommendations = List<Map<String, dynamic>>.from(analytics!['recommendations'] as List);

    final completionRate  = (stats['completion_rate'] as num?)?.toDouble() ?? 0.0;
    final totalRevenue    = (stats['total_revenue'] as num?)?.toDouble() ?? 0.0;
    final avgRating       = (stats['avg_rating'] as num?)?.toDouble() ?? 5.0;
    final completedRides  = stats['completed_rides'] as int? ?? 0;
    final totalRides      = stats['total_rides'] as int? ?? 0;
    final pendingRequests = stats['pending_requests'] as int? ?? 0;
    final reliabilityScore = reliability['score'] as int? ?? 0;

    return SingleChildScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Reliability Score Hero ───────────────────────────
          _ReliabilityHero(
            user: user,
            reliability: reliability,
            avgRating: avgRating,
            completionRate: completionRate,
          ),
          const SizedBox(height: 16),

          // ── KPI grid ─────────────────────────────────────────
          _KpiGrid(
            completionRate: completionRate,
            totalRevenue: totalRevenue,
            completedRides: completedRides,
            totalRides: totalRides,
            avgRating: avgRating,
            pendingRequests: pendingRequests,
          ),
          const SizedBox(height: 20),

          // ── Rating distribution ───────────────────────────────
          _sectionLabel('Rating Distribution', Icons.bar_chart_rounded),
          const SizedBox(height: 10),
          _RatingDistChart(distribution: dist),
          const SizedBox(height: 20),

          // ── 6-month rating trend ──────────────────────────────
          if (ratingTrend.isNotEmpty) ...[
            _sectionLabel('6-Month Rating Trend', Icons.show_chart_rounded),
            const SizedBox(height: 10),
            _LineChart(
              points: ratingTrend.map((r) => _ChartPoint(
                label: _shortMonth(r['month'] as String? ?? ''),
                value: (r['avg_score'] as num?)?.toDouble() ?? 0,
              )).toList(),
              maxValue: 5.0,
              color: _kGold,
              unit: '★',
            ),
            const SizedBox(height: 20),
          ],

          // ── Monthly revenue ───────────────────────────────────
          if (revenueTrend.isNotEmpty) ...[
            _sectionLabel('Monthly Revenue', Icons.payments_rounded),
            const SizedBox(height: 10),
            _BarChart(
              bars: revenueTrend.map((r) => _ChartPoint(
                label: _shortMonth(r['month'] as String? ?? ''),
                value: (r['revenue'] as num?)?.toDouble() ?? 0,
              )).toList(),
              color: _kGreen,
              unit: ' TND',
            ),
            const SizedBox(height: 20),
          ],

          // ── Smart recommendations ─────────────────────────────
          _sectionLabel('Smart Recommendations', Icons.lightbulb_rounded),
          const SizedBox(height: 10),
          ...recommendations.map((r) => _RecommendationCard(rec: r)),

          // ── Quick actions ─────────────────────────────────────
          const SizedBox(height: 8),
          _sectionLabel('Quick Actions', Icons.bolt_rounded),
          const SizedBox(height: 10),
          _QuickActions(pendingCount: pendingRequests),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}

Widget _sectionLabel(String title, IconData icon) => Row(children: [
  Icon(icon, color: _kGold, size: 16),
  const SizedBox(width: 8),
  Text(
    title.toUpperCase(),
    style: const TextStyle(
      color: _kGold,
      fontSize: 11,
      fontWeight: FontWeight.w700,
      letterSpacing: 0.8,
    ),
  ),
]);

String _shortMonth(String ym) {
  // ym = "2025-11" → "Nov"
  try {
    final parts = ym.split('-');
    final dt = DateTime(int.parse(parts[0]), int.parse(parts[1]));
    return DateFormat('MMM').format(dt);
  } catch (_) {
    return ym;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Reliability Score Hero — the primary card in the analytics tab
// ─────────────────────────────────────────────────────────────────────────────
class _ReliabilityHero extends StatelessWidget {
  final dynamic user;
  final Map<String, dynamic> reliability;
  final double avgRating;
  final double completionRate;

  const _ReliabilityHero({
    required this.user,
    required this.reliability,
    required this.avgRating,
    required this.completionRate,
  });

  static const _tiers = [
    (0,  59,  'Bronze',   Color(0xFFCD7F32), Icons.shield_outlined),
    (60, 74,  'Silver',   Color(0xFFC0C0C0), Icons.shield_rounded),
    (75, 89,  'Gold',     Color(0xFFFFD700), Icons.verified_rounded),
    (90, 100, 'Platinum', Color(0xFF4FC3F7), Icons.workspace_premium_rounded),
  ];

  (String, Color, IconData) _tier(int score) {
    for (final t in _tiers) {
      if (score >= t.$1 && score <= t.$2) return (t.$3, t.$4, t.$5);
    }
    return ('Bronze', const Color(0xFFCD7F32), Icons.shield_outlined);
  }

  @override
  Widget build(BuildContext context) {
    final name    = user.username as String;
    final score   = reliability['score'] as int? ?? 0;
    final comp    = reliability['comp_component']     as int? ?? 0;
    final rating  = reliability['rating_component']   as int? ?? 0;
    final act     = reliability['activity_component'] as int? ?? 0;
    final resp    = reliability['response_component'] as int? ?? 0;
    final (tierLabel, tierColor, tierIcon) = _tier(score);

    final scoreColor = score >= 80 ? _kGreen : score >= 60 ? _kGold : _kRed;

    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            scoreColor.withOpacity(0.12),
            const Color(0xFF0D1B35),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: scoreColor.withOpacity(0.35)),
        boxShadow: [
          BoxShadow(color: scoreColor.withOpacity(0.15), blurRadius: 20, offset: const Offset(0, 8)),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            // ── Top row: driver name + tier badge ─────────��────
            Row(children: [
              // Avatar
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  gradient: const LinearGradient(colors: [_kGold, AppTheme.accentDark]),
                  borderRadius: BorderRadius.circular(14),
                  boxShadow: [BoxShadow(color: _kGold.withOpacity(0.35), blurRadius: 10, offset: const Offset(0, 4))],
                ),
                child: Center(
                  child: Text(name[0].toUpperCase(),
                      style: const TextStyle(color: _kNavy, fontSize: 20, fontWeight: FontWeight.w900)),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(name, style: const TextStyle(color: _kWhite, fontSize: 16, fontWeight: FontWeight.w800)),
                const SizedBox(height: 3),
                Row(children: [
                  ...List.generate(5, (i) {
                    final filled = i < avgRating.floor();
                    final half   = !filled && i < avgRating.ceil() &&
                        (avgRating - avgRating.floor()) >= 0.5;
                    return Icon(
                      half ? Icons.star_half_rounded
                           : (filled ? Icons.star_rounded : Icons.star_outline_rounded),
                      color: _kGold, size: 14,
                    );
                  }),
                  const SizedBox(width: 5),
                  Text(avgRating.toStringAsFixed(1),
                      style: const TextStyle(color: _kGold, fontSize: 12, fontWeight: FontWeight.w600)),
                ]),
              ])),
              // Tier badge
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: tierColor.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: tierColor.withOpacity(0.4)),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(tierIcon, color: tierColor, size: 14),
                  const SizedBox(width: 5),
                  Text(tierLabel,
                      style: TextStyle(color: tierColor, fontSize: 12, fontWeight: FontWeight.w800)),
                ]),
              ),
            ]),

            const SizedBox(height: 24),

            // ── Circular gauge + score ─────────────────────────
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                SizedBox(
                  width: 180,
                  height: 130,
                  child: CustomPaint(
                    painter: _ArcGaugePainter(
                      score: score,
                      color: scoreColor,
                      trackColor: _kBorder,
                    ),
                    child: Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const SizedBox(height: 16),
                          Text(
                            '$score',
                            style: TextStyle(
                              color: scoreColor,
                              fontSize: 42,
                              fontWeight: FontWeight.w900,
                              height: 1,
                            ),
                          ),
                          Text(
                            'out of 100',
                            style: TextStyle(
                                color: scoreColor.withOpacity(0.6),
                                fontSize: 11),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'RELIABILITY SCORE',
                            style: const TextStyle(
                              color: _kMuted,
                              fontSize: 9,
                              fontWeight: FontWeight.w700,
                              letterSpacing: 0.8,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),

            const SizedBox(height: 20),

            // ── Formula breakdown rows ─────────────────────────
            const Divider(color: _kBorder, height: 1),
            const SizedBox(height: 14),
            _FormulaRow('Completion Rate', comp, 40, Icons.check_circle_rounded, _kGreen,
                '${completionRate.round()}% of rides completed'),
            _FormulaRow('Average Rating', rating, 30, Icons.star_rounded, _kGold,
                '${avgRating.toStringAsFixed(1)} ★ out of 5.0'),
            _FormulaRow('Activity Level', act, 20, Icons.directions_car_rounded, AppTheme.info,
                '${reliability['completed_bookings'] ?? 0} completed trips'),
            _FormulaRow('Response Speed', resp, 10, Icons.flash_on_rounded,
                const Color(0xFF8B5CF6),
                '${reliability['total_bookings'] ?? 0} total bookings handled',
                isLast: true),
          ],
        ),
      ),
    );
  }
}

Widget _FormulaRow(
  String label,
  int score,
  int maxScore,
  IconData icon,
  Color color,
  String sub, {
  bool isLast = false,
}) {
  return Padding(
    padding: EdgeInsets.only(bottom: isLast ? 0 : 10),
    child: Row(children: [
      Container(
        width: 30, height: 30,
        decoration: BoxDecoration(color: color.withOpacity(0.15), borderRadius: BorderRadius.circular(8)),
        child: Icon(icon, color: color, size: 15),
      ),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Text(label,
                style: const TextStyle(color: _kWhite, fontSize: 12, fontWeight: FontWeight.w600)),
          ),
          Text('$score / $maxScore',
              style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700)),
        ]),
        const SizedBox(height: 4),
        Stack(children: [
          Container(height: 5, decoration: BoxDecoration(color: _kBorder, borderRadius: BorderRadius.circular(3))),
          FractionallySizedBox(
            widthFactor: (score / maxScore).clamp(0.0, 1.0),
            child: Container(
              height: 5,
              decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(3)),
            ),
          ),
        ]),
        const SizedBox(height: 2),
        Text(sub, style: const TextStyle(color: _kMuted, fontSize: 10)),
      ])),
    ]),
  );
}

// Arc gauge painter — draws a 240° arc from bottom-left to bottom-right
class _ArcGaugePainter extends CustomPainter {
  final int score;
  final Color color;
  final Color trackColor;
  const _ArcGaugePainter({required this.score, required this.color, required this.trackColor});

  @override
  void paint(Canvas canvas, Size size) {
    const startAngle = math.pi * 0.75;  // 135°
    const sweepMax   = math.pi * 1.5;   // 270° sweep

    final cx = size.width / 2;
    final cy = size.height * 0.72;
    final radius = math.min(cx, cy) * 0.92;
    final rect = Rect.fromCircle(center: Offset(cx, cy), radius: radius);

    final trackPaint = Paint()
      ..color = trackColor
      ..strokeWidth = 12
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;

    final fillPaint = Paint()
      ..color = color
      ..strokeWidth = 12
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..maskFilter = MaskFilter.blur(BlurStyle.solid, 1);

    // Track
    canvas.drawArc(rect, startAngle, sweepMax, false, trackPaint);

    // Fill
    final sweep = sweepMax * (score / 100).clamp(0.0, 1.0);
    if (sweep > 0) {
      canvas.drawArc(rect, startAngle, sweep, false, fillPaint);
    }

    // Glow dot at fill end
    if (sweep > 0) {
      final angle = startAngle + sweep;
      final dx = cx + radius * math.cos(angle);
      final dy = cy + radius * math.sin(angle);
      canvas.drawCircle(
        Offset(dx, dy), 7,
        Paint()
          ..color = color.withOpacity(0.6)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 6),
      );
      canvas.drawCircle(Offset(dx, dy), 5, Paint()..color = color);
    }
  }

  @override
  bool shouldRepaint(covariant _ArcGaugePainter old) => old.score != score;
}

// ─────────────────────────────────────────────────────────────────────────────
// KPI Grid (2×3)
// ─────────────────────────────────────────────────────────────────────────────
class _KpiGrid extends StatelessWidget {
  final double completionRate;
  final double totalRevenue;
  final int completedRides;
  final int totalRides;
  final double avgRating;
  final int pendingRequests;

  const _KpiGrid({
    required this.completionRate,
    required this.totalRevenue,
    required this.completedRides,
    required this.totalRides,
    required this.avgRating,
    required this.pendingRequests,
  });

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      Row(children: [
        Expanded(child: _KpiCard(
          label: 'Completion Rate',
          value: '${completionRate.round()}%',
          icon: Icons.check_circle_rounded,
          color: completionRate >= 80 ? _kGreen : completionRate >= 60 ? _kGold : _kRed,
          sub: completionRate >= 90 ? 'Excellent' : completionRate >= 75 ? 'Good' : 'Needs work',
        )),
        const SizedBox(width: 10),
        Expanded(child: _KpiCard(
          label: 'Total Revenue',
          value: '${totalRevenue.toStringAsFixed(0)} TND',
          icon: Icons.payments_rounded,
          color: _kGold,
          sub: 'All time',
        )),
        const SizedBox(width: 10),
        Expanded(child: _KpiCard(
          label: 'Completed',
          value: '$completedRides / $totalRides',
          icon: Icons.done_all_rounded,
          color: AppTheme.info,
          sub: 'Rides',
        )),
      ]),
      const SizedBox(height: 10),
      Row(children: [
        Expanded(child: _KpiCard(
          label: 'Avg Rating',
          value: '${avgRating.toStringAsFixed(1)}★',
          icon: Icons.star_rounded,
          color: avgRating >= 4.5 ? _kGold : avgRating >= 4.0 ? _kGreen : _kRed,
          sub: avgRating >= 4.5 ? 'Top Rated' : avgRating >= 4.0 ? 'Good' : 'Improve',
        )),
        const SizedBox(width: 10),
        Expanded(child: _KpiCard(
          label: 'Pending',
          value: '$pendingRequests',
          icon: Icons.inbox_rounded,
          color: pendingRequests > 0 ? AppTheme.warning : _kMuted,
          sub: 'Requests',
        )),
        const SizedBox(width: 10),
        Expanded(child: _KpiCard(
          label: 'Active Rides',
          value: '${totalRides - completedRides}',
          icon: Icons.directions_car_rounded,
          color: _kGreen,
          sub: 'Ongoing',
        )),
      ]),
    ]);
  }
}

class _KpiCard extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  final Color color;
  final String sub;
  const _KpiCard({required this.label, required this.value, required this.icon, required this.color, required this.sub});

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.fromLTRB(12, 14, 12, 12),
    decoration: BoxDecoration(
      color: _kCard,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: _kBorder),
    ),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Container(
        width: 32, height: 32,
        decoration: BoxDecoration(color: color.withOpacity(0.15), borderRadius: BorderRadius.circular(9)),
        child: Icon(icon, color: color, size: 17),
      ),
      const SizedBox(height: 8),
      Text(value, style: TextStyle(color: color, fontSize: 16, fontWeight: FontWeight.w800)),
      const SizedBox(height: 2),
      Text(label, style: const TextStyle(color: _kMuted, fontSize: 9, fontWeight: FontWeight.w600, letterSpacing: 0.3)),
      Text(sub, style: TextStyle(color: color.withOpacity(0.7), fontSize: 9)),
    ]),
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Rating Distribution — horizontal bar chart
// ─────────────────────────────────────────────────────────────────────────────
class _RatingDistChart extends StatelessWidget {
  final List<Map<String, dynamic>> distribution;
  const _RatingDistChart({required this.distribution});

  @override
  Widget build(BuildContext context) {
    final total = distribution.fold<int>(0, (s, r) => s + (r['count'] as int));
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: _kCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: _kBorder),
      ),
      child: Column(
        children: distribution.map((r) {
          final stars = r['stars'] as int;
          final count = r['count'] as int;
          final pct   = total > 0 ? count / total : 0.0;
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 5),
            child: Row(
              children: [
                Row(children: List.generate(5, (i) => Icon(
                  i < stars ? Icons.star_rounded : Icons.star_outline_rounded,
                  color: _kGold, size: 13,
                ))),
                const SizedBox(width: 10),
                Expanded(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(
                      value: pct,
                      minHeight: 10,
                      backgroundColor: _kBorder,
                      valueColor: AlwaysStoppedAnimation(
                        stars >= 4 ? _kGreen : stars == 3 ? _kGold : _kRed,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                SizedBox(
                  width: 28,
                  child: Text(
                    '$count',
                    textAlign: TextAlign.right,
                    style: const TextStyle(color: _kMuted, fontSize: 12, fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
          );
        }).toList(),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Shared chart model
// ─────────────────────────────────────────────────────────────────────────────
class _ChartPoint {
  final String label;
  final double value;
  const _ChartPoint({required this.label, required this.value});
}

// ─────────────────────────────────────────────────────────────────────────────
// Line Chart (rating trend)
// ─────────────────────────────────────────────────────────────────────────────
class _LineChart extends StatelessWidget {
  final List<_ChartPoint> points;
  final double maxValue;
  final Color color;
  final String unit;
  const _LineChart({required this.points, required this.maxValue, required this.color, required this.unit});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(8, 16, 8, 8),
      decoration: BoxDecoration(
        color: _kCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: _kBorder),
      ),
      child: SizedBox(
        height: 160,
        child: CustomPaint(
          painter: _LineChartPainter(
            points: points,
            maxValue: maxValue,
            color: color,
            unit: unit,
          ),
          size: Size.infinite,
        ),
      ),
    );
  }
}

class _LineChartPainter extends CustomPainter {
  final List<_ChartPoint> points;
  final double maxValue;
  final Color color;
  final String unit;
  const _LineChartPainter({required this.points, required this.maxValue, required this.color, required this.unit});

  @override
  void paint(Canvas canvas, Size size) {
    if (points.isEmpty) return;
    const leftPad = 36.0;
    const bottomPad = 28.0;
    const topPad = 10.0;
    final chartW = size.width - leftPad - 8;
    final chartH = size.height - bottomPad - topPad;

    // Grid lines
    final gridPaint = Paint()
      ..color = _kBorder
      ..strokeWidth = 0.5;
    for (int i = 0; i <= 4; i++) {
      final y = topPad + chartH * (1 - i / 4);
      canvas.drawLine(Offset(leftPad, y), Offset(leftPad + chartW, y), gridPaint);
      // Y axis labels
      final val = (maxValue * i / 4).toStringAsFixed(1);
      _drawText(canvas, '$val$unit', Offset(0, y - 6), 9, _kMuted);
    }

    // Points and line
    final n = points.length;
    final xs = List.generate(n, (i) => leftPad + i * (chartW / (n > 1 ? n - 1 : 1)));
    final ys = points.map((p) => topPad + chartH * (1 - (p.value / maxValue).clamp(0, 1))).toList();

    // Gradient fill under curve
    final path = Path();
    path.moveTo(xs[0], ys[0]);
    for (int i = 1; i < n; i++) {
      path.lineTo(xs[i], ys[i]);
    }
    path.lineTo(xs[n - 1], topPad + chartH);
    path.lineTo(xs[0], topPad + chartH);
    path.close();
    final fillPaint = Paint()
      ..shader = LinearGradient(
        colors: [color.withOpacity(0.3), color.withOpacity(0.0)],
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
      ).createShader(Rect.fromLTWH(leftPad, topPad, chartW, chartH));
    canvas.drawPath(path, fillPaint);

    // Line
    final linePaint = Paint()
      ..color = color
      ..strokeWidth = 2.5
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;
    final linePath = Path();
    linePath.moveTo(xs[0], ys[0]);
    for (int i = 1; i < n; i++) {
      linePath.lineTo(xs[i], ys[i]);
    }
    canvas.drawPath(linePath, linePaint);

    // Dots + values + labels
    for (int i = 0; i < n; i++) {
      canvas.drawCircle(Offset(xs[i], ys[i]), 4.5, Paint()..color = color);
      canvas.drawCircle(Offset(xs[i], ys[i]), 2.5, Paint()..color = _kNavy);
      _drawText(canvas, points[i].value.toStringAsFixed(1), Offset(xs[i] - 12, ys[i] - 18), 9, color);
      _drawText(canvas, points[i].label, Offset(xs[i] - 10, size.height - 16), 9, _kMuted);
    }
  }

  void _drawText(Canvas canvas, String text, Offset offset, double size, Color color) {
    final tp = TextPainter(
      text: TextSpan(text: text, style: TextStyle(color: color, fontSize: size, fontWeight: FontWeight.w600)),
      textDirection: TextDirection.ltr,
    )..layout();
    tp.paint(canvas, offset);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

// ─────────────────────────────────────────────────────────────────────────────
// Bar Chart (revenue)
// ─────────────────────────────────────────────────────────────────────────────
class _BarChart extends StatelessWidget {
  final List<_ChartPoint> bars;
  final Color color;
  final String unit;
  const _BarChart({required this.bars, required this.color, required this.unit});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(8, 16, 8, 8),
      decoration: BoxDecoration(
        color: _kCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: _kBorder),
      ),
      child: SizedBox(
        height: 160,
        child: CustomPaint(
          painter: _BarChartPainter(bars: bars, color: color, unit: unit),
          size: Size.infinite,
        ),
      ),
    );
  }
}

class _BarChartPainter extends CustomPainter {
  final List<_ChartPoint> bars;
  final Color color;
  final String unit;
  const _BarChartPainter({required this.bars, required this.color, required this.unit});

  @override
  void paint(Canvas canvas, Size size) {
    if (bars.isEmpty) return;
    const leftPad = 44.0;
    const bottomPad = 28.0;
    const topPad = 10.0;
    final chartW = size.width - leftPad - 8;
    final chartH = size.height - bottomPad - topPad;

    final maxVal = bars.map((b) => b.value).fold(0.0, math.max);
    if (maxVal == 0) return;

    // Grid lines + Y axis
    final gridPaint = Paint()..color = _kBorder..strokeWidth = 0.5;
    for (int i = 0; i <= 4; i++) {
      final y = topPad + chartH * (1 - i / 4);
      canvas.drawLine(Offset(leftPad, y), Offset(leftPad + chartW, y), gridPaint);
      final val = (maxVal * i / 4).round();
      _drawText(canvas, '$val$unit', Offset(0, y - 6), 8, _kMuted);
    }

    final n = bars.length;
    final barW = (chartW / n * 0.55).clamp(12.0, 40.0);
    final gap  = chartW / n;

    for (int i = 0; i < n; i++) {
      final x = leftPad + i * gap + gap / 2 - barW / 2;
      final h = chartH * (bars[i].value / maxVal);
      final y = topPad + chartH - h;

      // Bar
      final rrect = RRect.fromRectAndCorners(
        Rect.fromLTWH(x, y, barW, h),
        topLeft: const Radius.circular(5),
        topRight: const Radius.circular(5),
      );
      canvas.drawRRect(rrect, Paint()
        ..shader = LinearGradient(
          colors: [color, color.withOpacity(0.5)],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ).createShader(Rect.fromLTWH(x, y, barW, h)));

      // Value above bar
      _drawText(canvas, bars[i].value.round().toString(),
          Offset(x, y - 14), 9, color);

      // X label
      _drawText(canvas, bars[i].label,
          Offset(x - 2, size.height - 16), 9, _kMuted);
    }
  }

  void _drawText(Canvas canvas, String text, Offset offset, double size, Color color) {
    final tp = TextPainter(
      text: TextSpan(text: text, style: TextStyle(color: color, fontSize: size, fontWeight: FontWeight.w600)),
      textDirection: TextDirection.ltr,
    )..layout();
    tp.paint(canvas, offset);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

// ─────────────────────────────────────────────────────────────────────────────
// Recommendation Card
// ─────────────────────────────────────────────────────────────────────────────
class _RecommendationCard extends StatelessWidget {
  final Map<String, dynamic> rec;
  const _RecommendationCard({required this.rec});

  Color _parseColor(String c) => switch (c) {
    'red'    => _kRed,
    'orange' => AppTheme.warning,
    'green'  => _kGreen,
    'blue'   => AppTheme.info,
    'teal'   => const Color(0xFF14B8A6),
    _        => _kGold,
  };

  IconData _parseIcon(String i) => switch (i) {
    'cancel'      => Icons.cancel_rounded,
    'star'        => Icons.star_rounded,
    'trending_up' => Icons.trending_up_rounded,
    'inbox'       => Icons.inbox_rounded,
    'ac_unit'     => Icons.ac_unit_rounded,
    'verified'    => Icons.verified_rounded,
    _             => Icons.lightbulb_rounded,
  };

  @override
  Widget build(BuildContext context) {
    final color = _parseColor(rec['color'] as String? ?? '');
    final icon  = _parseIcon(rec['icon'] as String? ?? '');
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withOpacity(0.06),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withOpacity(0.25)),
      ),
      child: Row(children: [
        Container(
          width: 40, height: 40,
          decoration: BoxDecoration(color: color.withOpacity(0.15), borderRadius: BorderRadius.circular(12)),
          child: Icon(icon, color: color, size: 20),
        ),
        const SizedBox(width: 12),
        Expanded(child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(rec['title'] as String? ?? '', style: TextStyle(color: color, fontSize: 13, fontWeight: FontWeight.w700)),
            const SizedBox(height: 3),
            Text(rec['body'] as String? ?? '', style: const TextStyle(color: _kMuted, fontSize: 12, height: 1.4)),
          ],
        )),
      ]),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Quick Actions
// ─────────────────────────────────────────────────────────────────────────────
class _QuickActions extends StatelessWidget {
  final int pendingCount;
  const _QuickActions({required this.pendingCount});

  @override
  Widget build(BuildContext context) {
    return Wrap(spacing: 10, runSpacing: 10, children: [
      _QBtn('Offer Ride',      Icons.add_road_rounded,         _kGold,         () => context.go('/offer-ride')),
      _QBtn('Requests',        Icons.inbox_rounded,            AppTheme.warning, () => context.go('/booking-requests'), badge: pendingCount),
      _QBtn('My Vehicles',     Icons.directions_car_rounded,   AppTheme.info,    () => context.go('/vehicles')),
      _QBtn('Wallet',          Icons.account_balance_wallet_rounded, _kGreen,   () => context.go('/payments')),
    ]);
  }
}

class _QBtn extends StatelessWidget {
  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;
  final int badge;
  const _QBtn(this.label, this.icon, this.color, this.onTap, {this.badge = 0});

  @override
  Widget build(BuildContext context) => GestureDetector(
    onTap: onTap,
    child: Container(
      width: (MediaQuery.of(context).size.width - 52) / 2,
      padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 14),
      decoration: BoxDecoration(
        color: _kCard,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: _kBorder),
      ),
      child: Row(children: [
        Stack(children: [
          Container(
            width: 36, height: 36,
            decoration: BoxDecoration(color: color.withOpacity(0.15), borderRadius: BorderRadius.circular(10)),
            child: Icon(icon, color: color, size: 18),
          ),
          if (badge > 0)
            Positioned(
              right: 0, top: 0,
              child: Container(
                width: 14, height: 14,
                decoration: const BoxDecoration(color: _kRed, shape: BoxShape.circle),
                child: Center(child: Text('$badge', style: const TextStyle(color: _kWhite, fontSize: 8, fontWeight: FontWeight.w800))),
              ),
            ),
        ]),
        const SizedBox(width: 10),
        Text(label, style: const TextStyle(color: _kWhite, fontSize: 12, fontWeight: FontWeight.w600)),
      ]),
    ),
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Rides Tab (second tab)
// ─────────────────────────────────────────────────────────────────────────────
class _RidesTab extends StatelessWidget {
  final List<RideModel> rides;
  final List<dynamic> requests;
  final bool loading;
  final void Function(int id, String action) onRespond;
  final VoidCallback onRefresh;
  const _RidesTab({
    required this.rides,
    required this.requests,
    required this.loading,
    required this.onRespond,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Center(child: CircularProgressIndicator(color: _kGold));
    }
    return SingleChildScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (requests.isNotEmpty) ...[
            Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              _sectionLabel('Pending Requests', Icons.inbox_rounded),
              TextButton(
                onPressed: () => context.go('/booking-requests'),
                child: const Text('View all', style: TextStyle(color: _kGold, fontSize: 12)),
              ),
            ]),
            const SizedBox(height: 8),
            ...requests.take(3).map((r) => _RequestCard(
              request: r as Map<String, dynamic>,
              onAccept: () => onRespond(r['id'] as int, 'accept'),
              onReject: () => onRespond(r['id'] as int, 'reject'),
            )),
            const SizedBox(height: 16),
          ],
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            _sectionLabel('My Rides', Icons.directions_car_rounded),
            TextButton(
              onPressed: () => context.go('/my-rides'),
              child: const Text('View all', style: TextStyle(color: _kGold, fontSize: 12)),
            ),
          ]),
          const SizedBox(height: 8),
          if (rides.isEmpty)
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(color: _kCard, borderRadius: BorderRadius.circular(16), border: Border.all(color: _kBorder)),
              child: const Center(
                child: Column(children: [
                  Icon(Icons.directions_car_outlined, color: _kMuted, size: 36),
                  SizedBox(height: 8),
                  Text('No rides yet.', style: TextStyle(color: _kMuted)),
                ]),
              ),
            )
          else
            ...rides.take(8).map((r) => _DriverRideCard(ride: r, onDeparted: onRefresh)),
        ],
      ),
    );
  }
}

class _RequestCard extends StatelessWidget {
  final Map<String, dynamic> request;
  final VoidCallback onAccept;
  final VoidCallback onReject;
  const _RequestCard({required this.request, required this.onAccept, required this.onReject});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: _kCard,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: _kBorder),
      ),
      child: Row(children: [
        Container(
          width: 40, height: 40,
          decoration: BoxDecoration(
            color: _kGold.withOpacity(0.15),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Center(
            child: Text(
              (request['passenger_name'] as String? ?? 'P')[0].toUpperCase(),
              style: const TextStyle(color: _kGold, fontSize: 16, fontWeight: FontWeight.w800),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(request['passenger_name'] as String? ?? 'Passenger',
              style: const TextStyle(color: _kWhite, fontWeight: FontWeight.w700, fontSize: 13)),
          Text(
            '${request['from_location']} → ${request['to_location']} · ${request['seats']} seat(s)',
            style: const TextStyle(color: _kMuted, fontSize: 11),
          ),
        ])),
        Row(children: [
          GestureDetector(
            onTap: onReject,
            child: Container(
              width: 34, height: 34,
              decoration: BoxDecoration(color: _kRed.withOpacity(0.12), borderRadius: BorderRadius.circular(10), border: Border.all(color: _kRed.withOpacity(0.3))),
              child: const Icon(Icons.close_rounded, color: _kRed, size: 18),
            ),
          ),
          const SizedBox(width: 8),
          GestureDetector(
            onTap: onAccept,
            child: Container(
              width: 34, height: 34,
              decoration: BoxDecoration(color: _kGreen.withOpacity(0.12), borderRadius: BorderRadius.circular(10), border: Border.all(color: _kGreen.withOpacity(0.3))),
              child: const Icon(Icons.check_rounded, color: _kGreen, size: 18),
            ),
          ),
        ]),
      ]),
    );
  }
}

class _DriverRideCard extends StatefulWidget {
  final RideModel ride;
  final VoidCallback onDeparted;
  const _DriverRideCard({required this.ride, required this.onDeparted});

  @override
  State<_DriverRideCard> createState() => _DriverRideCardState();
}

class _DriverRideCardState extends State<_DriverRideCard> {
  bool _departing = false;
  bool _boosting = false;
  bool _arriving = false;

  Future<void> _boostRide() async {
    final tier = await showBoostTierPicker(
      context,
      title: 'Boost Ride',
      subtitle:
          'Pin "${widget.ride.fromLocation} → ${widget.ride.toLocation}" to the top of the community feed. Pick how long it stays featured — the fee is deducted from your wallet.',
      backgroundColor: _kCard,
      textColor: _kWhite,
      subtitleColor: _kMuted,
    );
    if (tier == null || !mounted) return;

    setState(() => _boosting = true);
    try {
      await context
          .read<ApiService>()
          .post('/feed/boost-ride', {'ride_id': widget.ride.id, 'tier': tier});
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Ride boosted to the top of the feed!'),
          backgroundColor: Colors.amber,
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: _kRed),
      );
    } finally {
      if (mounted) setState(() => _boosting = false);
    }
  }

  Future<void> _markArrival() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: _kCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Row(children: [
          Icon(Icons.flag_rounded, color: _kGold),
          SizedBox(width: 8),
          Text('Mark Arrival', style: TextStyle(color: _kWhite, fontWeight: FontWeight.w800)),
        ]),
        content: Text(
          'Confirm that the ride has arrived at ${widget.ride.toLocation}?',
          style: const TextStyle(color: _kMuted, fontSize: 13, height: 1.5),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel', style: TextStyle(color: _kMuted)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: _kGold,
              foregroundColor: _kNavy,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Arrive Now', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    setState(() => _arriving = true);
    try {
      await context.read<RideService>().markArrival(widget.ride.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Ride completed!'), backgroundColor: _kGreen),
      );
      widget.onDeparted();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: _kRed),
      );
    } finally {
      if (mounted) setState(() => _arriving = false);
    }
  }

  Future<void> _confirmDeparture() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: _kCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('Confirm Departure',
            style: TextStyle(color: _kWhite, fontWeight: FontWeight.w800)),
        content: Text(
          'Confirm that you are departing from ${widget.ride.fromLocation} to ${widget.ride.toLocation}?\n\nAll confirmed passengers will be notified.',
          style: const TextStyle(color: _kMuted, fontSize: 13),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel', style: TextStyle(color: _kMuted)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: _kGreen,
              foregroundColor: _kWhite,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Depart Now', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    setState(() => _departing = true);
    try {
      await context.read<RideService>().markDeparture(widget.ride.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Ride started! Passengers have been notified.'),
          backgroundColor: _kGreen,
        ),
      );
      widget.onDeparted();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: _kRed),
      );
    } finally {
      if (mounted) setState(() => _departing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ride = widget.ride;
    final (statusColor, statusLabel) = switch (ride.status) {
      'active' || 'open'   => (_kGreen, 'ACTIVE'),
      'completed'          => (AppTheme.info, 'DONE'),
      'in_progress'        => (_kGold, 'IN PROGRESS'),
      'cancelled'          => (_kRed, 'CANCELLED'),
      _                    => (_kMuted, ride.status.toUpperCase()),
    };

    final canDepart = ride.status == 'active' || ride.status == 'open';
    final canArrive = ride.status == 'in_progress';

    return GestureDetector(
      onTap: () => context.push('/ride/${ride.id}'),
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: _kCard,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: canDepart ? _kGreen.withOpacity(0.3)
                : canArrive ? _kGold.withOpacity(0.3)
                : _kBorder,
          ),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Container(
              width: 40, height: 40,
              decoration: BoxDecoration(
                  color: statusColor.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(12)),
              child: Icon(Icons.directions_car_rounded, color: statusColor, size: 20),
            ),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${ride.fromLocation} → ${ride.toLocation}',
                  style: const TextStyle(
                      color: _kWhite, fontWeight: FontWeight.w700, fontSize: 13),
                  overflow: TextOverflow.ellipsis),
              Text(DateFormat('EEE, MMM d · HH:mm').format(ride.departureTime),
                  style: const TextStyle(color: _kMuted, fontSize: 11)),
            ])),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text('${ride.price.toStringAsFixed(1)} TND',
                  style: const TextStyle(
                      color: _kGold, fontWeight: FontWeight.w700, fontSize: 13)),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                decoration: BoxDecoration(
                  color: statusColor.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(statusLabel,
                    style: TextStyle(
                        color: statusColor, fontSize: 9, fontWeight: FontWeight.w700)),
              ),
            ]),
          ]),
          if (canArrive) ...[
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              height: 36,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: _kGold.withOpacity(0.15),
                  foregroundColor: _kGold,
                  elevation: 0,
                  side: BorderSide(color: _kGold.withOpacity(0.4)),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                ),
                onPressed: _arriving ? null : _markArrival,
                icon: _arriving
                    ? const SizedBox(width: 14, height: 14,
                        child: CircularProgressIndicator(strokeWidth: 2, color: _kGold))
                    : const Icon(Icons.flag_rounded, size: 16),
                label: Text(
                  _arriving ? 'Completing...' : 'Mark Arrival',
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
                ),
              ),
            ),
          ],
          if (canDepart) ...[
            const SizedBox(height: 10),
            Row(children: [
              Expanded(
                child: SizedBox(
                  height: 36,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: _kGreen.withOpacity(0.15),
                      foregroundColor: _kGreen,
                      elevation: 0,
                      side: BorderSide(color: _kGreen.withOpacity(0.4)),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    ),
                    onPressed: _departing ? null : _confirmDeparture,
                    icon: _departing
                        ? const SizedBox(width: 14, height: 14,
                            child: CircularProgressIndicator(strokeWidth: 2, color: _kGreen))
                        : const Icon(Icons.play_circle_outline_rounded, size: 16),
                    label: Text(
                      _departing ? 'Starting...' : 'Confirm Departure',
                      style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(
                height: 36,
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.amber.withOpacity(0.15),
                    foregroundColor: Colors.amber,
                    elevation: 0,
                    side: BorderSide(color: Colors.amber.withOpacity(0.4)),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                  onPressed: _boosting ? null : _boostRide,
                  icon: _boosting
                      ? const SizedBox(width: 14, height: 14,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.amber))
                      : const Icon(Icons.bolt, size: 16),
                  label: Text(
                    _boosting ? '...' : 'Boost',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
                  ),
                ),
              ),
            ]),
          ],
        ]),
      ),
    );
  }
}
