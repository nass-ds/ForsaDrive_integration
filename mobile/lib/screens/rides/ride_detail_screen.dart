import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';
import '../../models/ride_model.dart';
import '../../providers/auth_provider.dart';
import '../../services/map_service.dart';
import '../../services/ride_service.dart';
import '../../utils/app_theme.dart';

// ── Color aliases ─────────────────────────────────────────────────────────────
const _kNavy    = AppTheme.primary;
const _kCard    = AppTheme.darkCard;
const _kSurface = AppTheme.darkSurface;
const _kBorder  = Color(0xFF1E3A5F);
const _kGold    = AppTheme.accent;
const _kGreen   = AppTheme.success;
const _kRed     = AppTheme.danger;
const _kMuted   = Color(0xFF8A9BB5);
const _kWhite   = Colors.white;

class RideDetailScreen extends StatefulWidget {
  final int rideId;
  const RideDetailScreen({super.key, required this.rideId});

  @override
  State<RideDetailScreen> createState() => _RideDetailScreenState();
}

class _RideDetailScreenState extends State<RideDetailScreen> {
  RideModel? _ride;
  bool _loading = true;
  String? _error;
  int _seats = 1;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final ride = await context.read<RideService>().getRideDetail(widget.rideId);
      if (mounted) setState(() { _ride = ride; _loading = false; });
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _loading = false; });
    }
  }

  Future<void> _markDeparture() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => _ConfirmActionDialog(
        icon: Icons.directions_run_rounded,
        color: _kGreen,
        title: 'Mark Departure',
        message: 'Confirm that the ride has departed from ${_ride!.fromLocation}?',
        confirmLabel: 'Depart Now',
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await context.read<RideService>().markDeparture(widget.rideId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Ride marked as departed!'), backgroundColor: AppTheme.success),
      );
      _load();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: _kRed),
      );
    }
  }

  Future<void> _markArrival() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => _ConfirmActionDialog(
        icon: Icons.flag_rounded,
        color: _kGold,
        title: 'Mark Arrival',
        message: 'Confirm that the ride has arrived at ${_ride!.toLocation}?',
        confirmLabel: 'Arrive Now',
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await context.read<RideService>().markArrival(widget.rideId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Ride marked as arrived!'), backgroundColor: AppTheme.success),
      );
      // Navigate to cash payment confirmation screen
      context.push('/ride/${widget.rideId}/confirm');
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: _kRed),
      );
    }
  }

  Future<void> _book({String? promoCode, int orgDiscount = 0}) async {
    final ride = _ride!;
    final user = context.read<AuthProvider>().user!;
    final isStudent = user.isStudent;
    double price = isStudent ? ride.studentPrice : ride.price;
    if (orgDiscount > 0) price = price * (1 - orgDiscount / 100);
    final total = price * _seats * 0.5;

    final confirm = await showDialog<bool>(
      context: context,
      builder: (_) => _ConfirmDialog(
        ride: ride,
        seats: _seats,
        pricePerSeat: price,
        upfront: total,
        isStudent: isStudent,
        orgDiscount: orgDiscount,
        promoCode: promoCode,
        walletBalance: user.balance,
      ),
    );
    if (confirm != true) return;

    try {
      await context.read<RideService>().bookRide(ride.id, _seats, promoCode: promoCode);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Booking submitted!'), backgroundColor: AppTheme.success),
      );
      context.pop();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: _kRed),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _kNavy,
      appBar: AppBar(
        backgroundColor: AppTheme.primaryDark,
        foregroundColor: _kWhite,
        title: const Text('Ride Details'),
        elevation: 0,
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: _kGold))
          : _error != null
              ? _ErrorView(message: _error!, onRetry: _load)
              : _ride == null
                  ? const SizedBox()
                  : _Body(
                      ride: _ride!,
                      seats: _seats,
                      onSeatsChanged: (v) => setState(() => _seats = v),
                      onBook: ({promoCode, orgDiscount = 0}) => _book(promoCode: promoCode, orgDiscount: orgDiscount),
                      onMarkDeparture: _markDeparture,
                      onMarkArrival: _markArrival,
                      onViewPayments: () => context.push('/ride/${widget.rideId}/confirm'),
                    ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Body
// ─────────────────────────────────────────────────────────────────────────────
class _Body extends StatelessWidget {
  final RideModel ride;
  final int seats;
  final ValueChanged<int> onSeatsChanged;
  final void Function({String? promoCode, int orgDiscount}) onBook;
  final VoidCallback onMarkDeparture;
  final VoidCallback onMarkArrival;
  final VoidCallback onViewPayments;

  const _Body({
    required this.ride,
    required this.seats,
    required this.onSeatsChanged,
    required this.onBook,
    required this.onMarkDeparture,
    required this.onMarkArrival,
    required this.onViewPayments,
  });

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user!;
    final isDriver = user.id == ride.driverId;
    final isStudent = user.isStudent;
    final effectivePrice = isStudent ? ride.studentPrice : ride.price;
    final seatsLeft = ride.seatsLeft ?? ride.availableSeats;
    final alreadyBooked = ride.bookingId != null;

    return Column(
      children: [
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                _DriverCard(ride: ride),
                const SizedBox(height: 14),
                _RouteCard(ride: ride),
                const SizedBox(height: 14),
                _RouteMapCard(
                  fromLocation: ride.fromLocation,
                  toLocation: ride.toLocation,
                ),
                const SizedBox(height: 14),
                _VehicleCard(ride: ride),
                const SizedBox(height: 14),
                _PriceCard(
                  ride: ride,
                  isStudent: isStudent,
                  effectivePrice: effectivePrice,
                  seatsLeft: seatsLeft,
                ),
                if (isDriver) ...[
                  const SizedBox(height: 14),
                  _DriverActionsCard(
                    ride: ride,
                    onMarkDeparture: onMarkDeparture,
                    onMarkArrival: onMarkArrival,
                    onViewPayments: onViewPayments,
                  ),
                ],
                if (alreadyBooked) ...[
                  const SizedBox(height: 14),
                  _AlreadyBookedBanner(status: ride.bookingStatus ?? 'pending'),
                ],
              ],
            ),
          ),
        ),
        if (!isDriver && !alreadyBooked && seatsLeft > 0)
          _BottomBar(
            ride: ride,
            seats: seats,
            seatsLeft: seatsLeft,
            basePrice: effectivePrice,
            walletBalance: user.balance,
            onSeatsChanged: onSeatsChanged,
            onBook: onBook,
          ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Driver Card
// ─────────────────────────────────────────────────────────────────────────────
class _DriverCard extends StatelessWidget {
  final RideModel ride;
  const _DriverCard({required this.ride});

  @override
  Widget build(BuildContext context) {
    final name = ride.driverName ?? 'Driver';
    final score = ride.driverScore ?? 5.0;

    return _Panel(
      child: Row(
        children: [
          Container(
            width: 52,
            height: 52,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [_kGold, AppTheme.accentDark],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Center(
              child: Text(
                name[0].toUpperCase(),
                style: const TextStyle(
                  color: _kNavy, fontSize: 22, fontWeight: FontWeight.w800,
                ),
              ),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    color: _kWhite, fontSize: 16, fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    const Icon(Icons.star_rounded, color: _kGold, size: 14),
                    const SizedBox(width: 3),
                    Text(
                      score.toStringAsFixed(1),
                      style: const TextStyle(color: _kGold, fontSize: 13, fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(width: 8),
                    Container(width: 3, height: 3, decoration: const BoxDecoration(color: _kMuted, shape: BoxShape.circle)),
                    const SizedBox(width: 8),
                    const Text('Verified Driver', style: TextStyle(color: _kMuted, fontSize: 12)),
                  ],
                ),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: _kGreen.withOpacity(0.12),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: _kGreen.withOpacity(0.3)),
            ),
            child: Text(
              '${(score / 5 * 100).round()}% Reliability',
              style: const TextStyle(color: _kGreen, fontSize: 11, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Route Card
// ─────────────────────────────────────────────────────────────────────────────
class _RouteCard extends StatelessWidget {
  final RideModel ride;
  const _RouteCard({required this.ride});

  @override
  Widget build(BuildContext context) {
    final dateStr = DateFormat('EEEE, MMM d').format(ride.departureTime);
    final timeStr = DateFormat('h:mm a').format(ride.departureTime);

    return _Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(icon: Icons.route_rounded, label: 'Route'),
          const SizedBox(height: 14),
          IntrinsicHeight(
            child: Row(
              children: [
                // Timeline
                Column(
                  children: [
                    _Dot(color: _kGreen),
                    Expanded(
                      child: Container(
                        width: 2,
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            colors: [_kGreen, _kRed],
                            begin: Alignment.topCenter,
                            end: Alignment.bottomCenter,
                          ),
                        ),
                      ),
                    ),
                    _Dot(color: _kRed),
                  ],
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('From', style: TextStyle(color: _kMuted, fontSize: 11)),
                          const SizedBox(height: 2),
                          Text(ride.fromLocation,
                              style: const TextStyle(color: _kWhite, fontSize: 15, fontWeight: FontWeight.w700)),
                        ],
                      ),
                      const SizedBox(height: 20),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('To', style: TextStyle(color: _kMuted, fontSize: 11)),
                          const SizedBox(height: 2),
                          Text(ride.toLocation,
                              style: const TextStyle(color: _kWhite, fontSize: 15, fontWeight: FontWeight.w700)),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: _kNavy.withOpacity(0.5),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: _kBorder),
            ),
            child: Row(
              children: [
                const Icon(Icons.calendar_today_rounded, color: _kGold, size: 16),
                const SizedBox(width: 8),
                Text(dateStr, style: const TextStyle(color: _kWhite, fontWeight: FontWeight.w600, fontSize: 13)),
                const SizedBox(width: 16),
                const Icon(Icons.access_time_rounded, color: _kGold, size: 16),
                const SizedBox(width: 8),
                Text(timeStr, style: const TextStyle(color: _kWhite, fontWeight: FontWeight.w600, fontSize: 13)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Driver Actions Card
// ─────────────────────────────────────────────────────────────────────────────
class _DriverActionsCard extends StatelessWidget {
  final RideModel ride;
  final VoidCallback onMarkDeparture;
  final VoidCallback onMarkArrival;
  final VoidCallback onViewPayments;

  const _DriverActionsCard({
    required this.ride,
    required this.onMarkDeparture,
    required this.onMarkArrival,
    required this.onViewPayments,
  });

  @override
  Widget build(BuildContext context) {
    final status = ride.status;
    final (statusColor, statusIcon, statusLabel) = switch (status) {
      'in_progress' => (_kGold, Icons.directions_car_filled_rounded, 'In Progress'),
      'completed'   => (_kGreen, Icons.check_circle_rounded, 'Completed'),
      'cancelled'   => (_kRed, Icons.cancel_rounded, 'Cancelled'),
      _             => (_kMuted, Icons.schedule_rounded, 'Open'),
    };

    return _Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(icon: Icons.admin_panel_settings_rounded, label: 'Driver Actions'),
          const SizedBox(height: 14),

          // Status badge
          Row(
            children: [
              const Text('Current Status:', style: TextStyle(color: _kMuted, fontSize: 13)),
              const SizedBox(width: 10),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                decoration: BoxDecoration(
                  color: statusColor.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: statusColor.withOpacity(0.4)),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(statusIcon, color: statusColor, size: 14),
                  const SizedBox(width: 6),
                  Text(statusLabel,
                      style: TextStyle(
                          color: statusColor,
                          fontSize: 12,
                          fontWeight: FontWeight.w700)),
                ]),
              ),
            ],
          ),
          const SizedBox(height: 16),

          // Action buttons based on status
          if (status == 'open' || status == 'confirmed') ...[
            _ActionButton(
              icon: Icons.directions_run_rounded,
              label: 'Mark Departure',
              subtitle: 'Start this ride when all passengers are onboard',
              color: _kGreen,
              onTap: onMarkDeparture,
            ),
          ] else if (status == 'in_progress') ...[
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: _kGold.withOpacity(0.06),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: _kGold.withOpacity(0.2)),
              ),
              child: const Row(children: [
                Icon(Icons.radio_button_checked, color: _kGold, size: 14),
                SizedBox(width: 8),
                Text('Ride is currently in progress',
                    style: TextStyle(color: _kGold, fontSize: 13, fontWeight: FontWeight.w600)),
              ]),
            ),
            const SizedBox(height: 12),
            _ActionButton(
              icon: Icons.flag_rounded,
              label: 'Mark Arrival',
              subtitle: 'Confirm destination reached · collect cash payments',
              color: _kGold,
              onTap: onMarkArrival,
            ),
          ] else if (status == 'completed') ...[
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: _kGreen.withOpacity(0.06),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: _kGreen.withOpacity(0.2)),
              ),
              child: const Row(children: [
                Icon(Icons.check_circle_outline_rounded, color: _kGreen, size: 14),
                SizedBox(width: 8),
                Text('Ride completed',
                    style: TextStyle(color: _kGreen, fontSize: 13, fontWeight: FontWeight.w600)),
              ]),
            ),
            const SizedBox(height: 12),
            _ActionButton(
              icon: Icons.payments_rounded,
              label: 'View Payment Summary',
              subtitle: 'Review cash collected from passengers',
              color: AppTheme.info,
              onTap: onViewPayments,
            ),
          ],
        ],
      ),
    );
  }
}

