import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/ride_service.dart';
import '../../utils/app_theme.dart';

const _kNavy    = AppTheme.primary;
const _kCard    = AppTheme.darkCard;
const _kSurface = AppTheme.darkSurface;
const _kBorder  = Color(0xFF1E3A5F);
const _kGold    = AppTheme.accent;
const _kGreen   = AppTheme.success;
const _kRed     = AppTheme.danger;
const _kMuted   = Color(0xFF8A9BB5);
const _kWhite   = Colors.white;

class RideConfirmationScreen extends StatefulWidget {
  final int rideId;
  const RideConfirmationScreen({super.key, required this.rideId});

  @override
  State<RideConfirmationScreen> createState() => _RideConfirmationScreenState();
}

class _RideConfirmationScreenState extends State<RideConfirmationScreen> {
  List<Map<String, dynamic>> _passengers = [];
  bool _loading = true;
  String? _error;

  // Local collected state: bookingId → bool
  final Map<int, bool> _collected = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final passengers =
          await context.read<RideService>().getRidePassengers(widget.rideId);
      if (mounted) {
        setState(() {
          _passengers = passengers;
          _loading = false;
          for (final p in passengers) {
            final id = p['booking_id'] as int? ?? 0;
            _collected[id] =
                (p['payment_status'] as String? ?? '') == 'cash_collected';
          }
        });
      }
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _loading = false; });
    }
  }

  Future<void> _toggleCollected(Map<String, dynamic> passenger) async {
    final bookingId = passenger['booking_id'] as int? ?? 0;
    final alreadyCollected = _collected[bookingId] ?? false;
    if (alreadyCollected) return; // can't un-collect
    setState(() => _collected[bookingId] = true);
    try {
      await context.read<RideService>().markCashCollected(bookingId);
    } catch (_) {
      if (mounted) setState(() => _collected[bookingId] = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _kNavy,
      appBar: AppBar(
        backgroundColor: AppTheme.primaryDark,
        foregroundColor: _kWhite,
        title: const Text('Payment Confirmation'),
        elevation: 0,
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: _kGold))
          : _error != null
              ? _ErrorView(message: _error!, onRetry: _load)
              : _passengers.isEmpty
                  ? _EmptyView()
                  : _Body(
                      passengers: _passengers,
                      collected: _collected,
                      onToggle: _toggleCollected,
                    ),
    );
  }
}

// ─── Body ─────────────────────────────────────────────────────────────────────

class _Body extends StatelessWidget {
  final List<Map<String, dynamic>> passengers;
  final Map<int, bool> collected;
  final Future<void> Function(Map<String, dynamic>) onToggle;

  const _Body({
    required this.passengers,
    required this.collected,
    required this.onToggle,
  });

  @override
  Widget build(BuildContext context) {
    // Compute totals
    double totalCashDue = 0;
    double totalCollected = 0;
    int collectedCount = 0;

    for (final p in passengers) {
      final cashDue = (p['cash_due'] as num?)?.toDouble() ?? 0.0;
      final id = p['booking_id'] as int? ?? 0;
      totalCashDue += cashDue;
      if (collected[id] == true) {
        totalCollected += cashDue;
        collectedCount++;
      }
    }

    final progress = totalCashDue > 0 ? totalCollected / totalCashDue : 0.0;
    final allDone = collectedCount == passengers.length;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          // ── Summary Banner ────────────────────────────────────────
          _Panel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: _kGold.withOpacity(0.12),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Icon(Icons.payments_rounded, color: _kGold, size: 22),
                  ),
                  const SizedBox(width: 12),
                  Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Text('Cash Payment Summary',
                        style: TextStyle(color: _kWhite, fontSize: 16, fontWeight: FontWeight.w700)),
                    Text(
                      '$collectedCount / ${passengers.length} passengers collected',
                      style: const TextStyle(color: _kMuted, fontSize: 12),
                    ),
                  ]),
                  const Spacer(),
                  if (allDone)
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: _kGreen.withOpacity(0.12),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: _kGreen.withOpacity(0.3)),
                      ),
                      child: const Text('All Done ✓',
                          style: TextStyle(color: _kGreen, fontSize: 12, fontWeight: FontWeight.w700)),
                    ),
                ]),
                const SizedBox(height: 16),

                // Progress bar
                ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: progress,
                    minHeight: 8,
                    backgroundColor: _kBorder,
                    valueColor: AlwaysStoppedAnimation(
                        allDone ? _kGreen : _kGold),
                  ),
                ),
                const SizedBox(height: 16),

                // Totals row
                Row(
                  children: [
                    _TotalChip(
                        label: 'Expected',
                        amount: totalCashDue,
                        color: _kMuted),
                    const SizedBox(width: 10),
                    _TotalChip(
                        label: 'Collected',
                        amount: totalCollected,
                        color: _kGreen),
                    const SizedBox(width: 10),
                    _TotalChip(
                        label: 'Pending',
                        amount: totalCashDue - totalCollected,
                        color: totalCashDue - totalCollected > 0
                            ? _kRed
                            : _kMuted),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // ── Instruction ───────────────────────────────────────────
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppTheme.info.withOpacity(0.06),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppTheme.info.withOpacity(0.2)),
            ),
            child: Row(children: const [
              Icon(Icons.info_outline_rounded, color: AppTheme.info, size: 16),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Tap "Mark Collected" once you receive cash from each passenger.',
                  style: TextStyle(color: AppTheme.info, fontSize: 12),
                ),
              ),
            ]),
          ),
          const SizedBox(height: 16),

          // ── Passenger list ────────────────────────────────────────
          ...passengers.map((p) {
            final id = p['booking_id'] as int? ?? 0;
            final isCollected = collected[id] ?? false;
            return _PassengerRow(
              passenger: p,
              isCollected: isCollected,
              onMarkCollected: () => onToggle(p),
            );
          }),
        ],
      ),
    );
  }
}

