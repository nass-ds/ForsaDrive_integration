import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../services/user_service.dart';
import '../../utils/app_theme.dart';
import '../_shared/access_denied_screen.dart';

class AdminScreen extends StatefulWidget {
  const AdminScreen({super.key});
  @override
  State<AdminScreen> createState() => _AdminScreenState();
}

class _AdminScreenState extends State<AdminScreen> with SingleTickerProviderStateMixin {
  late TabController _tab;
  Map<String, dynamic> _stats = {};
  List<dynamic> _users = [];
  List<dynamic> _rides = [];
  List<dynamic> _verifications = [];
  List<dynamic> _complaints = [];
  List<dynamic> _organizations = [];
  bool _loading = false;
  String _userSearch = '';

  @override
  void initState() {
    super.initState();
    _tab = TabController(length: 6, vsync: this);
    _tab.addListener(() { if (!_tab.indexIsChanging) _loadTab(_tab.index); });
    _loadTab(0);
  }

  @override
  void dispose() {
    _tab.dispose();
    super.dispose();
  }

  Future<void> _loadTab(int index) async {
    setState(() => _loading = true);
    try {
      final svc = context.read<UserService>();
      switch (index) {
        case 0:
          final res = await svc.getAdminData('overview');
          setState(() => _stats = res['stats'] as Map<String, dynamic>? ?? {});
          break;
        case 1:
          final res = await svc.getAdminData('users');
          setState(() => _users = res['users'] as List<dynamic>? ?? []);
          break;
        case 2:
          final res = await svc.getAdminData('rides');
          setState(() => _rides = res['rides'] as List<dynamic>? ?? []);
          break;
        case 3:
          final res = await svc.getAdminData('verifications');
          setState(() => _verifications = res['verifications'] as List<dynamic>? ?? []);
          break;
        case 4:
          final res = await svc.getAdminData('complaints');
          setState(() => _complaints = res['complaints'] as List<dynamic>? ?? []);
          break;
        case 5:
          final orgs = await svc.getAdminOrganizations();
          setState(() => _organizations = orgs);
          break;
      }
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _action(Map<String, dynamic> data) async {
    try {
      await context.read<UserService>().adminAction(data);
      _loadTab(_tab.index);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    }
  }

  Future<void> _confirmAction(String title, String body, Map<String, dynamic> data) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.danger),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Confirm', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
    if (ok == true) await _action(data);
  }