class _ActionButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final String subtitle;
  final Color color;
  final VoidCallback onTap;

  const _ActionButton({
    required this.icon,
    required this.label,
    required this.subtitle,
    required this.color,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: color.withOpacity(0.08),
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: color.withOpacity(0.3)),
          ),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: color.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(icon, color: color, size: 22),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label,
                        style: TextStyle(
                            color: color,
                            fontSize: 14,
                            fontWeight: FontWeight.w700)),
                    const SizedBox(height: 2),
                    Text(subtitle,
                        style: const TextStyle(color: _kMuted, fontSize: 11)),
                  ],
                ),
              ),
              Icon(Icons.arrow_forward_ios_rounded, color: color, size: 14),
            ],
          ),
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Confirm Action Dialog
// ─────────────────────────────────────────────────────────────────────────────
class _ConfirmActionDialog extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String title;
  final String message;
  final String confirmLabel;

  const _ConfirmActionDialog({
    required this.icon,
    required this.color,
    required this.title,
    required this.message,
    required this.confirmLabel,
  });

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: _kSurface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      title: Row(children: [
        Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: color.withOpacity(0.12),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(icon, color: color, size: 20),
        ),
        const SizedBox(width: 12),
        Text(title,
            style: const TextStyle(
                color: _kWhite, fontSize: 17, fontWeight: FontWeight.w700)),
      ]),
      content: Text(message,
          style: const TextStyle(color: _kMuted, fontSize: 14, height: 1.5)),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: const Text('Cancel', style: TextStyle(color: _kMuted)),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(context, true),
          style: ElevatedButton.styleFrom(
            backgroundColor: color,
            foregroundColor: _kNavy,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          child: Text(confirmLabel,
              style: const TextStyle(fontWeight: FontWeight.w700)),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Vehicle Card
// ─────────────────────────────────────────────────────────────────────────────
class _VehicleCard extends StatelessWidget {
  final RideModel ride;
  const _VehicleCard({required this.ride});

  @override
  Widget build(BuildContext context) {
    final type = ride.vehicleType ?? 'Car';

    return _Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(icon: Icons.directions_car_rounded, label: 'Vehicle'),
          const SizedBox(height: 12),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppTheme.info.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(Icons.directions_car_filled_rounded, color: AppTheme.info, size: 24),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  type[0].toUpperCase() + type.substring(1),
                  style: const TextStyle(color: _kWhite, fontSize: 15, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _AmenityChip(Icons.ac_unit_rounded, 'Air Conditioning', _kGold),
              _AmenityChip(Icons.wifi_rounded, 'Wi-Fi', AppTheme.info),
              _AmenityChip(Icons.luggage_rounded, 'Luggage', _kGreen),
            ],
          ),
        ],
      ),
    );
  }
}

