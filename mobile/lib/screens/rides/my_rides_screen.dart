import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../services/ride_service.dart';
import '../../models/ride_model.dart';
import '../../utils/app_theme.dart';
import '../../widgets/ride_card.dart';

class MyRidesScreen extends StatefulWidget {
  const MyRidesScreen({super.key});
  @override
  State<MyRidesScreen> createState() => _MyRidesScreenState();
}

class _MyRidesScreenState extends State<MyRidesScreen> with SingleTickerProviderStateMixin {
  late TabController _tab;
  List<RideModel> _passengerRides = [];
  List<RideModel> _driverRides = [];
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    final isDriver = context.read<AuthProvider>().isDriver;
    _tab = TabController(length: isDriver ? 2 : 1, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tab.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final svc = context.read<RideService>();
      final results = await Future.wait([
        svc.getMyRides(asDriver: false),
        if (context.read<AuthProvider>().isDriver) svc.getMyRides(asDriver: true),
      ]);
      setState(() {
        _passengerRides = results[0];
        if (results.length > 1) _driverRides = results[1];
      });
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDriver = context.read<AuthProvider>().isDriver;

    return Scaffold(
      appBar: AppBar(
        title: const Text('My Rides'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        bottom: TabBar(
          controller: _tab,
          labelColor: AppTheme.accent,
          unselectedLabelColor: Colors.white60,
          indicatorColor: AppTheme.accent,
          tabs: [
            const Tab(icon: Icon(Icons.person), text: 'As Passenger'),
            if (isDriver) const Tab(icon: Icon(Icons.drive_eta), text: 'As Driver'),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : TabBarView(
              controller: _tab,
              children: [
                _RideList(rides: _passengerRides, onRefresh: _load),
                if (isDriver) _RideList(rides: _driverRides, onRefresh: _load, isDriver: true),
              ],
            ),
    );
  }
}

class _RideList extends StatelessWidget {
  final List<RideModel> rides;
  final Future<void> Function() onRefresh;
  final bool isDriver;

  const _RideList({required this.rides, required this.onRefresh, this.isDriver = false});

  @override
  Widget build(BuildContext context) {
    if (rides.isEmpty) {
      return const Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.directions_car_outlined, size: 64, color: AppTheme.textSecondary),
            SizedBox(height: 12),
            Text('No rides yet', style: TextStyle(color: AppTheme.textSecondary, fontSize: 16)),
          ],
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: rides.length,
        itemBuilder: (ctx, i) {
          final ride = rides[i];
          final isCompleted = ride.bookingStatus == 'completed' ||
              (isDriver && ride.status == 'completed');
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              RideCard(ride: ride),
              if (isCompleted)
                Padding(
                  padding: const EdgeInsets.only(bottom: 16, left: 4, right: 4),
                  child: OutlinedButton.icon(
                    onPressed: () => ctx.push('/ride/${ride.id}/receipt'),
                    icon: const Icon(Icons.receipt_long_rounded, size: 16),
                    label: const Text('View Receipt'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppTheme.accent,
                      side: BorderSide(color: AppTheme.accent.withOpacity(0.5)),
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
