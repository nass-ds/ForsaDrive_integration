import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../services/ride_service.dart';
import '../../models/ride_model.dart';
import '../../utils/app_theme.dart';
import '../../widgets/app_nav.dart';
import '../../widgets/ride_card.dart';
import '../../l10n/app_localizations.dart';

// ─── Filter result snapshot passed from the sheet ────────────────────────────

class _FilterResult {
  final double minPrice;
  final double maxPrice;
  final double minRating;
  final String date;
  final int seats;
  final String sort;
  final Set<String> amenities;

  const _FilterResult({
    required this.minPrice,
    required this.maxPrice,
    required this.minRating,
    required this.date,
    required this.seats,
    required this.sort,
    required this.amenities,
  });

  int get activeCount {
    int n = 0;
    if (minPrice > 0 || maxPrice < 200) n++;
    if (minRating > 0) n++;
    if (date.isNotEmpty) n++;
    if (seats > 1) n++;
    if (sort.isNotEmpty) n++;
    n += amenities.length;
    return n;
  }
}

// ─── Dashboard Screen ─────────────────────────────────────────────────────────

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});
  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final _fromCtrl = TextEditingController();
  final _toCtrl = TextEditingController();

  _FilterResult _filters = const _FilterResult(
    minPrice: 0,
    maxPrice: 200,
    minRating: 0,
    date: '',
    seats: 1,
    sort: '',
    amenities: {},
  );

  List<RideModel> _rides = [];
  List<RideModel> _filtered = [];
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadRides();
  }

  @override
  void dispose() {
    _fromCtrl.dispose();
    _toCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadRides() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final svc = context.read<RideService>();
      final rides = await svc.getAvailableRides(
        from: _fromCtrl.text,
        to: _toCtrl.text,
        date: _filters.date,
        seats: _filters.seats > 1 ? _filters.seats : null,
        sort: _filters.sort,
        minPrice: _filters.minPrice > 0 ? _filters.minPrice : null,
        maxPrice: _filters.maxPrice < 200 ? _filters.maxPrice : null,
        minRating: _filters.minRating > 0 ? _filters.minRating : null,
        amenities: _filters.amenities.isNotEmpty ? _filters.amenities.toList() : null,
      );
      setState(() {
        _rides = rides;
        _filtered = _applyClientFilters(rides);
      });
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      setState(() => _loading = false);
    }
  }

  List<RideModel> _applyClientFilters(List<RideModel> source) {
    var result = source.where((r) {
      if (r.price < _filters.minPrice || r.price > _filters.maxPrice) return false;
      if (_filters.minRating > 0 && (r.driverScore ?? 0) < _filters.minRating) return false;
      if (_filters.amenities.contains('ac') && r.hasAc != true) return false;
      if (_filters.amenities.contains('wifi') && r.hasWifi != true) return false;
      if (_filters.amenities.contains('music') && r.hasMusic != true) return false;
      if (_filters.amenities.contains('accessible') && r.isAccessible != true) return false;
      final avail = r.seatsLeft ?? r.availableSeats;
      if (avail < _filters.seats) return false;
      return true;
    }).toList();

    switch (_filters.sort) {
      case 'price_asc':
        result.sort((a, b) => a.price.compareTo(b.price));
        break;
      case 'price_desc':
        result.sort((a, b) => b.price.compareTo(a.price));
        break;
      case 'rating':
        result.sort((a, b) => (b.driverScore ?? 0).compareTo(a.driverScore ?? 0));
        break;
      case 'seats':
        result.sort((a, b) => (b.seatsLeft ?? b.availableSeats)
            .compareTo(a.seatsLeft ?? a.availableSeats));
        break;
      case 'departure':
        result.sort((a, b) => a.departureTime.compareTo(b.departureTime));
        break;
    }
    return result;
  }

  void _openFilterSheet() async {
    final result = await showModalBottomSheet<_FilterResult>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _FilterSheet(initial: _filters),
    );
    if (result != null) {
      setState(() => _filters = result);
      _loadRides();
    }
  }

  void _removeFilterChip(String key) {
    final f = _filters;
    setState(() {
      _filters = _FilterResult(
        minPrice: key == 'price' ? 0 : f.minPrice,
        maxPrice: key == 'price' ? 200 : f.maxPrice,
        minRating: key == 'rating' ? 0 : f.minRating,
        date: key == 'date' ? '' : f.date,
        seats: key == 'seats' ? 1 : f.seats,
        sort: key == 'sort' ? '' : f.sort,
        amenities: key.startsWith('amenity_')
            ? (Set.from(f.amenities)..remove(key.substring(8)))
            : f.amenities,
      );
    });
    _loadRides();
  }

  Future<void> _bookRide(RideModel ride) async {
    final l10n = AppLocalizations.of(context);
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(l10n.bookRide),
        content: Text(
          '${l10n.bookConfirmMsg(ride.fromLocation, ride.toLocation)}\n\n'
          '${l10n.chargedMsg((ride.price * 0.5).toStringAsFixed(2))}',
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(l10n.cancel)),
          ElevatedButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(l10n.confirm)),
        ],
      ),
    );
    if (confirm != true || !mounted) return;
    try {
      await context.read<RideService>().bookRide(ride.id, 1);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(l10n.bookingSent),
          backgroundColor: AppTheme.success,
        ),
      );
      _loadRides();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final user = auth.user!;
    final l10n = AppLocalizations.of(context);
    final activeCount = _filters.activeCount;

    return Scaffold(
      appBar: const AppNavBar(),
      body: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (MediaQuery.of(context).size.width > 900)
            SizedBox(width: 260, child: _Sidebar(user: user)),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${_greeting(l10n)}, ${user.username}!',
                    style: const TextStyle(
                      fontSize: 24,
                      fontWeight: FontWeight.w800,
                      color: AppTheme.primary,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${l10n.balance}: ${user.balance.toStringAsFixed(2)} TND',
                    style: const TextStyle(color: AppTheme.textSecondary),
                  ),
                  const SizedBox(height: 24),
                  // Quick action cards
                  Wrap(spacing: 16, runSpacing: 16, children: [
                    _StatCard(
                        icon: Icons.directions_car,
                        label: l10n.findARide,
                        color: AppTheme.info,
                        onTap: () {}),
                    if (user.isDriver)
                      _StatCard(
                          icon: Icons.add_circle_outline,
                          label: l10n.offerRide,
                          color: AppTheme.success,
                          onTap: () => context.go('/offer-ride')),
                    _StatCard(
                        icon: Icons.history,
                        label: l10n.myRides,
                        color: AppTheme.warning,
                        onTap: () => context.go('/my-rides')),
                    if (user.isDriver)
                      _StatCard(
                          icon: Icons.people,
                          label: l10n.bookingRequests,
                          color: AppTheme.accent,
                          onTap: () => context.go('/booking-requests')),
                  ]),
                  const SizedBox(height: 32),

                  // ─── Search & Filter card ─────────────────────────
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Text(
                                l10n.findAvailableRides,
                                style: const TextStyle(
                                  fontSize: 18,
                                  fontWeight: FontWeight.w700,
                                  color: AppTheme.primary,
                                ),
                              ),
                              const Spacer(),
                              // Filter button with badge
                              Stack(
                                clipBehavior: Clip.none,
                                children: [
                                  OutlinedButton.icon(
                                    onPressed: _openFilterSheet,
                                    icon: const Icon(Icons.tune, size: 18),
                                    label: Text(l10n.filters),
                                    style: OutlinedButton.styleFrom(
                                      foregroundColor: activeCount > 0
                                          ? AppTheme.accent
                                          : AppTheme.textSecondary,
                                      side: BorderSide(
                                        color: activeCount > 0
                                            ? AppTheme.accent
                                            : AppTheme.darkBorder,
                                      ),
                                    ),
                                  ),
                                  if (activeCount > 0)
                                    Positioned(
                                      top: -6,
                                      right: -6,
                                      child: Container(
                                        width: 20,
                                        height: 20,
                                        decoration: const BoxDecoration(
                                          color: AppTheme.accent,
                                          shape: BoxShape.circle,
                                        ),
                                        child: Center(
                                          child: Text(
                                            '$activeCount',
                                            style: const TextStyle(
                                              color: AppTheme.primaryDark,
                                              fontSize: 11,
                                              fontWeight: FontWeight.w800,
                                            ),
                                          ),
                                        ),
                                      ),
                                    ),
                                ],
                              ),
                            ],
                          ),
                          const SizedBox(height: 16),
                          // From / To / Search row
                          Wrap(
                            spacing: 12,
                            runSpacing: 12,
                            crossAxisAlignment: WrapCrossAlignment.center,
                            children: [
                              SizedBox(
                                width: 200,
                                child: TextField(
                                  controller: _fromCtrl,
                                  decoration: InputDecoration(
                                    labelText: l10n.from,
                                    prefixIcon:
                                        const Icon(Icons.trip_origin, size: 18),
                                  ),
                                ),
                              ),
                              SizedBox(
                                width: 200,
                                child: TextField(
                                  controller: _toCtrl,
                                  decoration: InputDecoration(
                                    labelText: l10n.to,
                                    prefixIcon: const Icon(Icons.location_on,
                                        size: 18),
                                  ),
                                ),
                              ),
                              ElevatedButton.icon(
                                onPressed: _loadRides,
                                icon: const Icon(Icons.search, size: 18),
                                label: Text(l10n.search),
                              ),
                            ],
                          ),
                          // Active filter chips
                          if (activeCount > 0) ...[
                            const SizedBox(height: 12),
                            _ActiveFilterChips(
                              filters: _filters,
                              onRemove: _removeFilterChip,
                              l10n: l10n,
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 24),

                  // ─── Results ──────────────────────────────────────
                  if (_loading)
                    const Center(child: CircularProgressIndicator())
                  else if (_error != null)
                    Center(
                        child: Text(_error!,
                            style: const TextStyle(color: AppTheme.danger)))
                  else if (_filtered.isEmpty)
                    Center(
                      child: Padding(
                        padding: const EdgeInsets.all(48),
                        child: Column(children: [
                          const Icon(Icons.search_off,
                              size: 56, color: AppTheme.textSecondary),
                          const SizedBox(height: 12),
                          Text(
                            l10n.noRidesFound,
                            style: const TextStyle(color: AppTheme.textSecondary),
                          ),
                          if (activeCount > 0) ...[
                            const SizedBox(height: 8),
                            TextButton(
                              onPressed: () {
                                setState(() => _filters = const _FilterResult(
                                      minPrice: 0,
                                      maxPrice: 200,
                                      minRating: 0,
                                      date: '',
                                      seats: 1,
                                      sort: '',
                                      amenities: {},
                                    ));
                                _loadRides();
                              },
                              child: Text(l10n.clearFilters),
                            ),
                          ],
                        ]),
                      ),
                    )
                  else
                    ..._filtered.map((ride) => RideCard(
                          ride: ride,
                          showBookButton: true,
                          onBook: () => _bookRide(ride),
                        )),
                ],
              ),
            ),
          ),
        ],
      ),
      floatingActionButton: auth.isDriver
          ? FloatingActionButton.extended(
              onPressed: () => context.go('/offer-ride'),
              icon: const Icon(Icons.add),
              label: Text(l10n.offerRide),
              backgroundColor: AppTheme.accent,
              foregroundColor: AppTheme.primary,
            )
          : null,
    );
  }

  String _greeting(AppLocalizations l10n) {
    final hour = DateTime.now().hour;
    if (hour < 12) return l10n.goodMorning;
    if (hour < 17) return l10n.goodAfternoon;
    return l10n.goodEvening;
  }
}