class _AmenityChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color color;
  const _AmenityChip(this.icon, this.label, this.color);

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
    decoration: BoxDecoration(
      color: color.withOpacity(0.1),
      borderRadius: BorderRadius.circular(20),
      border: Border.all(color: color.withOpacity(0.3)),
    ),
    child: Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, color: color, size: 13),
      const SizedBox(width: 5),
      Text(label, style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w600)),
    ]),
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Price Card
// ─────────────────────────────────────────────────────────────────────────────
class _PriceCard extends StatelessWidget {
  final RideModel ride;
  final bool isStudent;
  final double effectivePrice;
  final int seatsLeft;

  const _PriceCard({
    required this.ride,
    required this.isStudent,
    required this.effectivePrice,
    required this.seatsLeft,
  });

  @override
  Widget build(BuildContext context) {
    return _Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(icon: Icons.payments_rounded, label: 'Pricing'),
          const SizedBox(height: 12),
          Row(
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (isStudent) ...[
                    Text(
                      '${ride.price.toStringAsFixed(2)} TND',
                      style: const TextStyle(
                        color: _kMuted,
                        fontSize: 13,
                        decoration: TextDecoration.lineThrough,
                      ),
                    ),
                    const SizedBox(height: 2),
                  ],
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        effectivePrice.toStringAsFixed(2),
                        style: const TextStyle(
                          color: _kGold, fontSize: 28, fontWeight: FontWeight.w800, letterSpacing: -0.5,
                        ),
                      ),
                      const Padding(
                        padding: EdgeInsets.only(bottom: 4, left: 4),
                        child: Text('TND / seat', style: TextStyle(color: _kMuted, fontSize: 12)),
                      ),
                    ],
                  ),
                ],
              ),
              const Spacer(),
              if (isStudent)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: _kGreen.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: _kGreen.withOpacity(0.3)),
                  ),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.school_rounded, color: _kGreen, size: 14),
                      SizedBox(width: 5),
                      Text('50% OFF', style: TextStyle(color: _kGreen, fontSize: 12, fontWeight: FontWeight.w800)),
                    ],
                  ),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              const Icon(Icons.info_outline_rounded, color: _kMuted, size: 14),
              const SizedBox(width: 6),
              const Text('50% paid online · 50% cash on arrival', style: TextStyle(color: _kMuted, fontSize: 12)),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: seatsLeft <= 2 ? _kRed.withOpacity(0.1) : _kGreen.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: (seatsLeft <= 2 ? _kRed : _kGreen).withOpacity(0.3)),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.airline_seat_recline_normal_rounded, color: seatsLeft <= 2 ? _kRed : _kGreen, size: 14),
                  const SizedBox(width: 5),
                  Text(
                    '$seatsLeft seat${seatsLeft != 1 ? 's' : ''} available',
                    style: TextStyle(color: seatsLeft <= 2 ? _kRed : _kGreen, fontSize: 12, fontWeight: FontWeight.w700),
                  ),
                ]),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Already Booked Banner