  Future<void> _adjustBalanceDialog(int userId, String username) async {
    final ctrl = TextEditingController();
    final noteCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Adjust Balance — $username'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: ctrl, keyboardType: const TextInputType.numberWithOptions(signed: true, decimal: true), decoration: const InputDecoration(labelText: 'Amount (use - to deduct)', prefixText: 'TND ')),
          const SizedBox(height: 8),
          TextField(controller: noteCtrl, decoration: const InputDecoration(labelText: 'Note (optional)')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Apply')),
        ],
      ),
    );
    if (ok == true && ctrl.text.isNotEmpty) {
      final amount = double.tryParse(ctrl.text);
      if (amount != null) {
        await _action({'action': 'adjust_balance', 'user_id': userId, 'amount': amount, 'note': noteCtrl.text.isEmpty ? null : noteCtrl.text});
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAdmin = context.watch<AuthProvider>().isAdmin;
    if (!isAdmin) return const AccessDeniedScreen(reason: 'Admin access required');

    return Scaffold(
      backgroundColor: const Color(0xFFF5F6FA),
      appBar: AppBar(
        title: const Text('Admin Panel'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        bottom: TabBar(
          controller: _tab,
          labelColor: AppTheme.accent,
          unselectedLabelColor: Colors.white60,
          indicatorColor: AppTheme.accent,
          isScrollable: true,
          tabs: const [
            Tab(icon: Icon(Icons.dashboard_rounded), text: 'Overview'),
            Tab(icon: Icon(Icons.people_rounded), text: 'Users'),
            Tab(icon: Icon(Icons.directions_car_rounded), text: 'Rides'),
            Tab(icon: Icon(Icons.school_rounded), text: 'Verify'),
            Tab(icon: Icon(Icons.report_rounded), text: 'Complaints'),
            Tab(icon: Icon(Icons.business_rounded), text: 'Orgs'),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : TabBarView(
              controller: _tab,
              children: [
                _buildOverview(),
                _buildUsers(),
                _buildRides(),
                _buildVerifications(),
                _buildComplaints(),
                _buildOrganizations(),
              ],
            ),
    );
  }

  // ── Overview ──────────────────────────────────────────────────────────────
  Widget _buildOverview() {
    final s = _stats;
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const _SectionHeader('Platform Stats'),
        Wrap(spacing: 12, runSpacing: 12, children: [
          _StatCard('Total Users', '${s['total_users'] ?? 0}', Icons.people_rounded, AppTheme.info),
          _StatCard('Drivers', '${s['total_drivers'] ?? 0}', Icons.drive_eta_rounded, AppTheme.success),
          _StatCard('Total Rides', '${s['total_rides'] ?? 0}', Icons.directions_car_rounded, AppTheme.primary),
          _StatCard('Active Rides', '${s['active_rides'] ?? 0}', Icons.play_circle_rounded, const Color(0xFF00B894)),
          _StatCard('Completed', '${s['completed_rides'] ?? 0}', Icons.check_circle_rounded, AppTheme.success),
          _StatCard('Bookings', '${s['total_bookings'] ?? 0}', Icons.book_online_rounded, AppTheme.warning),
          _StatCard('Revenue', 'TND ${((s['total_revenue'] as num?) ?? 0).toStringAsFixed(2)}', Icons.attach_money_rounded, const Color(0xFF6C5CE7)),
          _StatCard('Suspended', '${s['suspended_users'] ?? 0}', Icons.block_rounded, AppTheme.danger),
          _StatCard('Pending Complaints', '${s['pending_complaints'] ?? 0}', Icons.report_rounded, AppTheme.danger),
          _StatCard('Pending Verify', '${s['pending_verifications'] ?? 0}', Icons.school_rounded, AppTheme.warning),
        ]),
      ]),
    );
  }

  // ── Users ─────────────────────────────────────────────────────────────────
  Widget _buildUsers() {
    final filtered = _userSearch.isEmpty
        ? _users
        : _users.where((u) {
            final name = (u['username'] as String? ?? '').toLowerCase();
            final email = (u['email'] as String? ?? '').toLowerCase();
            return name.contains(_userSearch) || email.contains(_userSearch);
          }).toList();

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
        child: TextField(
          decoration: InputDecoration(
            hintText: 'Search users...',
            prefixIcon: const Icon(Icons.search),
            filled: true,
            fillColor: Colors.white,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
            contentPadding: const EdgeInsets.symmetric(vertical: 10),
          ),
          onChanged: (v) => setState(() => _userSearch = v.toLowerCase()),
        ),
      ),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
        child: Text('${filtered.length} users', style: const TextStyle(color: AppTheme.textSecondary, fontSize: 12)),
      ),
      Expanded(
        child: RefreshIndicator(
          onRefresh: () => _loadTab(1),
          child: ListView.builder(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            itemCount: filtered.length,
            itemBuilder: (ctx, i) {
              final u = filtered[i] as Map<String, dynamic>;
              final isSuspended = u['suspended'] == 1 || u['suspended'] == true;
              final isAdmin = u['is_admin'] == 1 || u['is_admin'] == true;
              final isDriver = u['is_driver'] == 1 || u['is_driver'] == true;
              final isStudent = u['is_student'] == 1 || u['is_student'] == true;
              final isHelpdesk = u['is_helpdesk_agent'] == 1 || u['is_helpdesk_agent'] == true;
              final balance = (u['balance'] as num?)?.toDouble() ?? 0.0;
              final uid = u['id'] as int;

              return Card(
                margin: const EdgeInsets.only(bottom: 8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      CircleAvatar(
                        backgroundColor: isSuspended ? AppTheme.danger.withOpacity(0.15) : AppTheme.accentLight,
                        child: Text((u['username'] as String? ?? 'U')[0].toUpperCase(),
                            style: TextStyle(color: isSuspended ? AppTheme.danger : AppTheme.primary, fontWeight: FontWeight.w700)),
                      ),
                      const SizedBox(width: 10),
                      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(u['username'] as String? ?? '', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                        Text(u['email'] as String? ?? '', style: const TextStyle(color: AppTheme.textSecondary, fontSize: 12)),
                      ])),
                      Text('TND ${balance.toStringAsFixed(2)}', style: const TextStyle(fontWeight: FontWeight.w700, color: AppTheme.success, fontSize: 13)),
                      PopupMenuButton<String>(
                        icon: const Icon(Icons.more_vert, color: AppTheme.textSecondary),
                        onSelected: (val) async {
                          switch (val) {
                            case 'ban':
                              await _confirmAction('Ban User', 'Ban ${u['username']}? They will not be able to log in.', {'action': 'ban_user', 'user_id': uid, 'reason': 'Violation of terms'});
                              break;
                            case 'unban':
                              await _action({'action': 'unban_user', 'user_id': uid});
                              break;
                            case 'make_admin':
                              await _confirmAction('Make Admin', 'Give ${u['username']} admin privileges?', {'action': 'make_admin', 'user_id': uid});
                              break;
                            case 'remove_admin':
                              await _confirmAction('Remove Admin', 'Remove admin role from ${u['username']}?', {'action': 'remove_admin', 'user_id': uid});
                              break;
                            case 'make_driver':
                              await _action({'action': 'make_driver', 'user_id': uid});
                              break;
                            case 'make_helpdesk':
                              await _action({'action': 'make_helpdesk', 'user_id': uid});
                              break;
                            case 'remove_helpdesk':
                              await _action({'action': 'remove_helpdesk', 'user_id': uid});
                              break;
                            case 'balance':
                              await _adjustBalanceDialog(uid, u['username'] as String? ?? '');
                              break;
                          }
                        },
                        itemBuilder: (_) => [
                          if (!isSuspended) const PopupMenuItem(value: 'ban', child: ListTile(leading: Icon(Icons.block, color: AppTheme.danger, size: 20), title: Text('Ban User'), dense: true)),
                          if (isSuspended) const PopupMenuItem(value: 'unban', child: ListTile(leading: Icon(Icons.check_circle, color: AppTheme.success, size: 20), title: Text('Unban User'), dense: true)),
                          if (!isAdmin) const PopupMenuItem(value: 'make_admin', child: ListTile(leading: Icon(Icons.admin_panel_settings, color: AppTheme.primary, size: 20), title: Text('Make Admin'), dense: true)),
                          if (isAdmin) const PopupMenuItem(value: 'remove_admin', child: ListTile(leading: Icon(Icons.remove_moderator, color: AppTheme.danger, size: 20), title: Text('Remove Admin'), dense: true)),
                          if (!isDriver) const PopupMenuItem(value: 'make_driver', child: ListTile(leading: Icon(Icons.drive_eta, color: AppTheme.info, size: 20), title: Text('Make Driver'), dense: true)),
                          if (!isHelpdesk) const PopupMenuItem(value: 'make_helpdesk', child: ListTile(leading: Icon(Icons.support_agent, color: AppTheme.warning, size: 20), title: Text('Make Helpdesk'), dense: true)),
                          if (isHelpdesk) const PopupMenuItem(value: 'remove_helpdesk', child: ListTile(leading: Icon(Icons.person_remove, color: AppTheme.danger, size: 20), title: Text('Remove Helpdesk'), dense: true)),
                          const PopupMenuItem(value: 'balance', child: ListTile(leading: Icon(Icons.account_balance_wallet, color: AppTheme.success, size: 20), title: Text('Adjust Balance'), dense: true)),
                        ],
                      ),
                    ]),
                    const SizedBox(height: 8),
                    Wrap(spacing: 6, children: [
                      if (isAdmin) _Badge('Admin', AppTheme.primary),
                      if (isDriver) _Badge('Driver', AppTheme.info),
                      if (isStudent) _Badge('Student', AppTheme.success),
                      if (isHelpdesk) _Badge('Helpdesk', AppTheme.warning),
                      if (isSuspended) _Badge('Banned', AppTheme.danger),
                    ]),
                    if (isSuspended && u['ban_reason'] != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text('Reason: ${u['ban_reason']}', style: const TextStyle(color: AppTheme.danger, fontSize: 11)),
                      ),
                  ]),
                ),
              );
            },
          ),
        ),
      ),
    ]);
  }

  // ── Rides ─────────────────────────────────────────────────────────────────
  Widget _buildRides() {
    return RefreshIndicator(
      onRefresh: () => _loadTab(2),
      child: ListView.builder(
        padding: const EdgeInsets.all(12),
        itemCount: _rides.length,
        itemBuilder: (ctx, i) {
          final r = _rides[i] as Map<String, dynamic>;
          final status = r['status'] as String? ?? '';
          final statusColor = switch (status) {
            'completed' => AppTheme.success,
            'in_progress' => AppTheme.info,
            'cancelled' => AppTheme.danger,
            _ => AppTheme.warning,
          };
          return Card(
            margin: const EdgeInsets.only(bottom: 8),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text('${r['from_location']} → ${r['to_location']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14))),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(color: statusColor.withOpacity(0.15), borderRadius: BorderRadius.circular(20)),
                    child: Text(status.toUpperCase(), style: TextStyle(color: statusColor, fontSize: 10, fontWeight: FontWeight.w700)),
                  ),
                ]),
                const SizedBox(height: 6),
                Text('Driver: ${r['driver_name']} (${r['driver_email']})', style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                Text('Departure: ${r['departure_time']}', style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                Text('Price: TND ${r['price']} · Seats: ${r['booked_seats']}/${r['available_seats']} booked', style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                if (status == 'active' || status == 'in_progress')
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton.icon(
                      onPressed: () => _confirmAction('Cancel Ride', 'Cancel this ride and all its bookings?', {'action': 'cancel_ride', 'ride_id': r['id']}),
                      icon: const Icon(Icons.cancel_rounded, size: 16, color: AppTheme.danger),
                      label: const Text('Cancel Ride', style: TextStyle(color: AppTheme.danger, fontSize: 12)),
                    ),
                  ),
              ]),
            ),
          );
        },
      ),
    );
  }

  // ── Verifications ─────────────────────────────────────────────────────────
  Widget _buildVerifications() {
    return RefreshIndicator(
      onRefresh: () => _loadTab(3),
      child: _verifications.isEmpty
          ? const Center(child: Text('No verifications found', style: TextStyle(color: AppTheme.textSecondary)))
          : ListView.builder(
              padding: const EdgeInsets.all(12),
              itemCount: _verifications.length,
              itemBuilder: (ctx, i) {
                final v = _verifications[i] as Map<String, dynamic>;
                final status = v['status'] as String? ?? 'pending';
                final statusColor = switch (status) {
                  'approved' => AppTheme.success,
                  'rejected' => AppTheme.danger,
                  _ => AppTheme.warning,
                };
                return Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        const Icon(Icons.school_rounded, color: AppTheme.primary, size: 20),
                        const SizedBox(width: 8),
                        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(v['username'] as String? ?? '', style: const TextStyle(fontWeight: FontWeight.w700)),
                          Text(v['email'] as String? ?? '', style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                        ])),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(color: statusColor.withOpacity(0.15), borderRadius: BorderRadius.circular(20)),
                          child: Text(status.toUpperCase(), style: TextStyle(color: statusColor, fontSize: 10, fontWeight: FontWeight.w700)),
                        ),
                      ]),
                      Text('Submitted: ${v['created_at']}', style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                      if (v['document_path'] != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text('Doc: ${v['document_path']}', style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                        ),
                      if (status == 'pending')
                        Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                          TextButton.icon(
                            onPressed: () => _action({'action': 'reject_verification', 'verification_id': v['id']}),
                            icon: const Icon(Icons.close, size: 16, color: AppTheme.danger),
                            label: const Text('Reject', style: TextStyle(color: AppTheme.danger)),
                          ),
                          const SizedBox(width: 8),
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.success, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8)),
                            onPressed: () => _action({'action': 'approve_verification', 'verification_id': v['id']}),
                            icon: const Icon(Icons.check, size: 16),
                            label: const Text('Approve'),
                          ),
                        ]),
                    ]),
                  ),
                );
              },
            ),
    );
  }

  // ── Complaints ────────────────────────────────────────────────────────────
  Widget _buildComplaints() {
    return RefreshIndicator(
      onRefresh: () => _loadTab(4),
      child: _complaints.isEmpty
          ? const Center(child: Text('No complaints', style: TextStyle(color: AppTheme.textSecondary)))
          : ListView.builder(
              padding: const EdgeInsets.all(12),
              itemCount: _complaints.length,
              itemBuilder: (ctx, i) {
                final c = _complaints[i] as Map<String, dynamic>;
                final status = c['status'] as String? ?? 'pending';
                final statusColor = switch (status) {
                  'resolved' => AppTheme.success,
                  'dismissed' => AppTheme.textSecondary,
                  _ => AppTheme.danger,
                };
                return Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        const Icon(Icons.report_rounded, color: AppTheme.danger, size: 20),
                        const SizedBox(width: 8),
                        Expanded(child: Text('${c['from_name']} → ${c['against_name']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13))),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(color: statusColor.withOpacity(0.15), borderRadius: BorderRadius.circular(20)),
                          child: Text(status.toUpperCase(), style: TextStyle(color: statusColor, fontSize: 10, fontWeight: FontWeight.w700)),
                        ),
                      ]),
                      const SizedBox(height: 4),
                      if (c['type'] != null)
                        Text('Type: ${c['type']}', style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary, fontWeight: FontWeight.w600)),
                      Text(c['description'] as String? ?? '', style: const TextStyle(fontSize: 13), maxLines: 3, overflow: TextOverflow.ellipsis),
                      Text('${c['created_at']}', style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                      if (status == 'pending')
                        Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                          TextButton.icon(
                            onPressed: () => _action({'action': 'resolve_complaint', 'complaint_id': c['id'], 'status': 'dismissed'}),
                            icon: const Icon(Icons.close, size: 16, color: AppTheme.textSecondary),
                            label: const Text('Dismiss', style: TextStyle(color: AppTheme.textSecondary)),
                          ),
                          const SizedBox(width: 8),
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.success, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8)),
                            onPressed: () => _action({'action': 'resolve_complaint', 'complaint_id': c['id'], 'status': 'resolved'}),
                            icon: const Icon(Icons.check, size: 16),
                            label: const Text('Resolve'),
                          ),
                        ]),
                    ]),
                  ),
                );
              },
            ),
    );
  }

  // ── Organizations ─────────────────────────────────────────────────────────
  Widget _buildOrganizations() {
    return RefreshIndicator(
      onRefresh: () => _loadTab(5),
      child: _organizations.isEmpty
          ? const Center(child: Text('No organization applications', style: TextStyle(color: AppTheme.textSecondary)))
          : ListView.builder(
              padding: const EdgeInsets.all(12),
              itemCount: _organizations.length,
              itemBuilder: (ctx, i) {
                final o = _organizations[i] as Map<String, dynamic>;
                final status = o['status'] as String? ?? 'pending';
                final statusColor = switch (status) {
                  'approved' => AppTheme.success,
                  'rejected' => AppTheme.danger,
                  _ => AppTheme.warning,
                };
                return Card(
                  margin: const EdgeInsets.only(bottom: 10),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        CircleAvatar(
                          backgroundColor: AppTheme.primary.withOpacity(0.12),
                          child: Text((o['name'] as String? ?? 'O')[0].toUpperCase(),
                              style: const TextStyle(color: AppTheme.primary, fontWeight: FontWeight.w800)),
                        ),
                        const SizedBox(width: 10),
                        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(o['name'] as String? ?? '', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                          Text('${o['type']} · Applied by ${o['applicant_name']}', style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                        ])),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(color: statusColor.withOpacity(0.15), borderRadius: BorderRadius.circular(20)),
                          child: Text(status.toUpperCase(), style: TextStyle(color: statusColor, fontSize: 10, fontWeight: FontWeight.w700)),
                        ),
                      ]),
                      const Divider(height: 16),
                      _OrgRow(Icons.percent_rounded, 'Discount', '${o['discount_percent']}% off every ride'),
                      _OrgRow(Icons.person_rounded, 'Contact', '${o['contact_person']} (${o['contact_email']})'),
                      if (o['phone'] != null) _OrgRow(Icons.phone_rounded, 'Phone', o['phone'] as String),
                      if (o['address'] != null) _OrgRow(Icons.location_on_rounded, 'Address', o['address'] as String),
                      if (o['notes'] != null) _OrgRow(Icons.notes_rounded, 'Notes', o['notes'] as String),
                      if (status == 'approved' && o['discount_code'] != null)
                        _OrgRow(Icons.confirmation_number_rounded, 'Discount Code', o['discount_code'] as String, valueColor: AppTheme.success),
                      if (status == 'rejected' && o['rejection_reason'] != null)
                        _OrgRow(Icons.info_rounded, 'Rejection Reason', o['rejection_reason'] as String, valueColor: AppTheme.danger),
                      Text('Applied: ${o['created_at']}', style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                      if (status == 'pending')
                        Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                          TextButton.icon(
                            onPressed: () async {
                              final reason = await _rejectReasonDialog(o['name'] as String? ?? '');
                              if (reason != null && mounted) {
                                await context.read<UserService>().reviewOrganization(
                                  o['id'] as int, 'rejected', rejectionReason: reason,
                                );
                                _loadTab(5);
                              }
                            },
                            icon: const Icon(Icons.close, size: 16, color: AppTheme.danger),
                            label: const Text('Reject', style: TextStyle(color: AppTheme.danger)),
                          ),
                          const SizedBox(width: 8),
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.success, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8)),
                            onPressed: () async {
                              await context.read<UserService>().reviewOrganization(o['id'] as int, 'approved');
                              _loadTab(5);
                            },
                            icon: const Icon(Icons.check, size: 16),
                            label: const Text('Approve'),
                          ),
                        ]),
                    ]),
                  ),
                );
              },
            ),
    );
  }

  Future<String?> _rejectReasonDialog(String orgName) async {
    final ctrl = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Reject "$orgName"'),
        content: TextField(
          controller: ctrl,
          maxLines: 3,
          decoration: const InputDecoration(hintText: 'Reason for rejection (optional)', border: OutlineInputBorder()),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, null), child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.danger, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, ctrl.text),
            child: const Text('Reject'),
          ),
        ],
      ),
    );
  }
}