// ─── Active filter chips row ──────────────────────────────────────────────────

class _ActiveFilterChips extends StatelessWidget {
  final _FilterResult filters;
  final void Function(String key) onRemove;
  final AppLocalizations l10n;

  const _ActiveFilterChips(
      {required this.filters,
      required this.onRemove,
      required this.l10n});

  @override
  Widget build(BuildContext context) {
    final chips = <Widget>[];

    if (filters.minPrice > 0 || filters.maxPrice < 200) {
      chips.add(_chip(
        '${filters.minPrice.toInt()} – ${filters.maxPrice.toInt()} TND',
        () => onRemove('price'),
      ));
    }
    if (filters.minRating > 0) {
      chips.add(_chip(
        '★ ≥ ${filters.minRating.toStringAsFixed(1)}',
        () => onRemove('rating'),
      ));
    }
    if (filters.date.isNotEmpty) {
      chips.add(_chip(filters.date, () => onRemove('date')));
    }
    if (filters.seats > 1) {
      chips.add(_chip('${filters.seats} ${l10n.seats}', () => onRemove('seats')));
    }
    if (filters.sort.isNotEmpty) {
      chips.add(_chip(_sortLabel(filters.sort, l10n), () => onRemove('sort')));
    }
    for (final a in filters.amenities) {
      chips.add(_chip(_amenityLabel(a, l10n), () => onRemove('amenity_$a')));
    }

    return Wrap(spacing: 8, runSpacing: 8, children: chips);
  }

