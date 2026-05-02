import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../providers/auth_provider.dart';
import '../../services/user_service.dart';
import '../../utils/app_theme.dart';

class RatingsScreen extends StatefulWidget {
  const RatingsScreen({super.key});
  @override
  State<RatingsScreen> createState() => _RatingsScreenState();
}

class _RatingsScreenState extends State<RatingsScreen> {
  List<dynamic> _ratings = [];
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      _ratings = await context.read<UserService>().getRatings();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final userId = context.read<AuthProvider>().user!.id;
    return Scaffold(
      appBar: AppBar(title: const Text('Ratings'), backgroundColor: AppTheme.primary, foregroundColor: Colors.white),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _ratings.isEmpty
              ? const Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(Icons.star_outline, size: 64, color: AppTheme.textSecondary),
                  SizedBox(height: 12),
                  Text('No ratings yet', style: TextStyle(color: AppTheme.textSecondary)),
                ]))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.builder(
                    padding: const EdgeInsets.all(16),
                    itemCount: _ratings.length,
                    itemBuilder: (ctx, i) {
                      final r = _ratings[i] as Map<String, dynamic>;
                      final isReceived = r['to_user_id'] == userId;
                      final score = r['score'] as int? ?? 5;
                      final date = DateTime.tryParse(r['created_at'] as String? ?? '');
                      return Card(
                        margin: const EdgeInsets.only(bottom: 12),
                        child: ListTile(
                          leading: Container(
                            width: 44, height: 44,
                            decoration: BoxDecoration(
                              color: isReceived ? AppTheme.success.withOpacity(0.1) : AppTheme.info.withOpacity(0.1),
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Icon(isReceived ? Icons.arrow_downward : Icons.arrow_upward, color: isReceived ? AppTheme.success : AppTheme.info, size: 20),
                          ),
                          title: Row(children: [
                            Text(isReceived ? 'From ${r['from_name']}' : 'To ${r['to_name']}', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                            const SizedBox(width: 8),
                            Row(children: List.generate(5, (j) => Icon(j < score ? Icons.star : Icons.star_border, color: AppTheme.accent, size: 14))),
                          ]),
                          subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            if (r['comment'] != null && (r['comment'] as String).isNotEmpty)
                              Text('"${r['comment']}"', style: const TextStyle(fontStyle: FontStyle.italic, fontSize: 13)),
                            if (date != null)
                              Text(DateFormat('MMM d, yyyy').format(date), style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                          ]),
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}