class _OrgRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final Color? valueColor;
  const _OrgRow(this.icon, this.label, this.value, {this.valueColor});
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Icon(icon, size: 14, color: AppTheme.textSecondary),
      const SizedBox(width: 6),
      Text('$label: ', style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
      Expanded(child: Text(value, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: valueColor ?? AppTheme.textPrimary))),
    ]),
  );
}

class _SectionHeader extends StatelessWidget {
  final String text;
  const _SectionHeader(this.text);
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Text(text, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppTheme.textPrimary)),
  );
}

class _StatCard extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  final Color color;
  const _StatCard(this.label, this.value, this.icon, this.color);

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 160,
    child: Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(color: color.withOpacity(0.12), borderRadius: BorderRadius.circular(10)),
            child: Icon(icon, color: color, size: 22),
          ),
          const SizedBox(height: 10),
          Text(value, style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: color)),
          const SizedBox(height: 2),
          Text(label, style: const TextStyle(color: AppTheme.textSecondary, fontSize: 11)),
        ]),
      ),
    ),
  );
}

class _Badge extends StatelessWidget {
  final String label;
  final Color color;
  const _Badge(this.label, this.color);
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
    decoration: BoxDecoration(color: color.withOpacity(0.12), borderRadius: BorderRadius.circular(20)),
    child: Text(label, style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w700)),
  );
}