  Widget _chip(String label, VoidCallback onDelete) {
    return Chip(
      label: Text(label, style: const TextStyle(fontSize: 12)),
      deleteIcon: const Icon(Icons.close, size: 14),
      onDeleted: onDelete,
      backgroundColor: AppTheme.accent.withOpacity(0.12),
      side: BorderSide(color: AppTheme.accent.withOpacity(0.4)),
      labelStyle: const TextStyle(color: AppTheme.accent),
      deleteIconColor: AppTheme.accent,
      visualDensity: VisualDensity.compact,
      materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
    );
  }

  String _sortLabel(String sort, AppLocalizations l10n) {
    switch (sort) {
      case 'price_asc':
        return '${l10n.price} ↑';
      case 'price_desc':
        return '${l10n.price} ↓';
      case 'rating':
        return '${l10n.rating} ↓';
      case 'seats':
        return l10n.seats;
      case 'departure':
        return l10n.departure;
      default:
        return sort;
    }
  }

  String _amenityLabel(String a, AppLocalizations l10n) {
    switch (a) {
      case 'ac':
        return l10n.amenityAc;
      case 'wifi':
        return l10n.amenityWifi;
      case 'music':
        return l10n.amenityMusic;
      case 'accessible':
        return l10n.amenityAccessible;
      default:
        return a;
    }
  }
}

