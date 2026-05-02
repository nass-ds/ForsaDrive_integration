import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../../services/ride_service.dart';
import '../../utils/app_theme.dart';

const _kNavy    = AppTheme.primary;
const _kCard    = AppTheme.darkCard;
const _kBorder  = Color(0xFF1E3A5F);
const _kGold    = AppTheme.accent;
const _kGreen   = AppTheme.success;
const _kMuted   = Color(0xFF8A9BB5);
const _kWhite   = Colors.white;

class TripReceiptScreen extends StatefulWidget {
  final int rideId;
  const TripReceiptScreen({super.key, required this.rideId});

  @override
  State<TripReceiptScreen> createState() => _TripReceiptScreenState();
}

class _TripReceiptScreenState extends State<TripReceiptScreen> {
  Map<String, dynamic>? _receipt;
  String? _type;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final res = await context.read<RideService>().getTripReceipt(widget.rideId);
      if (mounted) {
        setState(() {
          _receipt = res['receipt'] as Map<String, dynamic>?;
          _type    = res['type']    as String? ?? 'passenger';
          _loading = false;
        });
      }
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _kNavy,
      appBar: AppBar(
        backgroundColor: AppTheme.primaryDark,
        foregroundColor: _kWhite,
        title: const Text('Trip Receipt', style: TextStyle(fontWeight: FontWeight.w700)),
        elevation: 0,
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: _kGold))
          : _error != null
              ? _ErrorView(message: _error!, onRetry: _load)
              : _receipt == null
                  ? const Center(child: Text('Receipt not found', style: TextStyle(color: _kMuted)))
                  : _ReceiptBody(receipt: _receipt!, type: _type!),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
class _ReceiptBody extends StatelessWidget {
  final Map<String, dynamic> receipt;
  final String type;
  const _ReceiptBody({required this.receipt, required this.type});

