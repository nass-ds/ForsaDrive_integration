import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../../models/ride_model.dart';
import '../../providers/auth_provider.dart';
import '../../services/ride_service.dart';
import '../../utils/app_theme.dart';

// ── Color aliases ──────────────────────────────────────────────────────────────
const _kNavy    = AppTheme.primary;
const _kCard    = AppTheme.darkCard;
const _kSurface = AppTheme.darkSurface;
const _kBorder  = Color(0xFF1E3A5F);
const _kGold    = AppTheme.accent;
const _kGreen   = AppTheme.success;
const _kRed     = AppTheme.danger;
const _kMuted   = Color(0xFF8A9BB5);
const _kWhite   = Colors.white;

class GroupBookingScreen extends StatefulWidget {
  final int rideId;
  const GroupBookingScreen({super.key, required this.rideId});

  @override
  State<GroupBookingScreen> createState() => _GroupBookingScreenState();
}

class _GroupBookingScreenState extends State<GroupBookingScreen> {
  RideModel? _ride;
  bool _loading = true;
  String? _error;
  bool _submitting = false;

  int _totalSeats = 2;
  bool _splitPayment = false;

  // Extra passengers (totalSeats - 1 entries)
  final List<TextEditingController> _nameCtrl = [];
  final List<TextEditingController> _phoneCtrl = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _nameCtrl) c.dispose();
    for (final c in _phoneCtrl) c.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final ride = await context.read<RideService>().getRideDetail(widget.rideId);
      if (mounted) {
        setState(() {
          _ride = ride;
          _loading = false;
          _syncPassengers();
        });
      }
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _loading = false; });
    }
  }

  void _syncPassengers() {
    final needed = _totalSeats - 1;
    while (_nameCtrl.length < needed) {
      _nameCtrl.add(TextEditingController());
      _phoneCtrl.add(TextEditingController());
    }
    while (_nameCtrl.length > needed) {
      _nameCtrl.removeLast().dispose();
      _phoneCtrl.removeLast().dispose();
    }
  }

  void _setSeats(int v) {
    final seatsLeft = _ride!.seatsLeft ?? _ride!.availableSeats;
    final clamped = v.clamp(2, seatsLeft);
    setState(() {
      _totalSeats = clamped;
      _syncPassengers();
    });
  }

  Future<void> _submit() async {
    // Validate all passenger names
    for (int i = 0; i < _nameCtrl.length; i++) {
      if (_nameCtrl[i].text.trim().isEmpty) {
        _showError('Please enter a name for Passenger ${i + 2}');
        return;
      }
    }

    setState(() => _submitting = true);

    final passengers = List.generate(_nameCtrl.length, (i) => {
      'name': _nameCtrl[i].text.trim(),
      'phone': _phoneCtrl[i].text.trim(),
    });

    try {
      await context.read<RideService>().bookGroup(
        rideId: widget.rideId,
        seats: _totalSeats,
        splitPayment: _splitPayment,
        passengers: passengers,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Group booking submitted!'),
          backgroundColor: AppTheme.success,
        ),
      );
      context.pop();
      context.pop(); // pop detail screen too
    } catch (e) {
      if (!mounted) return;
      setState(() => _submitting = false);
      _showError(e.toString());
    }
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: _kRed),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _kNavy,
      appBar: AppBar(
        backgroundColor: AppTheme.primaryDark,
        foregroundColor: _kWhite,
        title: const Text('Group Booking'),
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
                      totalSeats: _totalSeats,
                      splitPayment: _splitPayment,
                      nameCtrl: _nameCtrl,
                      phoneCtrl: _phoneCtrl,
                      submitting: _submitting,
                      onSeatsChanged: _setSeats,
                      onSplitToggled: (v) => setState(() => _splitPayment = v),
                      onSubmit: _submit,
                    ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Body
// ─────────────────────────────────────────────────────────────────────────────
class _Body extends StatelessWidget {
  final RideModel ride;
  final int totalSeats;
  final bool splitPayment;
  final List<TextEditingController> nameCtrl;
  final List<TextEditingController> phoneCtrl;
  final bool submitting;
  final ValueChanged<int> onSeatsChanged;
  final ValueChanged<bool> onSplitToggled;
  final VoidCallback onSubmit;

  const _Body({
    required this.ride,
    required this.totalSeats,
    required this.splitPayment,
    required this.nameCtrl,
    required this.phoneCtrl,
    required this.submitting,
    required this.onSeatsChanged,
    required this.onSplitToggled,
    required this.onSubmit,
  });

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user!;
    final isStudent = user.isStudent;
    final organizerPrice = isStudent ? ride.price * 0.5 : ride.price;
    final extraPrice = ride.price;
    final seatsLeft = ride.seatsLeft ?? ride.availableSeats;

    // Total cost computation
    final otherSeats = totalSeats - 1;
    final double organizerUpfront = organizerPrice * 0.5;
    final double othersTotal = extraPrice * otherSeats;
    final double totalGroupCost = organizerPrice + othersTotal;
    final double organizerPaysUpfront = splitPayment
        ? organizerUpfront
        : totalGroupCost * 0.5;

    return Column(
      children: [
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Ride summary card
                _RideSummaryCard(ride: ride),
                const SizedBox(height: 14),

                // Seat selector
                _Panel(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _SectionLabel(icon: Icons.group_rounded, label: 'Total Seats'),
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          const Text(
                            'Seats (including you)',
                            style: TextStyle(color: _kWhite, fontSize: 14, fontWeight: FontWeight.w500),
                          ),
                          const Spacer(),
                          _SeatsCounter(
                            value: totalSeats,
                            min: 2,
                            max: seatsLeft,
                            onChange: onSeatsChanged,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),

                // Payment mode toggle
                _Panel(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _SectionLabel(icon: Icons.payments_rounded, label: 'Payment Mode'),
                      const SizedBox(height: 12),
                      _PaymentToggle(
                        splitPayment: splitPayment,
                        onToggle: onSplitToggled,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),

                // Cost breakdown
                _CostBreakdown(
                  ride: ride,
                  totalSeats: totalSeats,
                  splitPayment: splitPayment,
                  isStudent: isStudent,
                  organizerPrice: organizerPrice,
                  organizerPaysUpfront: organizerPaysUpfront,
                  walletBalance: user.balance,
                ),
                const SizedBox(height: 14),

                // Passenger list
                _PassengerList(
                  count: nameCtrl.length,
                  nameCtrl: nameCtrl,
                  phoneCtrl: phoneCtrl,
                ),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ),

        // Bottom bar
        _BottomBar(
          organizerPaysUpfront: organizerPaysUpfront,
          walletBalance: user.balance,
          submitting: submitting,
          onSubmit: onSubmit,
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Ride Summary Card
// ─────────────────────────────────────────────────────────────────────────────
class _RideSummaryCard extends StatelessWidget {
  final RideModel ride;
  const _RideSummaryCard({required this.ride});

  @override
  Widget build(BuildContext context) {
    final dateStr = DateFormat('EEE, MMM d • h:mm a').format(ride.departureTime);
    return _Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(icon: Icons.route_rounded, label: 'Ride'),
          const SizedBox(height: 10),
          Row(
            children: [
              _RouteDot(color: _kGreen),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  ride.fromLocation,
                  style: const TextStyle(color: _kWhite, fontSize: 14, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.only(left: 5),
            child: Container(width: 2, height: 14, color: _kBorder),
          ),
          Row(
            children: [
              _RouteDot(color: _kRed),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  ride.toLocation,
                  style: const TextStyle(color: _kWhite, fontSize: 14, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(dateStr, style: const TextStyle(color: _kMuted, fontSize: 12)),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Payment Toggle
// ─────────────────────────────────────────────────────────────────────────────
class _PaymentToggle extends StatelessWidget {
  final bool splitPayment;
  final ValueChanged<bool> onToggle;
  const _PaymentToggle({required this.splitPayment, required this.onToggle});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        _ToggleOption(
          selected: !splitPayment,
          icon: Icons.wallet_rounded,
          title: 'Pay for Everyone',
          subtitle: 'You pay 50% of the total group cost upfront',
          color: _kGold,
          onTap: () => onToggle(false),
        ),
        const SizedBox(height: 8),
        _ToggleOption(
          selected: splitPayment,
          icon: Icons.people_alt_rounded,
          title: 'Split Payment',
          subtitle: 'You pay 50% of your seat · Others pay their own share',
          color: AppTheme.info,
          onTap: () => onToggle(true),
        ),
      ],
    );
  }
}

class _ToggleOption extends StatelessWidget {
  final bool selected;
  final IconData icon;
  final String title;
  final String subtitle;
  final Color color;
  final VoidCallback onTap;

  const _ToggleOption({
    required this.selected,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.color,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: selected ? color.withOpacity(0.1) : _kNavy.withOpacity(0.4),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected ? color.withOpacity(0.5) : _kBorder,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: color.withOpacity(0.15),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Icon(icon, color: color, size: 18),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: TextStyle(color: selected ? color : _kWhite, fontSize: 13, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 2),
                  Text(subtitle, style: const TextStyle(color: _kMuted, fontSize: 11)),
                ],
              ),
            ),
            Icon(
              selected ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
              color: selected ? color : _kMuted,
              size: 20,
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Cost Breakdown
// ─────────────────────────────────────────────────────────────────────────────
class _CostBreakdown extends StatelessWidget {
  final RideModel ride;
  final int totalSeats;
  final bool splitPayment;
  final bool isStudent;
  final double organizerPrice;
  final double organizerPaysUpfront;
  final double walletBalance;

  const _CostBreakdown({
    required this.ride,
    required this.totalSeats,
    required this.splitPayment,
    required this.isStudent,
    required this.organizerPrice,
    required this.organizerPaysUpfront,
    required this.walletBalance,
  });

  @override
  Widget build(BuildContext context) {
    final otherSeats = totalSeats - 1;
    final othersPerSeat = ride.price * 0.5;
    final canAfford = walletBalance >= organizerPaysUpfront;

    return _Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(icon: Icons.receipt_long_rounded, label: 'Cost Breakdown'),
          const SizedBox(height: 12),
          _BreakdownRow(
            label: 'Your seat${isStudent ? ' (student −50%)' : ''}',
            value: '${organizerPrice.toStringAsFixed(2)} TND',
            color: isStudent ? _kGreen : _kWhite,
          ),
          if (otherSeats > 0) ...[
            const SizedBox(height: 6),
            _BreakdownRow(
              label: '$otherSeats extra seat${otherSeats > 1 ? 's' : ''} × ${ride.price.toStringAsFixed(2)} TND',
              value: '${(ride.price * otherSeats).toStringAsFixed(2)} TND',
            ),
          ],
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 10),
            child: Divider(color: _kBorder, height: 1),
          ),
          _BreakdownRow(
            label: splitPayment
                ? 'You pay now (your 50%)'
                : 'You pay now (group 50%)',
            value: '${organizerPaysUpfront.toStringAsFixed(2)} TND',
            color: _kGold,
            bold: true,
          ),
          if (splitPayment && otherSeats > 0) ...[
            const SizedBox(height: 6),
            _BreakdownRow(
              label: 'Per extra passenger (50%)',
              value: '${othersPerSeat.toStringAsFixed(2)} TND each',
              color: AppTheme.info,
            ),
          ],
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: canAfford ? _kGold.withOpacity(0.07) : _kRed.withOpacity(0.08),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: (canAfford ? _kGold : _kRed).withOpacity(0.25)),
            ),
            child: Row(children: [
              Icon(
                canAfford ? Icons.account_balance_wallet_rounded : Icons.warning_amber_rounded,
                color: canAfford ? _kGold : _kRed,
                size: 16,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  canAfford
                      ? 'Wallet after: ${(walletBalance - organizerPaysUpfront).toStringAsFixed(2)} TND'
                      : 'Insufficient balance (need ${organizerPaysUpfront.toStringAsFixed(2)} TND)',
                  style: TextStyle(
                    color: canAfford ? _kGold : _kRed,
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ]),
          ),
        ],
      ),
    );
  }
}

class _BreakdownRow extends StatelessWidget {
  final String label;
  final String value;
  final Color color;
  final bool bold;

  const _BreakdownRow({
    required this.label,
    required this.value,
    this.color = _kWhite,
    this.bold = false,
  });

  @override
  Widget build(BuildContext context) => Row(
    mainAxisAlignment: MainAxisAlignment.spaceBetween,
    children: [
      Text(label, style: const TextStyle(color: _kMuted, fontSize: 13)),
      Text(
        value,
        style: TextStyle(
          color: color,
          fontSize: 13,
          fontWeight: bold ? FontWeight.w700 : FontWeight.w500,
        ),
      ),
    ],
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Passenger List
// ─────────────────────────────────────────────────────────────────────────────
class _PassengerList extends StatelessWidget {
  final int count;
  final List<TextEditingController> nameCtrl;
  final List<TextEditingController> phoneCtrl;

  const _PassengerList({
    required this.count,
    required this.nameCtrl,
    required this.phoneCtrl,
  });

  @override
  Widget build(BuildContext context) {
    if (count == 0) return const SizedBox();

    return _Panel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(icon: Icons.people_rounded, label: 'Passengers'),
          const SizedBox(height: 4),
          const Text(
            'Enter names for the extra seats (phone optional)',
            style: TextStyle(color: _kMuted, fontSize: 11),
          ),
          const SizedBox(height: 14),
          ...List.generate(count, (i) => _PassengerEntry(
            index: i + 2, // seat 2, 3, …
            nameCtrl: nameCtrl[i],
            phoneCtrl: phoneCtrl[i],
          )),
        ],
      ),
    );
  }
}

class _PassengerEntry extends StatelessWidget {
  final int index;
  final TextEditingController nameCtrl;
  final TextEditingController phoneCtrl;

  const _PassengerEntry({
    required this.index,
    required this.nameCtrl,
    required this.phoneCtrl,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Container(
              width: 24,
              height: 24,
              decoration: BoxDecoration(
                color: AppTheme.info.withOpacity(0.15),
                shape: BoxShape.circle,
                border: Border.all(color: AppTheme.info.withOpacity(0.3)),
              ),
              child: Center(
                child: Text(
                  '$index',
                  style: const TextStyle(color: AppTheme.info, fontSize: 12, fontWeight: FontWeight.w700),
                ),
              ),
            ),
            const SizedBox(width: 8),
            Text(
              'Passenger $index',
              style: const TextStyle(color: _kWhite, fontSize: 13, fontWeight: FontWeight.w600),
            ),
          ]),
          const SizedBox(height: 8),
          _Field(
            controller: nameCtrl,
            hint: 'Full name *',
            icon: Icons.person_outline_rounded,
          ),
          const SizedBox(height: 8),
          _Field(
            controller: phoneCtrl,
            hint: 'Phone number (optional)',
            icon: Icons.phone_outlined,
            keyboardType: TextInputType.phone,
          ),
        ],
      ),
    );
  }
}