// ─── Filter Sheet ─────────────────────────────────────────────────────────────

class _FilterSheet extends StatefulWidget {
  final _FilterResult initial;
  const _FilterSheet({required this.initial});

  @override
  State<_FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<_FilterSheet> {
  late RangeValues _priceRange;
  late double _minRating;
  late String _date;
  late int _seats;
  late String _sort;
  late Set<String> _amenities;

  final _dateCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    final f = widget.initial;
    _priceRange = RangeValues(f.minPrice, f.maxPrice);
    _minRating = f.minRating;
    _date = f.date;
    _seats = f.seats;
    _sort = f.sort;
    _amenities = Set.from(f.amenities);
    _dateCtrl.text = _date;
  }

  @override
  void dispose() {
    _dateCtrl.dispose();
    super.dispose();
  }

  void _reset() {
    setState(() {
      _priceRange = const RangeValues(0, 200);
      _minRating = 0;
      _date = '';
      _seats = 1;
      _sort = '';
      _amenities = {};
      _dateCtrl.text = '';
    });
  }

  void _apply() {
    Navigator.of(context).pop(_FilterResult(
      minPrice: _priceRange.start,
      maxPrice: _priceRange.end,
      minRating: _minRating,
      date: _date,
      seats: _seats,
      sort: _sort,
      amenities: Set.unmodifiable(_amenities),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final mq = MediaQuery.of(context);

    return Container(
      height: mq.size.height * 0.85,
      decoration: const BoxDecoration(
        color: AppTheme.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      child: Column(
        children: [
          // Handle bar
          Padding(
            padding: const EdgeInsets.only(top: 12, bottom: 4),
            child: Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: AppTheme.darkBorder,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
          // Header
          Padding(
            padding:
                const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
            child: Row(
              children: [
                Text(
                  l10n.advancedFilters,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w700,
                    color: AppTheme.primary,
                  ),
                ),
                const Spacer(),
                TextButton(
                  onPressed: _reset,
                  child: Text(l10n.reset,
                      style: const TextStyle(color: AppTheme.textSecondary)),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          // Scrollable content
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ── Price range ─────────────────────────────────
                  _sectionTitle(l10n.priceRange),
                  const SizedBox(height: 4),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        '${_priceRange.start.toInt()} TND',
                        style: const TextStyle(
                            fontSize: 13, color: AppTheme.textSecondary),
                      ),
                      Text(
                        '${_priceRange.end.toInt()} TND',
                        style: const TextStyle(
                            fontSize: 13, color: AppTheme.textSecondary),
                      ),
                    ],
                  ),
                  RangeSlider(
                    values: _priceRange,
                    min: 0,
                    max: 200,
                    divisions: 40,
                    activeColor: AppTheme.accent,
                    inactiveColor: AppTheme.accent.withOpacity(0.2),
                    labels: RangeLabels(
                      '${_priceRange.start.toInt()} TND',
                      '${_priceRange.end.toInt()} TND',
                    ),
                    onChanged: (v) => setState(() => _priceRange = v),
                  ),

                  const SizedBox(height: 20),

                  // ── Minimum driver rating ────────────────────────
                  _sectionTitle(l10n.minDriverRating),
                  const SizedBox(height: 8),
                  Row(
                    children: [0.0, 3.0, 3.5, 4.0, 4.5].map((r) {
                      final selected = _minRating == r;
                      return Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: Text(
                            r == 0 ? l10n.any : '★ $r',
                            style: TextStyle(
                              fontSize: 13,
                              color: selected
                                  ? AppTheme.primaryDark
                                  : AppTheme.textSecondary,
                              fontWeight: selected
                                  ? FontWeight.w700
                                  : FontWeight.normal,
                            ),
                          ),
                          selected: selected,
                          selectedColor: AppTheme.accent,
                          checkmarkColor: AppTheme.primaryDark,
                          onSelected: (_) =>
                              setState(() => _minRating = r),
                        ),
                      );
                    }).toList(),
                  ),

                  const SizedBox(height: 20),

                  // ── Date ─────────────────────────────────────────
                  _sectionTitle(l10n.date),
                  const SizedBox(height: 8),
                  SizedBox(
                    width: 200,
                    child: TextField(
                      controller: _dateCtrl,
                      readOnly: true,
                      decoration: InputDecoration(
                        hintText: 'YYYY-MM-DD',
                        prefixIcon:
                            const Icon(Icons.calendar_today, size: 18),
                        suffixIcon: _date.isNotEmpty
                            ? IconButton(
                                icon: const Icon(Icons.clear, size: 18),
                                onPressed: () => setState(() {
                                  _date = '';
                                  _dateCtrl.text = '';
                                }),
                              )
                            : null,
                      ),
                      onTap: () async {
                        final picked = await showDatePicker(
                          context: context,
                          initialDate: DateTime.now(),
                          firstDate: DateTime.now(),
                          lastDate:
                              DateTime.now().add(const Duration(days: 90)),
                        );
                        if (picked != null) {
                          final s =
                              '${picked.year}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
                          setState(() {
                            _date = s;
                            _dateCtrl.text = s;
                          });
                        }
                      },
                    ),
                  ),

                  const SizedBox(height: 20),

                  // ── Seats ─────────────────────────────────────────
                  _sectionTitle(l10n.seats),
                  const SizedBox(height: 8),
                  Row(
                    children: [1, 2, 3, 4, 5, 6].map((s) {
                      final selected = _seats == s;
                      return Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: Text(
                            '$s',
                            style: TextStyle(
                              fontSize: 13,
                              color: selected
                                  ? AppTheme.primaryDark
                                  : AppTheme.textSecondary,
                              fontWeight: selected
                                  ? FontWeight.w700
                                  : FontWeight.normal,
                            ),
                          ),
                          selected: selected,
                          selectedColor: AppTheme.accent,
                          checkmarkColor: AppTheme.primaryDark,
                          onSelected: (_) => setState(() => _seats = s),
                        ),
                      );
                    }).toList(),
                  ),

                  const SizedBox(height: 20),

                  // ── Amenities ─────────────────────────────────────
                  _sectionTitle(l10n.amenities),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      _amenityChip('ac', l10n.amenityAc,
                          Icons.ac_unit, l10n),
                      _amenityChip('wifi', l10n.amenityWifi,
                          Icons.wifi, l10n),
                      _amenityChip('music', l10n.amenityMusic,
                          Icons.music_note, l10n),
                      _amenityChip('accessible', l10n.amenityAccessible,
                          Icons.accessible, l10n),
                    ],
                  ),

                  const SizedBox(height: 20),

                  // ── Sort ─────────────────────────────────────────
                  _sectionTitle(l10n.sortBy),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      _sortChip('', l10n.defaultSort),
                      _sortChip('price_asc', '${l10n.price} ↑'),
                      _sortChip('price_desc', '${l10n.price} ↓'),
                      _sortChip('rating', '${l10n.rating} ↓'),
                      _sortChip('departure', l10n.departure),
                      _sortChip('seats', l10n.seats),
                    ],
                  ),