// ─────────────────────────────────────────────────────────────────────────────
class _AlreadyBookedBanner extends StatelessWidget {
  final String status;
  const _AlreadyBookedBanner({required this.status});

  @override
  Widget build(BuildContext context) {
    final color = status == 'confirmed' ? _kGreen : status == 'cancelled' ? _kRed : _kGold;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withOpacity(0.4)),
      ),
      child: Row(
        children: [
          Icon(Icons.bookmark_rounded, color: color, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'You have a ${status.toUpperCase()} booking for this ride.',
              style: TextStyle(color: color, fontWeight: FontWeight.w600, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Bottom Bar (stateful — handles promo code)
// ─────────────────────────────────────────────────────────────────────────────
class _BottomBar extends StatefulWidget {
  final RideModel ride;
  final int seats;
  final int seatsLeft;
  final double basePrice;
  final double walletBalance;
  final ValueChanged<int> onSeatsChanged;
  final void Function({String? promoCode, int orgDiscount}) onBook;

  const _BottomBar({
    required this.ride,
    required this.seats,
    required this.seatsLeft,
    required this.basePrice,
    required this.walletBalance,
    required this.onSeatsChanged,
    required this.onBook,
  });

  @override
  State<_BottomBar> createState() => _BottomBarState();
}

class _BottomBarState extends State<_BottomBar> {
  final _codeCtrl = TextEditingController();
  bool _validating = false;
  int _orgDiscount = 0;
  String? _appliedCode;
  String? _orgName;
  String? _codeError;

  @override
  void dispose() {
    _codeCtrl.dispose();
    super.dispose();
  }

  Future<void> _applyCode() async {
    final code = _codeCtrl.text.trim();
    if (code.isEmpty) return;
    setState(() { _validating = true; _codeError = null; });
    try {
      final res = await context.read<RideService>().validatePromoCode(code);
      setState(() {
        _orgDiscount = (res['discount_percent'] as num).toInt();
        _appliedCode = code.toUpperCase();
        _orgName    = res['org_name'] as String?;
        _codeError  = null;
      });
    } catch (e) {
      setState(() {
        _orgDiscount = 0;
        _appliedCode = null;
        _orgName     = null;
        _codeError   = e.toString().replaceFirst('Exception: ', '');
      });
    } finally {
      if (mounted) setState(() => _validating = false);
    }
  }

  void _removeCode() => setState(() {
    _orgDiscount = 0;
    _appliedCode = null;
    _orgName     = null;
    _codeError   = null;
    _codeCtrl.clear();
  });

  @override
  Widget build(BuildContext context) {
    double price = widget.basePrice;
    if (_orgDiscount > 0) price = price * (1 - _orgDiscount / 100);
    final upfront   = price * widget.seats * 0.5;
    final canAfford = widget.walletBalance >= upfront;

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 20),
      decoration: BoxDecoration(
        color: AppTheme.primaryDark,
        border: Border(top: BorderSide(color: _kBorder)),
        boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.4), blurRadius: 20, offset: const Offset(0, -4))],
      ),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Seat selector
            Row(
              children: [
                const Text('Seats', style: TextStyle(color: _kWhite, fontWeight: FontWeight.w600, fontSize: 14)),
                const Spacer(),
                _SeatsCounter(value: widget.seats, min: 1, max: widget.seatsLeft, onChange: widget.onSeatsChanged),
              ],
            ),
            const SizedBox(height: 10),

            // ── Promo code row ──────────────────────────────────
            if (_appliedCode != null)
              // Applied state — show chip with remove
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: _kGreen.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: _kGreen.withOpacity(0.3)),
                ),
                child: Row(children: [
                  const Icon(Icons.confirmation_number_rounded, color: _kGreen, size: 16),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      '$_appliedCode · $_orgDiscount% off${_orgName != null ? ' ($_orgName)' : ''}',
                      style: const TextStyle(color: _kGreen, fontSize: 12, fontWeight: FontWeight.w700),
                    ),
                  ),
                  GestureDetector(
                    onTap: _removeCode,
                    child: const Icon(Icons.close_rounded, color: _kGreen, size: 16),
                  ),
                ]),
              )
            else
              // Input state
              Row(children: [
                Expanded(
                  child: TextField(
                    controller: _codeCtrl,
                    textCapitalization: TextCapitalization.characters,
                    style: const TextStyle(color: _kWhite, fontSize: 13, letterSpacing: 1),
                    decoration: InputDecoration(
                      hintText: 'Organization promo code',
                      hintStyle: const TextStyle(color: _kMuted, fontSize: 13),
                      prefixIcon: const Icon(Icons.confirmation_number_outlined, color: _kMuted, size: 18),
                      filled: true,
                      fillColor: _kCard,
                      isDense: true,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: _kBorder)),
                      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: _kBorder)),
                      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: _kGold)),
                      errorText: _codeError,
                      errorStyle: const TextStyle(color: _kRed, fontSize: 10),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  height: 42,
                  child: ElevatedButton(
                    onPressed: _validating ? null : _applyCode,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: _kGold.withOpacity(0.15),
                      foregroundColor: _kGold,
                      elevation: 0,
                      side: BorderSide(color: _kGold.withOpacity(0.4)),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      padding: const EdgeInsets.symmetric(horizontal: 14),
                    ),
                    child: _validating
                        ? const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: _kGold))
                        : const Text('Apply', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                  ),
                ),
              ]),

            const SizedBox(height: 10),
            // Cost summary
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (_orgDiscount > 0)
                      Text(
                        '${(widget.basePrice * widget.seats * 0.5).toStringAsFixed(2)} TND',
                        style: const TextStyle(color: _kMuted, fontSize: 11, decoration: TextDecoration.lineThrough),
                      ),
                    Text(
                      'Upfront: ${upfront.toStringAsFixed(2)} TND',
                      style: const TextStyle(color: _kGold, fontSize: 16, fontWeight: FontWeight.w800),
                    ),
                    Text(
                      'Wallet: ${widget.walletBalance.toStringAsFixed(2)} TND',
                      style: TextStyle(color: canAfford ? _kGreen : _kRed, fontSize: 12),
                    ),
                  ],
                ),
                OutlinedButton.icon(
                  onPressed: () => context.push('/ride/${widget.ride.id}/group'),
                  icon: const Icon(Icons.group_rounded, size: 16),
                  label: const Text('Group'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: _kGold,
                    side: const BorderSide(color: _kGold),
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                ),
              ],
            ),
            if (!canAfford) ...[
              const SizedBox(height: 6),
              const Row(children: [
                Icon(Icons.warning_amber_rounded, color: _kRed, size: 14),
                SizedBox(width: 6),
                Text('Insufficient wallet balance. Deposit funds in Payments.', style: TextStyle(color: _kRed, fontSize: 11)),
              ]),
            ],
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              height: 50,
              child: ElevatedButton(
                onPressed: canAfford ? () => widget.onBook(promoCode: _appliedCode, orgDiscount: _orgDiscount) : null,
                style: ElevatedButton.styleFrom(
                  backgroundColor: _kGold,
                  foregroundColor: _kNavy,
                  disabledBackgroundColor: _kGold.withOpacity(0.4),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  elevation: 0,
                ),
                child: Text(
                  'Book ${widget.seats > 1 ? '${widget.seats} Seats' : 'Now'} · ${upfront.toStringAsFixed(2)} TND',
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Confirmation dialog
// ─────────────────────────────────────────────────────────────────────────────
class _ConfirmDialog extends StatelessWidget {
  final RideModel ride;
  final int seats;
  final double pricePerSeat;
  final double upfront;
  final bool isStudent;
  final int orgDiscount;
  final String? promoCode;
  final double walletBalance;

  const _ConfirmDialog({
    required this.ride,
    required this.seats,
    required this.pricePerSeat,
    required this.upfront,
    required this.isStudent,
    required this.walletBalance,
    this.orgDiscount = 0,
    this.promoCode,
  });

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: _kSurface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      title: const Row(children: [
        Icon(Icons.confirmation_number_rounded, color: _kGold, size: 22),
        SizedBox(width: 10),
        Text('Confirm Booking', style: TextStyle(color: _kWhite, fontSize: 17, fontWeight: FontWeight.w700)),
      ]),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _DialogRow('Route', '${ride.fromLocation} → ${ride.toLocation}'),
          _DialogRow('Date', DateFormat('EEE, MMM d • h:mm a').format(ride.departureTime)),
          _DialogRow('Seats', '$seats'),
          _DialogRow('Price/seat', '${pricePerSeat.toStringAsFixed(2)} TND${isStudent ? ' (student 50%)' : ''}${orgDiscount > 0 ? ' (org $orgDiscount%)' : ''}'),
          if (promoCode != null)
            _DialogRow('Promo code', promoCode!, highlight: true),
          const Divider(color: _kBorder, height: 20),
          _DialogRow('Pay now (50%)', '${upfront.toStringAsFixed(2)} TND', highlight: true),
          _DialogRow('Pay on arrival (50%)', '${upfront.toStringAsFixed(2)} TND'),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: _kGold.withOpacity(0.08),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: _kGold.withOpacity(0.2)),
            ),
            child: Row(children: [
              const Icon(Icons.account_balance_wallet_rounded, color: _kGold, size: 16),
              const SizedBox(width: 8),
              Text(
                'Wallet after: ${(walletBalance - upfront).toStringAsFixed(2)} TND',
                style: const TextStyle(color: _kGold, fontSize: 12, fontWeight: FontWeight.w600),
              ),
            ]),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: const Text('Cancel', style: TextStyle(color: _kMuted)),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(context, true),
          style: ElevatedButton.styleFrom(
            backgroundColor: _kGold, foregroundColor: _kNavy,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          child: const Text('Confirm', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
      ],
    );
  }
}

