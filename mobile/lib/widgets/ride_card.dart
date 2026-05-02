import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models/ride_model.dart';
import '../utils/app_theme.dart';
import '../config/api_config.dart';
import '../l10n/app_localizations.dart';

class RideCard extends StatelessWidget {
  final RideModel ride;
  final bool showBookButton;
  final bool showCancelButton;
  final VoidCallback? onBook;
  final VoidCallback? onCancel;

  const RideCard({
    super.key,
    required this.ride,
    this.showBookButton = false,
    this.showCancelButton = false,
    this.onBook,
    this.onCancel,
  });

  @override
  Widget build(BuildContext context) {
    final dateStr = DateFormat('EEE, MMM d • h:mm a').format(ride.departureTime);
    final seatsLeft = ride.seatsLeft ?? ride.availableSeats;

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Match badge (only on search results)
            if (ride.matchScore != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: _MatchBadge(score: ride.matchScore!),
              ),
            // Route
            Row(
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      const Icon(Icons.trip_origin, color: AppTheme.success, size: 16),
                      const SizedBox(width: 6),
                      Text(ride.fromLocation, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                    ]),
                    Container(
                      margin: const EdgeInsets.only(left: 7),
                      width: 2, height: 20,
                      color: AppTheme.border,
                    ),
                    Row(children: [
                      const Icon(Icons.location_on, color: AppTheme.danger, size: 16),
                      const SizedBox(width: 6),
                      Text(ride.toLocation, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                    ]),
                  ],
                ),
                const Spacer(),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      '${ride.price.toStringAsFixed(1)} TND',
                      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppTheme.primary),
                    ),
                    const Text('per seat', style: TextStyle(color: AppTheme.textSecondary, fontSize: 12)),
                  ],
                ),
              ],
            ),
            const Divider(height: 20),
            // Meta row
            Row(
              children: [
                // Driver
                if (ride.driverName != null) ...[
                  CircleAvatar(
                    radius: 14,
                    backgroundImage: NetworkImage(ApiConfig.profilePicture(ride.driverPic)),
                    onBackgroundImageError: (_, __) {},
                  ),
                  const SizedBox(width: 6),
                  Text(ride.driverName!, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  const SizedBox(width: 4),
                  const Icon(Icons.star, color: AppTheme.accent, size: 14),
                  Text(ride.driverScore?.toStringAsFixed(1) ?? '5.0', style: const TextStyle(fontSize: 12)),
                  const SizedBox(width: 12),
                ],
                const Icon(Icons.schedule, size: 14, color: AppTheme.textSecondary),
                const SizedBox(width: 4),
                Text(dateStr, style: const TextStyle(color: AppTheme.textSecondary, fontSize: 12)),
                const Spacer(),
                _SeatChip(seatsLeft: seatsLeft),
              ],
            ),
            // Vehicle type
            if (ride.vehicleType != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Row(children: [
                  const Icon(Icons.directions_car, size: 14, color: AppTheme.textSecondary),
                  const SizedBox(width: 4),
                  Text(ride.vehicleType!.toUpperCase(), style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary, letterSpacing: 0.5)),
                ]),
              ),
            // Amenities
            _AmenityRow(ride: ride),
            // Booking status (for my rides)
            if (ride.bookingStatus != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: _StatusBadge(ride.bookingStatus!),
              ),
            // Actions
            if (showBookButton || showCancelButton)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    if (showCancelButton)
                      OutlinedButton(
                        onPressed: onCancel,
                        style: OutlinedButton.styleFrom(foregroundColor: AppTheme.danger, side: const BorderSide(color: AppTheme.danger)),
                        child: const Text('Cancel'),
                      ),
                    if (showBookButton) ...[
                      const SizedBox(width: 8),
                      ElevatedButton.icon(
                        onPressed: seatsLeft > 0 ? onBook : null,
                        icon: const Icon(Icons.check_circle_outline, size: 16),
                        label: const Text('Book Now'),
                      ),
                    ],
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _AmenityRow extends StatelessWidget {
  final RideModel ride;
  const _AmenityRow({required this.ride});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final chips = <Widget>[];
    if (ride.hasAc == true) chips.add(_AmenityChip(icon: Icons.ac_unit, label: l10n.amenityAc));
    if (ride.hasWifi == true) chips.add(_AmenityChip(icon: Icons.wifi, label: l10n.amenityWifi));
    if (ride.hasMusic == true) chips.add(_AmenityChip(icon: Icons.music_note, label: l10n.amenityMusic));
    if (ride.isAccessible == true) chips.add(_AmenityChip(icon: Icons.accessible, label: l10n.amenityAccessible));
    if (chips.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Wrap(spacing: 6, runSpacing: 4, children: chips),
    );
  }
}

class _AmenityChip extends StatelessWidget {
  final IconData icon;
  final String label;
  const _AmenityChip({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: AppTheme.info.withOpacity(0.1),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppTheme.info.withOpacity(0.3)),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 12, color: AppTheme.info),
        const SizedBox(width: 3),
        Text(label, style: const TextStyle(fontSize: 11, color: AppTheme.info, fontWeight: FontWeight.w500)),
      ]),
    );
  }
}

class _SeatChip extends StatelessWidget {
  final int seatsLeft;
  const _SeatChip({required this.seatsLeft});

  @override
  Widget build(BuildContext context) {
    final color = seatsLeft == 0 ? AppTheme.danger : seatsLeft <= 2 ? AppTheme.warning : AppTheme.success;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: color.withOpacity(0.1), borderRadius: BorderRadius.circular(20)),
      child: Row(children: [
        Icon(Icons.airline_seat_recline_normal, size: 13, color: color),
        const SizedBox(width: 3),
        Text('$seatsLeft left', style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
      ]),
    );
  }
}

class _StatusBadge extends StatelessWidget {
  final String status;
  const _StatusBadge(this.status);

  @override
  Widget build(BuildContext context) {
    final (color, icon) = switch (status) {
      'confirmed' => (AppTheme.success, Icons.check_circle),
      'pending' => (AppTheme.warning, Icons.schedule),
      'cancelled' => (AppTheme.danger, Icons.cancel),
      'completed' => (AppTheme.info, Icons.done_all),
      _ => (AppTheme.textSecondary, Icons.info_outline),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: color.withOpacity(0.1), borderRadius: BorderRadius.circular(20)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 13, color: color),
        const SizedBox(width: 4),
        Text(status.toUpperCase(), style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: 0.5)),
      ]),
    );
  }
}

// ── Match score badge ─────────────────────────────────────────────────────────

class _MatchBadge extends StatelessWidget {
  final int score;
  const _MatchBadge({required this.score});

  @override
  Widget build(BuildContext context) {
    final (label, color, icon) = switch (score) {
      >= 90 => ('Perfect Match', const Color(0xFF00897B), Icons.verified),
      >= 75 => ('Great Match',   const Color(0xFF1E88E5), Icons.thumb_up),
      >= 60 => ('Good Match',    const Color(0xFF43A047), Icons.check_circle),
      _     => ('$score% Match', AppTheme.textSecondary,  Icons.circle_outlined),
    };

    return Row(
      children: [
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [color.withOpacity(0.15), color.withOpacity(0.05)],
            ),
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: color.withOpacity(0.4)),
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, size: 13, color: color),
            const SizedBox(width: 5),
            Text(
              score >= 60 ? '$score% · $label' : '$score% Match',
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: color,
                letterSpacing: 0.2,
              ),
            ),
          ]),
        ),
      ],
    );
  }
}