                  const SizedBox(height: 8),
                ],
              ),
            ),
          ),
          // Apply button
          Padding(
            padding: EdgeInsets.fromLTRB(20, 12, 20, mq.padding.bottom + 12),
            child: SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton(
                onPressed: _apply,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppTheme.accent,
                  foregroundColor: AppTheme.primaryDark,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14)),
                  textStyle: const TextStyle(
                      fontSize: 15, fontWeight: FontWeight.w700),
                ),
                child: Text(l10n.applyFilters),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _sectionTitle(String title) {
    return Text(
      title,
      style: const TextStyle(
        fontSize: 14,
        fontWeight: FontWeight.w700,
        color: AppTheme.primary,
      ),
    );
  }

  Widget _amenityChip(
      String key, String label, IconData icon, AppLocalizations l10n) {
    final selected = _amenities.contains(key);
    return FilterChip(
      avatar: Icon(icon,
          size: 16,
          color: selected ? AppTheme.primaryDark : AppTheme.textSecondary),
      label: Text(
        label,
        style: TextStyle(
          fontSize: 13,
          color:
              selected ? AppTheme.primaryDark : AppTheme.textSecondary,
          fontWeight:
              selected ? FontWeight.w600 : FontWeight.normal,
        ),
      ),
      selected: selected,
      selectedColor: AppTheme.accent,
      checkmarkColor: AppTheme.primaryDark,
      onSelected: (v) => setState(() {
        if (v) {
          _amenities = {..._amenities, key};
        } else {
          _amenities = Set.from(_amenities)..remove(key);
        }
      }),
    );
  }

  Widget _sortChip(String value, String label) {
    final selected = _sort == value;
    return ChoiceChip(
      label: Text(
        label,
        style: TextStyle(
          fontSize: 13,
          color: selected ? AppTheme.primaryDark : AppTheme.textSecondary,
          fontWeight: selected ? FontWeight.w700 : FontWeight.normal,
        ),
      ),
      selected: selected,
      selectedColor: AppTheme.accent,
      checkmarkColor: AppTheme.primaryDark,
      onSelected: (_) => setState(() => _sort = value),
    );
  }
}