class _DialogRow extends StatelessWidget {
  final String label;
  final String value;
  final bool highlight;
  const _DialogRow(this.label, this.value, {this.highlight = false});

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(color: _kMuted, fontSize: 13)),
        Text(value, style: TextStyle(
          color: highlight ? _kGold : _kWhite,
          fontSize: 13,
          fontWeight: highlight ? FontWeight.w700 : FontWeight.w500,
        )),
      ],
    ),
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Route Map Card
// ─────────────────────────────────────────────────────────────────────────────
class _RouteMapCard extends StatefulWidget {
  final String fromLocation;
  final String toLocation;
  const _RouteMapCard({required this.fromLocation, required this.toLocation});

  @override
  State<_RouteMapCard> createState() => _RouteMapCardState();
}

class _RouteMapCardState extends State<_RouteMapCard> {
  _MapData? _data;
  bool _loading = true;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _loadMap();
  }

  Future<void> _loadMap() async {
    final svc = MapService();
    final from = await svc.geocode(widget.fromLocation);
    final to = await svc.geocode(widget.toLocation);
    if (!mounted) return;
    if (from == null || to == null) {
      setState(() { _failed = true; _loading = false; });
      return;
    }
    final route = await svc.getRoute(from, to);
    if (!mounted) return;
    setState(() {
      _data = _MapData(from: from, to: to, route: route);
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: _kCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: _kBorder),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
            child: Row(children: [
              const Icon(Icons.map_rounded, color: _kGold, size: 16),
              const SizedBox(width: 8),
              const Text(
                'ROUTE MAP',
                style: TextStyle(
                  color: _kGold,
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 0.5,
                ),
              ),
              const Spacer(),
              if (_loading)
                const SizedBox(
                  width: 14,
                  height: 14,
                  child: CircularProgressIndicator(
                      color: _kGold, strokeWidth: 2),
                ),
            ]),
          ),
          // Map body
          ClipRRect(
            borderRadius: const BorderRadius.only(
              bottomLeft: Radius.circular(18),
              bottomRight: Radius.circular(18),
            ),
            child: SizedBox(
              height: 220,
              child: _loading
                  ? const Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          CircularProgressIndicator(color: _kGold),
                          SizedBox(height: 10),
                          Text('Loading route…',
                              style: TextStyle(color: _kMuted, fontSize: 12)),
                        ],
                      ),
                    )
                  : _failed
                      ? const Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.map_outlined,
                                  color: _kMuted, size: 32),
                              SizedBox(height: 8),
                              Text('Map unavailable',
                                  style: TextStyle(
                                      color: _kMuted, fontSize: 13)),
                            ],
                          ),
                        )
                      : _MapView(data: _data!),
            ),
          ),
        ],
      ),
    );
  }
}