  @override
  Widget build(BuildContext context) {
    final isDriver = type == 'driver';
    final rideId   = receipt['ride_id']       as int?    ?? receipt['booking_id'] as int? ?? 0;
    final bookingId = receipt['booking_id']   as int?;
    final from     = receipt['from_location'] as String? ?? '';
    final to       = receipt['to_location']   as String? ?? '';
    final deptStr  = receipt['departure_time'] as String? ?? '';
    final dept     = DateTime.tryParse(deptStr) ?? DateTime.now();
    final bookedAt = DateTime.tryParse(receipt['booked_at'] as String? ?? '') ?? DateTime.now();
    final driver   = receipt['driver_name']   as String? ?? 'Driver';
    final score    = (receipt['driver_score'] as num?)?.toDouble() ?? 5.0;
    final vType    = receipt['vehicle_type']  as String?;
    final vMake    = receipt['vehicle_make']  as String?;
    final vModel   = receipt['vehicle_model'] as String?;
    final status   = receipt['ride_status']   as String? ?? 'completed';

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          // ── Receipt Card ──────────────────────────────────────────
          Container(
            decoration: BoxDecoration(
              color: _kCard,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: _kBorder),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.3),
                  blurRadius: 20,
                  offset: const Offset(0, 8),
                ),
              ],
            ),
            child: Column(
              children: [
                // Header
                Container(
                  padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 20),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: [_kGold.withOpacity(0.15), _kNavy.withOpacity(0.5)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
                  ),
                  child: Row(
                    children: [
                      Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        const Text(
                          'FORSA DRIVE',
                          style: TextStyle(
                            color: _kGold,
                            fontSize: 18,
                            fontWeight: FontWeight.w900,
                            letterSpacing: 2,
                          ),
                        ),
                        const Text(
                          'Official Trip Receipt',
                          style: TextStyle(color: _kMuted, fontSize: 12),
                        ),
                      ]),
                      const Spacer(),
                      // Status stamp
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        decoration: BoxDecoration(
                          color: status == 'completed'
                              ? _kGreen.withOpacity(0.15)
                              : _kGold.withOpacity(0.15),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(
                            color: status == 'completed'
                                ? _kGreen.withOpacity(0.5)
                                : _kGold.withOpacity(0.5),
                          ),
                        ),
                        child: Text(
                          status.toUpperCase().replaceAll('_', ' '),
                          style: TextStyle(
                            color: status == 'completed' ? _kGreen : _kGold,
                            fontSize: 11,
                            fontWeight: FontWeight.w900,
                            letterSpacing: 1.2,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),

                Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Receipt / booking number
                      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                        _MetaItem(
                          label: isDriver ? 'Ride #' : 'Booking #',
                          value: '#${bookingId ?? rideId}',
                          color: _kGold,
                        ),
                        _MetaItem(
                          label: 'Date Issued',
                          value: DateFormat('MMM d, y').format(bookedAt),
                          color: _kMuted,
                          align: TextAlign.right,
                        ),
                      ]),

                      const SizedBox(height: 20),
                      _Dashes(),
                      const SizedBox(height: 20),

                      // Route
                      _SectionLabel('Route'),
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          Column(children: [
                            _Dot(color: _kGreen),
                            Container(width: 2, height: 36,
                                color: _kBorder),
                            _Dot(color: AppTheme.danger),
                          ]),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(from, style: const TextStyle(color: _kWhite, fontSize: 15, fontWeight: FontWeight.w700)),
                                const SizedBox(height: 20),
                                Text(to, style: const TextStyle(color: _kWhite, fontSize: 15, fontWeight: FontWeight.w700)),
                              ],
                            ),
                          ),
                        ],
                      ),

                      const SizedBox(height: 20),
                      _Dashes(),
                      const SizedBox(height: 20),

                      // Trip details
                      _SectionLabel('Trip Details'),
                      const SizedBox(height: 12),
                      _DetailRow(
                        icon: Icons.calendar_today_rounded,
                        label: 'Departure',
                        value: DateFormat('EEE, MMM d · h:mm a').format(dept),
                      ),
                      const SizedBox(height: 8),
                      _DetailRow(
                        icon: Icons.person_rounded,
                        label: 'Driver',
                        value: '$driver  ★ ${score.toStringAsFixed(1)}',
                      ),
                      if (vType != null) ...[
                        const SizedBox(height: 8),
                        _DetailRow(
                          icon: Icons.directions_car_rounded,
                          label: 'Vehicle',
                          value: [
                            vMake,
                            vModel,
                            vType[0].toUpperCase() + vType.substring(1),
                          ].whereType<String>().join(' '),
                        ),
                      ],
                      if (!isDriver) ...[
                        const SizedBox(height: 8),
                        _DetailRow(
                          icon: Icons.airline_seat_recline_normal_rounded,
                          label: 'Seats',
                          value: '${receipt['seats'] ?? 1}',
                        ),
                      ] else ...[
                        const SizedBox(height: 8),
                        _DetailRow(
                          icon: Icons.group_rounded,
                          label: 'Passengers',
                          value: '${receipt['total_bookings'] ?? 0} booking(s) · ${receipt['total_seats'] ?? 0} seat(s)',
                        ),
                      ],

                      const SizedBox(height: 20),
                      _Dashes(),
                      const SizedBox(height: 20),

                      // Payment breakdown
                      _SectionLabel(isDriver ? 'Earnings' : 'Payment'),
                      const SizedBox(height: 12),

                      if (!isDriver) ...[
                        _PayRow(
                          label: 'Price per seat',
                          value: '${((receipt['price_per_seat'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)} TND',
                        ),
                        if ((receipt['seats'] as int? ?? 1) > 1) ...[
                          const SizedBox(height: 6),
                          _PayRow(
                            label: '× ${receipt['seats']} seats',
                            value: '${((receipt['total_price'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)} TND',
                          ),
                        ],
                        const SizedBox(height: 12),
                        Container(height: 1, color: _kBorder),
                        const SizedBox(height: 12),
                        _PayRow(
                          label: 'Paid online (50%)',
                          value: '${((receipt['paid_amount'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)} TND',
                          valueColor: _kGreen,
                        ),
                        const SizedBox(height: 6),
                        _PayRow(
                          label: 'Cash on arrival (50%)',
                          value: '${((receipt['cash_on_arrival'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)} TND',
                          valueColor: _kGold,
                        ),
                        const SizedBox(height: 12),
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: _kGold.withOpacity(0.08),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: _kGold.withOpacity(0.25)),
                          ),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              const Text('TOTAL',
                                  style: TextStyle(color: _kWhite, fontSize: 14, fontWeight: FontWeight.w800, letterSpacing: 0.5)),
                              Text(
                                '${((receipt['total_price'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)} TND',
                                style: const TextStyle(color: _kGold, fontSize: 16, fontWeight: FontWeight.w900),
                              ),
                            ],
                          ),
                        ),
                      ] else ...[
                        _PayRow(
                          label: 'Online payments received',
                          value: '${((receipt['total_online_received'] as num?)?.toDouble() ?? 0).toStringAsFixed(2)} TND',
                          valueColor: _kGreen,
                        ),
                        const SizedBox(height: 6),
                        _PayRow(
                          label: 'Cash collected on arrival',
                          value: 'See payment confirmation',
                          valueColor: _kGold,
                        ),
                      ],

                      const SizedBox(height: 20),
                      _Dashes(),
                      const SizedBox(height: 16),

                      // Footer
                      Center(
                        child: Column(children: [
                          const Icon(Icons.verified_rounded, color: _kGold, size: 22),
                          const SizedBox(height: 6),
                          const Text(
                            'Thank you for using ForsaDrive',
                            style: TextStyle(color: _kMuted, fontSize: 12),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Receipt generated ${DateFormat('MMM d, y • h:mm a').format(DateTime.now())}',
                            style: const TextStyle(color: _kBorder, fontSize: 10),
                          ),
                        ]),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Small reusable widgets
// ─────────────────────────────────────────────────────────────────────────────

class _SectionLabel extends StatelessWidget {
  final String text;
  const _SectionLabel(this.text);

  @override
  Widget build(BuildContext context) => Text(
    text.toUpperCase(),
    style: const TextStyle(
      color: _kGold,
      fontSize: 10,
      fontWeight: FontWeight.w700,
      letterSpacing: 1,
    ),
  );
}

class _Dashes extends StatelessWidget {
  @override
  Widget build(BuildContext context) => Row(
    children: List.generate(
      40,
      (_) => Expanded(
        child: Container(
          height: 1,
          color: Colors.transparent,
          child: CustomPaint(painter: _DashPainter()),
        ),
      ),
    ),
  );
}

class _DashPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawLine(
      Offset.zero,
      Offset(size.width, 0),
      Paint()
        ..color = _kBorder
        ..strokeWidth = 1
        ..style = PaintingStyle.stroke,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _Dot extends StatelessWidget {
  final Color color;
  const _Dot({required this.color});

  @override
  Widget build(BuildContext context) => Container(
    width: 10,
    height: 10,
    decoration: BoxDecoration(color: color, shape: BoxShape.circle),
  );
}

class _MetaItem extends StatelessWidget {
  final String label;
  final String value;
  final Color color;
  final TextAlign align;
  const _MetaItem({
    required this.label,
    required this.value,
    required this.color,
    this.align = TextAlign.left,
  });

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: align == TextAlign.right
        ? CrossAxisAlignment.end
        : CrossAxisAlignment.start,
    children: [
      Text(label, style: const TextStyle(color: _kMuted, fontSize: 10, letterSpacing: 0.5)),
      const SizedBox(height: 3),
      Text(value,
          textAlign: align,
          style: TextStyle(
            color: color,
            fontSize: 14,
            fontWeight: FontWeight.w700,
          )),
    ],
  );
}

class _DetailRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  const _DetailRow({required this.icon, required this.label, required this.value});

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Icon(icon, color: _kMuted, size: 15),
      const SizedBox(width: 8),
      Text('$label:', style: const TextStyle(color: _kMuted, fontSize: 12)),
      const SizedBox(width: 8),
      Expanded(
        child: Text(
          value,
          style: const TextStyle(color: _kWhite, fontSize: 13, fontWeight: FontWeight.w600),
          overflow: TextOverflow.ellipsis,
        ),
      ),
    ],
  );
}

class _PayRow extends StatelessWidget {
  final String label;
  final String value;
  final Color valueColor;
  const _PayRow({required this.label, required this.value, this.valueColor = _kWhite});

  @override
  Widget build(BuildContext context) => Row(
    mainAxisAlignment: MainAxisAlignment.spaceBetween,
    children: [
      Text(label, style: const TextStyle(color: _kMuted, fontSize: 13)),
      Text(value, style: TextStyle(color: valueColor, fontSize: 13, fontWeight: FontWeight.w700)),
    ],
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
        const Icon(Icons.error_outline_rounded, color: AppTheme.danger, size: 52),
        const SizedBox(height: 16),
        Text(message, textAlign: TextAlign.center, style: const TextStyle(color: _kMuted)),
        const SizedBox(height: 20),
        ElevatedButton(
          onPressed: onRetry,
          style: ElevatedButton.styleFrom(backgroundColor: _kGold, foregroundColor: _kNavy),
          child: const Text('Retry'),
        ),
      ]),
    ),
  );
}