class _Field extends StatelessWidget {
  final TextEditingController controller;
  final String hint;
  final IconData icon;
  final TextInputType? keyboardType;

  const _Field({
    required this.controller,
    required this.hint,
    required this.icon,
    this.keyboardType,
  });

  @override
  Widget build(BuildContext context) => TextField(
    controller: controller,
    keyboardType: keyboardType,
    style: const TextStyle(color: _kWhite, fontSize: 14),
    decoration: InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(color: _kMuted, fontSize: 13),
      prefixIcon: Icon(icon, color: _kMuted, size: 18),
      filled: true,
      fillColor: _kNavy.withOpacity(0.6),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: _kBorder),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: _kBorder),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: _kGold, width: 1.5),
      ),
    ),
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Bottom Bar
// ─────────────────────────────────────────────────────────────────────────────
class _BottomBar extends StatelessWidget {
  final double organizerPaysUpfront;
  final double walletBalance;
  final bool submitting;
  final VoidCallback onSubmit;

  const _BottomBar({
    required this.organizerPaysUpfront,
    required this.walletBalance,
    required this.submitting,
    required this.onSubmit,
  });

  @override
  Widget build(BuildContext context) {
    final canAfford = walletBalance >= organizerPaysUpfront;

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
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'You pay: ${organizerPaysUpfront.toStringAsFixed(2)} TND',
                      style: const TextStyle(color: _kGold, fontSize: 16, fontWeight: FontWeight.w800),
                    ),
                    Text(
                      'Wallet: ${walletBalance.toStringAsFixed(2)} TND',
                      style: TextStyle(color: canAfford ? _kGreen : _kRed, fontSize: 12),
                    ),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              height: 50,
              child: ElevatedButton(
                onPressed: (canAfford && !submitting) ? onSubmit : null,
                style: ElevatedButton.styleFrom(
                  backgroundColor: _kGold,
                  foregroundColor: _kNavy,
                  disabledBackgroundColor: _kGold.withOpacity(0.4),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  elevation: 0,
                ),
                child: submitting
                    ? const SizedBox(
                        width: 20, height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2.5, color: _kNavy),
                      )
                    : const Text(
                        'Confirm Group Booking',
                        style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
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

class _RouteDot extends StatelessWidget {
  final Color color;
  const _RouteDot({required this.color});

  @override
  Widget build(BuildContext context) => Container(
    width: 10, height: 10,
    decoration: BoxDecoration(
      color: color, shape: BoxShape.circle,
      boxShadow: [BoxShadow(color: color.withOpacity(0.5), blurRadius: 4)],
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
      decoration: BoxDecoration(
        color: _kSurface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: _kBorder),
      ),
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