// ─── Stat card ────────────────────────────────────────────────────────────────

class _StatCard extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  const _StatCard(
      {required this.icon,
      required this.label,
      required this.color,
      required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        width: 160,
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: color.withOpacity(0.1),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: color.withOpacity(0.3)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: color, size: 28),
            const SizedBox(height: 8),
            Text(label,
                style: TextStyle(
                    color: color,
                    fontWeight: FontWeight.w700,
                    fontSize: 13)),
          ],
        ),
      ),
    );
  }
}

// ─── Sidebar ──────────────────────────────────────────────────────────────────

class _Sidebar extends StatelessWidget {
  final dynamic user;
  const _Sidebar({required this.user});

  @override
  Widget build(BuildContext context) {
    return Container(
      height: double.infinity,
      color: AppTheme.primary,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 24, 20, 12),
            child: Column(children: [
              CircleAvatar(
                radius: 36,
                backgroundColor: AppTheme.accent,
                child: Text(user.username[0].toUpperCase(),
                    style: const TextStyle(
                        fontSize: 28,
                        fontWeight: FontWeight.w800,
                        color: AppTheme.primary)),
              ),
              const SizedBox(height: 12),
              Text(user.username,
                  style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                      fontSize: 16)),
              Text(user.email,
                  style: const TextStyle(color: Colors.white60, fontSize: 12),
                  overflow: TextOverflow.ellipsis),
              const SizedBox(height: 8),
              Text('${user.balance.toStringAsFixed(2)} TND',
                  style: const TextStyle(
                      color: AppTheme.accent,
                      fontWeight: FontWeight.w800,
                      fontSize: 18)),
            ]),
          ),
          const Divider(color: Colors.white24),
          Expanded(
            child: SingleChildScrollView(
              child: Column(children: [
                _SidebarItem(
                    icon: Icons.dashboard,
                    label: 'Dashboard',
                    route: '/dashboard'),
                _SidebarItem(
                    icon: Icons.history,
                    label: 'My Rides',
                    route: '/my-rides'),
                _SidebarItem(
                    icon: Icons.account_balance_wallet,
                    label: 'Wallet',
                    route: '/payments'),
                _SidebarItem(
                    icon: Icons.message,
                    label: 'Messages',
                    route: '/messages'),
                _SidebarItem(
                    icon: Icons.notifications,
                    label: 'Notifications',
                    route: '/notifications'),
                _SidebarItem(
                    icon: Icons.star, label: 'Ratings', route: '/ratings'),
                _SidebarItem(
                    icon: Icons.report,
                    label: 'Complaints',
                    route: '/complaints'),
                _SidebarItem(
                    icon: Icons.support_agent,
                    label: 'Support',
                    route: '/helpdesk'),
                if (user.isDriver) ...[
                  const Divider(color: Colors.white24),
                  _SidebarItem(
                      icon: Icons.drive_eta,
                      label: 'Driver Dashboard',
                      route: '/driver-dashboard'),
                  _SidebarItem(
                      icon: Icons.directions_car,
                      label: 'My Vehicles',
                      route: '/vehicles'),
                  _SidebarItem(
                      icon: Icons.people,
                      label: 'Booking Requests',
                      route: '/booking-requests'),
                ],
                if (user.isAdmin) ...[
                  const Divider(color: Colors.white24),
                  _SidebarItem(
                      icon: Icons.admin_panel_settings,
                      label: 'Admin Panel',
                      route: '/admin'),
                ],
                const Divider(color: Colors.white24),
                _SidebarItem(
                    icon: Icons.settings,
                    label: 'Settings',
                    route: '/settings'),
                const SizedBox(height: 16),
              ]),
            ),
          ),
        ],
      ),
    );
  }
}

class _SidebarItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final String route;
  const _SidebarItem(
      {required this.icon, required this.label, required this.route});

  @override
  Widget build(BuildContext context) {
    final isActive =
        GoRouterState.of(context).uri.path.startsWith(route);
    return ListTile(
      leading: Icon(icon,
          color: isActive ? AppTheme.accent : Colors.white60, size: 20),
      title: Text(label,
          style: TextStyle(
              color: isActive ? AppTheme.accent : Colors.white70,
              fontSize: 13,
              fontWeight:
                  isActive ? FontWeight.w700 : FontWeight.normal)),
      selected: isActive,
      selectedTileColor: Colors.white12,
      onTap: () => context.go(route),
    );
  }
}