// ─── Passenger Row ────────────────────────────────────────────────────────────

class _PassengerRow extends StatelessWidget {
  final Map<String, dynamic> passenger;
  final bool isCollected;
  final VoidCallback onMarkCollected;

  const _PassengerRow({
    required this.passenger,
    required this.isCollected,
    required this.onMarkCollected,
  });

  @override
  Widget build(BuildContext context) {
    final name    = passenger['passenger_name'] as String? ?? 'Passenger';
    final seats   = passenger['seats'] as int? ?? 1;
    final cashDue = (passenger['cash_due'] as num?)?.toDouble() ?? 0.0;

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: _kCard,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: isCollected ? _kGreen.withOpacity(0.4) : _kBorder,
        ),
      ),
      child: Row(
        children: [
          // Avatar
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: isCollected
                    ? [_kGreen, _kGreen.withOpacity(0.7)]
                    : [_kGold, AppTheme.accentDark],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Center(
              child: Text(
                name[0].toUpperCase(),
                style: const TextStyle(
                    color: _kNavy, fontSize: 18, fontWeight: FontWeight.w800),
              ),
            ),
          ),
          const SizedBox(width: 12),

          // Info
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(name,
                    style: const TextStyle(
                        color: _kWhite, fontSize: 14, fontWeight: FontWeight.w700)),
                const SizedBox(height: 3),
                Text(
                  '$seats seat${seats != 1 ? 's' : ''} · '
                  '${cashDue.toStringAsFixed(2)} TND cash',
                  style: const TextStyle(color: _kMuted, fontSize: 12),
                ),
              ],
            ),
          ),

          // Collect button / badge
          if (isCollected)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
              decoration: BoxDecoration(
                color: _kGreen.withOpacity(0.12),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: _kGreen.withOpacity(0.3)),
              ),
              child: const Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(Icons.check_rounded, color: _kGreen, size: 14),
                SizedBox(width: 5),
                Text('Collected',
                    style: TextStyle(
                        color: _kGreen, fontSize: 12, fontWeight: FontWeight.w700)),
              ]),
            )
          else
            ElevatedButton(
              onPressed: onMarkCollected,
              style: ElevatedButton.styleFrom(
                backgroundColor: _kGold,
                foregroundColor: _kNavy,
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                elevation: 0,
                textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
              ),
              child: const Text('Collect'),
            ),
        ],
      ),
    );
  }
}

// ─── Total chip ───────────────────────────────────────────────────────────────

class _TotalChip extends StatelessWidget {
  final String label;
  final double amount;
  final Color color;
  const _TotalChip({required this.label, required this.amount, required this.color});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: color.withOpacity(0.07),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: color.withOpacity(0.2)),
        ),
        child: Column(children: [
          Text(label, style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w600)),
          const SizedBox(height: 3),
          Text(
            '${amount.toStringAsFixed(2)} TND',
            style: TextStyle(color: color, fontSize: 13, fontWeight: FontWeight.w800),
          ),
        ]),
      ),
    );
  }
}

// ─── Shared ───────────────────────────────────────────────────────────────────

class _Panel extends StatelessWidget {
  final Widget child;
  const _Panel({required this.child});

  @override
  Widget build(BuildContext context) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: _kCard,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: _kBorder),
    ),
    child: child,
  );
}

class _EmptyView extends StatelessWidget {
  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(32),
      child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
        const Icon(Icons.people_outline_rounded, color: _kMuted, size: 56),
        const SizedBox(height: 16),
        const Text('No confirmed passengers for this ride.',
            textAlign: TextAlign.center,
            style: TextStyle(color: _kMuted, fontSize: 14)),
      ]),
    ),
  );
}

class _ErrorView extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;
  const _ErrorView({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(32),
      child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
        const Icon(Icons.error_outline_rounded, color: _kRed, size: 52),
        const SizedBox(height: 16),
        Text(message,
            textAlign: TextAlign.center, style: const TextStyle(color: _kMuted)),
        const SizedBox(height: 20),
        ElevatedButton(onPressed: onRetry, child: const Text('Retry')),
      ]),
    ),
  );
}