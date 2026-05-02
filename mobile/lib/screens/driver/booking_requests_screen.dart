import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../services/ride_service.dart';
import '../../utils/app_theme.dart';
import '../../config/api_config.dart';

class BookingRequestsScreen extends StatefulWidget {
  const BookingRequestsScreen({super.key});
  @override
  State<BookingRequestsScreen> createState() => _BookingRequestsScreenState();
}

class _BookingRequestsScreenState extends State<BookingRequestsScreen> {
  List<dynamic> _requests = [];
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      _requests = await context.read<RideService>().getBookingRequests();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      setState(() => _loading = false);
    }
  }

  Future<void> _respond(int bookingId, String action) async {
    try {
      await context.read<RideService>().respondToBooking(bookingId, action);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(action == 'accept' ? 'Booking accepted!' : 'Booking rejected'), backgroundColor: action == 'accept' ? AppTheme.success : AppTheme.danger),
      );
      _load();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Booking Requests'), backgroundColor: AppTheme.primary, foregroundColor: Colors.white),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _requests.isEmpty
              ? const Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(Icons.people_outline, size: 64, color: AppTheme.textSecondary),
                  SizedBox(height: 12),
                  Text('No pending booking requests', style: TextStyle(color: AppTheme.textSecondary)),
                ]))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.builder(
                    padding: const EdgeInsets.all(16),
                    itemCount: _requests.length,
                    itemBuilder: (ctx, i) {
                      final r = _requests[i] as Map<String, dynamic>;
                      final depTime = DateTime.tryParse(r['departure_time'] as String? ?? '');
                      return Card(
                        margin: const EdgeInsets.only(bottom: 16),
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(children: [
                                CircleAvatar(
                                  backgroundImage: NetworkImage(ApiConfig.profilePicture(r['passenger_pic'] as String?)),
                                  onBackgroundImageError: (_, __) {},
                                ),
                                const SizedBox(width: 12),
                                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                  Text(r['passenger_name'] as String? ?? 'Passenger', style: const TextStyle(fontWeight: FontWeight.w700)),
                                  Row(children: [
                                    const Icon(Icons.star, color: AppTheme.accent, size: 14),
                                    Text('${r['passenger_score'] ?? 5.0}', style: const TextStyle(fontSize: 12)),
                                    if (r['is_student'] == 1 || r['is_student'] == true) ...[
                                      const SizedBox(width: 8),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                        decoration: BoxDecoration(color: AppTheme.info.withOpacity(0.15), borderRadius: BorderRadius.circular(10)),
                                        child: const Text('Student', style: TextStyle(color: AppTheme.info, fontSize: 10, fontWeight: FontWeight.w600)),
                                      ),
                                    ],
                                  ]),
                                ])),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                  decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.1), borderRadius: BorderRadius.circular(10)),
                                  child: Text('${r['seats']} seat(s)', style: const TextStyle(color: AppTheme.warning, fontWeight: FontWeight.w700)),
                                ),
                              ]),
                              const Divider(height: 20),
                              Row(children: [
                                const Icon(Icons.trip_origin, color: AppTheme.success, size: 14),
                                const SizedBox(width: 6),
                                Text(r['from_location'] as String? ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
                                const SizedBox(width: 8),
                                const Icon(Icons.arrow_forward, size: 14, color: AppTheme.textSecondary),
                                const SizedBox(width: 8),
                                const Icon(Icons.location_on, color: AppTheme.danger, size: 14),
                                const SizedBox(width: 6),
                                Text(r['to_location'] as String? ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
                              ]),
                              const SizedBox(height: 8),
                              if (depTime != null)
                                Text(DateFormat('EEE, MMM d • h:mm a').format(depTime), style: const TextStyle(color: AppTheme.textSecondary, fontSize: 13)),
                              const SizedBox(height: 12),
                              Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                                OutlinedButton.icon(
                                  onPressed: () => _respond(r['id'] as int, 'reject'),
                                  icon: const Icon(Icons.close, size: 16),
                                  label: const Text('Reject'),
                                  style: OutlinedButton.styleFrom(foregroundColor: AppTheme.danger, side: const BorderSide(color: AppTheme.danger)),
                                ),
                                const SizedBox(width: 8),
                                ElevatedButton.icon(
                                  onPressed: () => _respond(r['id'] as int, 'accept'),
                                  icon: const Icon(Icons.check, size: 16),
                                  label: const Text('Accept'),
                                ),
                              ]),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}