class _MapData {
  final LatLng from;
  final LatLng to;
  final List<LatLng> route;
  const _MapData({required this.from, required this.to, required this.route});
}

class _MapView extends StatelessWidget {
  final _MapData data;
  const _MapView({required this.data});

  @override
  Widget build(BuildContext context) {
    // Compute bounding box to fit both points
    final allPoints = [data.from, data.to, ...data.route];
    return FlutterMap(
      options: MapOptions(
        initialCameraFit: CameraFit.coordinates(
          coordinates: allPoints,
          padding: const EdgeInsets.all(40),
        ),
        interactionOptions: const InteractionOptions(
          flags: InteractiveFlag.pinchZoom | InteractiveFlag.drag,
        ),
      ),
      children: [
        // Base tile layer (OpenStreetMap — free, no API key)
        TileLayer(
          urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
          userAgentPackageName: 'com.forsadrive.app',
        ),
        // Driving route polyline
        PolylineLayer(
          polylines: [
            Polyline(
              points: data.route,
              strokeWidth: 4,
              color: const Color(0xFF4FC3F7),
              borderStrokeWidth: 1.5,
              borderColor: const Color(0xFF0277BD),
            ),
          ],
        ),
        // Pickup + dropoff markers
        MarkerLayer(
          markers: [
            Marker(
              point: data.from,
              width: 36,
              height: 36,
              child: _MapMarker(
                color: _kGreen,
                icon: Icons.trip_origin,
              ),
            ),
            Marker(
              point: data.to,
              width: 36,
              height: 36,
              child: _MapMarker(
                color: _kRed,
                icon: Icons.location_on,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _MapMarker extends StatelessWidget {
  final Color color;
  final IconData icon;
  const _MapMarker({required this.color, required this.icon});

  @override
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(
          color: color,
          shape: BoxShape.circle,
          boxShadow: [
            BoxShadow(
                color: color.withOpacity(0.5),
                blurRadius: 8,
                spreadRadius: 2),
          ],
        ),
        child: Icon(icon, color: Colors.white, size: 20),
      );
}

// ─────────────────────────────────────────────────────────────────────────────
// Shared small widgets
// ─────────────────────────────────────────────────────────────────────────────
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

class _SectionLabel extends StatelessWidget {
  final IconData icon;
  final String label;
  const _SectionLabel({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) => Row(children: [
    Icon(icon, color: _kGold, size: 16),
    const SizedBox(width: 8),
    Text(label, style: const TextStyle(color: _kGold, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 0.5)),
  ]);
}

class _Dot extends StatelessWidget {
  final Color color;
  const _Dot({required this.color});

  @override
  Widget build(BuildContext context) => Container(
    width: 12, height: 12,
    decoration: BoxDecoration(
      color: color, shape: BoxShape.circle,
      boxShadow: [BoxShadow(color: color.withOpacity(0.5), blurRadius: 6, spreadRadius: 1)],
    ),
  );
}

class _SeatsCounter extends StatelessWidget {
  final int value;
  final int min;
  final int max;
  final ValueChanged<int> onChange;
  const _SeatsCounter({required this.value, required this.min, required this.max, required this.onChange});

  @override
  Widget build(BuildContext context) => Row(children: [
    _CounterBtn(Icons.remove, value > min ? () => onChange(value - 1) : null),
    Container(
      margin: const EdgeInsets.symmetric(horizontal: 12),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      decoration: BoxDecoration(color: _kSurface, borderRadius: BorderRadius.circular(10), border: Border.all(color: _kBorder)),
      child: Text('$value', style: const TextStyle(color: _kWhite, fontSize: 16, fontWeight: FontWeight.w700)),
    ),
    _CounterBtn(Icons.add, value < max ? () => onChange(value + 1) : null),
  ]);
}

class _CounterBtn extends StatelessWidget {
  final IconData icon;
  final VoidCallback? onTap;
  const _CounterBtn(this.icon, this.onTap);

  @override
  Widget build(BuildContext context) => GestureDetector(
    onTap: onTap,
    child: Container(
      width: 34, height: 34,
      decoration: BoxDecoration(
        color: onTap != null ? _kGold.withOpacity(0.15) : _kBorder.withOpacity(0.3),
        borderRadius: BorderRadius.circular(9),
        border: Border.all(color: onTap != null ? _kGold.withOpacity(0.4) : _kBorder),
      ),
      child: Icon(icon, color: onTap != null ? _kGold : _kMuted, size: 18),
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
        Text(message, textAlign: TextAlign.center, style: const TextStyle(color: _kMuted)),
        const SizedBox(height: 20),
        ElevatedButton(onPressed: onRetry, child: const Text('Retry')),
      ]),
    ),
  );
}
