import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../models/organization_model.dart';
import '../../services/organization_service.dart';
import '../../utils/app_theme.dart';

// ── Member roles ──────────────────────────────────────────────────────────────
const _kRoles = ['member', 'manager', 'admin'];

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

const _orgTypes = ['Company', 'University', 'NGO', 'Government', 'Other'];

class OrganizationScreen extends StatefulWidget {
  const OrganizationScreen({super.key});

  @override
  State<OrganizationScreen> createState() => _OrganizationScreenState();
}

class _OrganizationScreenState extends State<OrganizationScreen> {
  List<OrganizationModel> _orgs = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      _orgs = await context.read<OrganizationService>().getMyOrganizations();
    } catch (_) {
      // silently show empty state — API may not exist yet
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _openApplyForm() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _ApplySheet(
        onSubmit: (data) async {
          await context.read<OrganizationService>().applyForOrganization(data);
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
              content: Text('Application submitted! We\'ll review it within 2–3 days.'),
              backgroundColor: AppTheme.success,
            ));
            _load();
          }
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: _kNavy,
      appBar: AppBar(
        backgroundColor: AppTheme.primaryDark,
        foregroundColor: _kWhite,
        title: const Text('Organizations'),
        elevation: 0,
        actions: [
          TextButton.icon(
            onPressed: _openApplyForm,
            icon: const Icon(Icons.add, color: _kGold, size: 18),
            label: const Text('Apply', style: TextStyle(color: _kGold, fontWeight: FontWeight.w700)),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        color: _kGold,
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(child: _HeroBanner(onApply: _openApplyForm)),
            SliverToBoxAdapter(child: _FeatureChips()),
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 20, 16, 8),
                child: Row(
                  children: [
                    const Icon(Icons.business_center_rounded, color: _kGold, size: 16),
                    const SizedBox(width: 8),
                    const Text(
                      'MY APPLICATIONS',
                      style: TextStyle(color: _kGold, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 0.5),
                    ),
                    const Spacer(),
                    if (!_loading)
                      Text('${_orgs.length}', style: const TextStyle(color: _kMuted, fontSize: 12)),
                  ],
                ),
              ),
            ),
            if (_loading)
              const SliverFillRemaining(
                child: Center(child: CircularProgressIndicator(color: _kGold)),
              )
            else if (_orgs.isEmpty)
              SliverFillRemaining(child: _EmptyState(onApply: _openApplyForm))
            else
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 100),
                sliver: SliverList(
                  delegate: SliverChildBuilderDelegate(
                    (_, i) => Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: _OrgCard(org: _orgs[i]),
                    ),
                    childCount: _orgs.length,
                  ),
                ),
              ),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openApplyForm,
        backgroundColor: _kGold,
        foregroundColor: _kNavy,
        icon: const Icon(Icons.add_business_rounded),
        label: const Text('Apply for Discount', style: TextStyle(fontWeight: FontWeight.w700)),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Hero Banner
// ─────────────────────────────────────────────────────────────────────────────
class _HeroBanner extends StatelessWidget {
  final VoidCallback onApply;
  const _HeroBanner({required this.onApply});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.all(16),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [AppTheme.primaryDark, const Color(0xFF0A2040)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: _kGold.withOpacity(0.3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: _kGold.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.business_rounded, color: _kGold, size: 24),
              ),
              const SizedBox(width: 12),
              const Expanded(
                child: Text(
                  'Discounts for Organizations',
                  style: TextStyle(color: _kWhite, fontSize: 17, fontWeight: FontWeight.w800),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          const Text(
            'Companies, universities, NGOs and government bodies can apply for exclusive discount codes — 10% to 50% off every ride, billed to your organization.',
            style: TextStyle(color: _kMuted, fontSize: 13, height: 1.5),
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: _kGold.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: _kGold.withOpacity(0.3)),
                ),
                child: const Text('10% – 50% OFF', style: TextStyle(color: _kGold, fontSize: 12, fontWeight: FontWeight.w800)),
              ),
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: _kGreen.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: _kGreen.withOpacity(0.3)),
                ),
                child: const Text('2–3 day review', style: TextStyle(color: _kGreen, fontSize: 12, fontWeight: FontWeight.w700)),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Feature Chips
// ─────────────────────────────────────────────────────────────────────────────
class _FeatureChips extends StatelessWidget {
  final _features = const [
    (Icons.discount_rounded, 'Discount codes 10–50%', _kGold),
    (Icons.groups_rounded, 'Group member management', Color(0xFF4FC3F7)),
    (Icons.receipt_long_rounded, 'Monthly invoicing', _kGreen),
    (Icons.support_agent_rounded, 'Priority support', Color(0xFFCE93D8)),
    (Icons.timer_rounded, '2–3 day review', _kMuted),
  ];

  const _FeatureChips();

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 44,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemCount: _features.length,
        itemBuilder: (_, i) {
          final (icon, label, color) = _features[i];
          return Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: color.withOpacity(0.08),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: color.withOpacity(0.25)),
            ),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(icon, color: color, size: 14),
              const SizedBox(width: 6),
              Text(label, style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w600)),
            ]),
          );
        },
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Org Card
// ─────────────────────────────────────────────────────────────────────────────
class _OrgCard extends StatefulWidget {
  final OrganizationModel org;
  const _OrgCard({required this.org});
  @override
  State<_OrgCard> createState() => _OrgCardState();
}

class _OrgCardState extends State<_OrgCard> {

  void _openMembers() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _MembersSheet(org: widget.org),
    );
  }

  @override
  Widget build(BuildContext context) {
    final org = widget.org;
    final (statusColor, statusIcon, statusLabel) = switch (org.status) {
      'approved'  => (_kGreen, Icons.check_circle_rounded, 'Approved'),
      'rejected'  => (_kRed, Icons.cancel_rounded, 'Rejected'),
      _           => (_kGold, Icons.hourglass_top_rounded, 'Pending Review'),
    };

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: _kCard,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: _kBorder),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header row
          Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: _kGold.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Center(
                  child: Text(
                    org.name[0].toUpperCase(),
                    style: const TextStyle(color: _kGold, fontSize: 18, fontWeight: FontWeight.w800),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(org.name, style: const TextStyle(color: _kWhite, fontSize: 15, fontWeight: FontWeight.w700)),
                    const SizedBox(height: 2),
                    Text(org.type, style: const TextStyle(color: _kMuted, fontSize: 12)),
                  ],
                ),
              ),
              // Status badge
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: statusColor.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: statusColor.withOpacity(0.3)),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(statusIcon, color: statusColor, size: 12),
                  const SizedBox(width: 5),
                  Text(statusLabel, style: TextStyle(color: statusColor, fontSize: 11, fontWeight: FontWeight.w700)),
                ]),
              ),
            ],
          ),
          const SizedBox(height: 14),
          const Divider(color: _kBorder, height: 1),
          const SizedBox(height: 14),
          // Details
          _InfoRow(Icons.percent_rounded, 'Discount', '${org.discountPercent}% off every ride'),
          const SizedBox(height: 8),
          _InfoRow(Icons.person_rounded, 'Contact', org.contactPerson),
          const SizedBox(height: 8),
          _InfoRow(Icons.calendar_today_rounded, 'Applied',
              DateFormat('MMM d, yyyy').format(org.createdAt)),
          // Discount code (approved only)
          if (org.status == 'approved' && org.discountCode != null) ...[
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: _kGreen.withOpacity(0.08),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: _kGreen.withOpacity(0.3)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.confirmation_number_rounded, color: _kGreen, size: 18),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Your Discount Code', style: TextStyle(color: _kMuted, fontSize: 11)),
                        Text(
                          org.discountCode!,
                          style: const TextStyle(color: _kGreen, fontSize: 16, fontWeight: FontWeight.w800, letterSpacing: 1.5),
                        ),
                      ],
                    ),
                  ),
                  GestureDetector(
                    onTap: () {
                      Clipboard.setData(ClipboardData(text: org.discountCode!));
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Code copied!'), backgroundColor: AppTheme.success),
                      );
                    },
                    child: Container(
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: _kGreen.withOpacity(0.12),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Icon(Icons.copy_rounded, color: _kGreen, size: 16),
                    ),
                  ),
                ],
              ),
            ),
          ],
          // Manage Members button (approved only)
          if (org.status == 'approved') ...[
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: _openMembers,
                icon: const Icon(Icons.groups_rounded, size: 18, color: _kGold),
                label: const Text('Manage Members', style: TextStyle(color: _kGold, fontWeight: FontWeight.w700)),
                style: OutlinedButton.styleFrom(
                  side: const BorderSide(color: _kGold, width: 1.5),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ),
          ],
          // Rejection reason
          if (org.status == 'rejected' && org.rejectionReason != null) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: _kRed.withOpacity(0.08),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: _kRed.withOpacity(0.3)),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.info_outline_rounded, color: _kRed, size: 16),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      org.rejectionReason!,
                      style: const TextStyle(color: _kRed, fontSize: 12, height: 1.4),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Members Sheet
// ─────────────────────────────────────────────────────────────────────────────
class _MembersSheet extends StatefulWidget {
  final OrganizationModel org;
  const _MembersSheet({required this.org});
  @override
  State<_MembersSheet> createState() => _MembersSheetState();
}

class _MembersSheetState extends State<_MembersSheet> {
  List<dynamic> _members = [];
  bool _loading = true;
  String? _discountCode;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final res = await context.read<OrganizationService>().getMembers(widget.org.id);
      setState(() {
        _members = res['members'] as List<dynamic>? ?? [];
        _discountCode = res['discount_code'] as String?;
      });
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: _kRed));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _openAddMember() {
    showDialog(
      context: context,
      builder: (ctx) => _AddMemberDialog(
        onAdd: (name, email, phone, role) async {
          await context.read<OrganizationService>().addMember(
            widget.org.id, name: name, email: email, phone: phone, role: role,
          );
          _load();
        },
      ),
    );
  }

  Future<void> _removeMember(int memberId, String name) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: _kCard,
        title: const Text('Remove Member', style: TextStyle(color: _kWhite)),
        content: Text('Remove $name from this organization?', style: const TextStyle(color: _kMuted)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel', style: TextStyle(color: _kMuted))),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: _kRed),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Remove', style: TextStyle(color: _kWhite)),
          ),
        ],
      ),
    );
    if (ok == true && mounted) {
      try {
        await context.read<OrganizationService>().removeMember(widget.org.id, memberId);
        _load();
      } catch (e) {
        if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: _kRed));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      height: MediaQuery.of(context).size.height * 0.80,
      decoration: const BoxDecoration(
        color: _kSurface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      child: Column(children: [
        // Handle
        Container(margin: const EdgeInsets.symmetric(vertical: 12), width: 40, height: 4,
            decoration: BoxDecoration(color: _kBorder, borderRadius: BorderRadius.circular(2))),
        // Header
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 12, 12),
          child: Row(children: [
            const Icon(Icons.groups_rounded, color: _kGold, size: 22),
            const SizedBox(width: 10),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(widget.org.name, style: const TextStyle(color: _kWhite, fontSize: 16, fontWeight: FontWeight.w800)),
              Text('${_members.length} member${_members.length != 1 ? 's' : ''}', style: const TextStyle(color: _kMuted, fontSize: 12)),
            ])),
            IconButton(
              onPressed: _openAddMember,
              icon: Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(color: _kGold.withOpacity(0.12), borderRadius: BorderRadius.circular(10)),
                child: const Icon(Icons.person_add_rounded, color: _kGold, size: 18),
              ),
            ),
          ]),
        ),
        // Discount code banner
        if (_discountCode != null)
          Container(
            margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: _kGreen.withOpacity(0.08),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: _kGreen.withOpacity(0.3)),
            ),
            child: Row(children: [
              const Icon(Icons.confirmation_number_rounded, color: _kGreen, size: 16),
              const SizedBox(width: 8),
              Text('Shared code: ', style: const TextStyle(color: _kMuted, fontSize: 12)),
              Text(_discountCode!, style: const TextStyle(color: _kGreen, fontWeight: FontWeight.w800, fontSize: 14, letterSpacing: 1.5)),
              const Spacer(),
              GestureDetector(
                onTap: () {
                  Clipboard.setData(ClipboardData(text: _discountCode!));
                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Code copied!'), backgroundColor: AppTheme.success));
                },
                child: const Icon(Icons.copy_rounded, color: _kGreen, size: 16),
              ),
            ]),
          ),
        const Divider(color: _kBorder, height: 1),
        // Members list
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator(color: _kGold))
              : _members.isEmpty
                  ? Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                      const Icon(Icons.person_off_rounded, color: _kMuted, size: 48),
                      const SizedBox(height: 12),
                      const Text('No members yet', style: TextStyle(color: _kMuted, fontSize: 15)),
                      const SizedBox(height: 16),
                      ElevatedButton.icon(
                        onPressed: _openAddMember,
                        icon: const Icon(Icons.person_add_rounded, size: 18),
                        label: const Text('Add First Member'),
                        style: ElevatedButton.styleFrom(backgroundColor: _kGold, foregroundColor: _kNavy),
                      ),
                    ]))
                  : ListView.separated(
                      padding: const EdgeInsets.all(16),
                      itemCount: _members.length,
                      separatorBuilder: (_, __) => const Divider(color: _kBorder, height: 1),
                      itemBuilder: (ctx, i) {
                        final m = _members[i] as Map<String, dynamic>;
                        final role = m['role'] as String? ?? 'member';
                        final roleColor = switch (role) {
                          'admin'   => _kRed,
                          'manager' => _kGold,
                          _         => _kMuted,
                        };
                        return ListTile(
                          contentPadding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                          leading: CircleAvatar(
                            backgroundColor: _kGold.withOpacity(0.12),
                            child: Text((m['name'] as String? ?? 'M')[0].toUpperCase(),
                                style: const TextStyle(color: _kGold, fontWeight: FontWeight.w700)),
                          ),
                          title: Text(m['name'] as String? ?? '', style: const TextStyle(color: _kWhite, fontWeight: FontWeight.w600, fontSize: 14)),
                          subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            if (m['email'] != null) Text(m['email'] as String, style: const TextStyle(color: _kMuted, fontSize: 11)),
                            if (m['phone'] != null) Text(m['phone'] as String, style: const TextStyle(color: _kMuted, fontSize: 11)),
                          ]),
                          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: roleColor.withOpacity(0.12),
                                borderRadius: BorderRadius.circular(20),
                              ),
                              child: Text(role, style: TextStyle(color: roleColor, fontSize: 10, fontWeight: FontWeight.w700)),
                            ),
                            const SizedBox(width: 4),
                            IconButton(
                              icon: const Icon(Icons.remove_circle_outline_rounded, color: _kRed, size: 20),
                              onPressed: () => _removeMember(m['id'] as int, m['name'] as String? ?? ''),
                            ),
                          ]),
                        );
                      },
                    ),
        ),
      ]),
    );
  }
}

class _AddMemberDialog extends StatefulWidget {
  final Future<void> Function(String name, String? email, String? phone, String role) onAdd;
  const _AddMemberDialog({required this.onAdd});
  @override
  State<_AddMemberDialog> createState() => _AddMemberDialogState();
}

class _AddMemberDialogState extends State<_AddMemberDialog> {
  final _nameCtrl  = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  String _role = 'member';
  bool _saving = false;

  @override
  void dispose() {
    _nameCtrl.dispose(); _emailCtrl.dispose(); _phoneCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_nameCtrl.text.trim().isEmpty) return;
    setState(() => _saving = true);
    try {
      await widget.onAdd(_nameCtrl.text.trim(), _emailCtrl.text.trim(), _phoneCtrl.text.trim(), _role);
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: _kRed));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: _kCard,
      title: const Text('Add Member', style: TextStyle(color: _kWhite, fontWeight: FontWeight.w700)),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        _DialogField(_nameCtrl, 'Full Name *', Icons.person_rounded),
        const SizedBox(height: 10),
        _DialogField(_emailCtrl, 'Email (optional)', Icons.email_rounded, keyboard: TextInputType.emailAddress),
        const SizedBox(height: 10),
        _DialogField(_phoneCtrl, 'Phone (optional)', Icons.phone_rounded, keyboard: TextInputType.phone),
        const SizedBox(height: 10),
        DropdownButtonFormField<String>(
          value: _role,
          dropdownColor: _kCard,
          style: const TextStyle(color: _kWhite, fontSize: 14),
          decoration: InputDecoration(
            labelText: 'Role',
            labelStyle: const TextStyle(color: _kMuted),
            filled: true, fillColor: _kSurface,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: _kBorder)),
            enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: _kBorder)),
          ),
          items: _kRoles.map((r) => DropdownMenuItem(value: r, child: Text(r.toUpperCase()))).toList(),
          onChanged: (v) => setState(() => _role = v ?? 'member'),
        ),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel', style: TextStyle(color: _kMuted))),
        ElevatedButton(
          style: ElevatedButton.styleFrom(backgroundColor: _kGold, foregroundColor: _kNavy),
          onPressed: _saving ? null : _save,
          child: _saving ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: _kNavy))
                         : const Text('Add', style: TextStyle(fontWeight: FontWeight.w700)),
        ),
      ],
    );
  }
}

class _DialogField extends StatelessWidget {
  final TextEditingController ctrl;
  final String hint;
  final IconData icon;
  final TextInputType? keyboard;
  const _DialogField(this.ctrl, this.hint, this.icon, {this.keyboard});
  @override
  Widget build(BuildContext context) => TextField(
    controller: ctrl,
    keyboardType: keyboard,
    style: const TextStyle(color: _kWhite, fontSize: 14),
    decoration: InputDecoration(
      labelText: hint, labelStyle: const TextStyle(color: _kMuted, fontSize: 13),
      prefixIcon: Icon(icon, color: _kMuted, size: 18),
      filled: true, fillColor: _kSurface,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: _kBorder)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: _kBorder)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: _kGold)),
    ),
  );
}

class _InfoRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  const _InfoRow(this.icon, this.label, this.value);

  @override
  Widget build(BuildContext context) => Row(children: [
    Icon(icon, color: _kMuted, size: 14),
    const SizedBox(width: 8),
    Text('$label: ', style: const TextStyle(color: _kMuted, fontSize: 12)),
    Expanded(child: Text(value, style: const TextStyle(color: _kWhite, fontSize: 12, fontWeight: FontWeight.w600))),
  ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Empty State
// ─────────────────────────────────────────────────────────────────────────────
class _EmptyState extends StatelessWidget {
  final VoidCallback onApply;
  const _EmptyState({required this.onApply});

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(40),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: _kGold.withOpacity(0.08),
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.business_rounded, color: _kGold, size: 48),
          ),
          const SizedBox(height: 20),
          const Text('No applications yet', style: TextStyle(color: _kWhite, fontSize: 18, fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          const Text(
            'Apply for your organization to get exclusive discount codes for your members.',
            textAlign: TextAlign.center,
            style: TextStyle(color: _kMuted, fontSize: 14, height: 1.5),
          ),
          const SizedBox(height: 24),
          ElevatedButton.icon(
            onPressed: onApply,
            icon: const Icon(Icons.add_business_rounded),
            label: const Text('Apply Now', style: TextStyle(fontWeight: FontWeight.w700)),
            style: ElevatedButton.styleFrom(
              backgroundColor: _kGold,
              foregroundColor: _kNavy,
              padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            ),
          ),
        ],
      ),
    ),
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Apply Bottom Sheet
// ─────────────────────────────────────────────────────────────────────────────
class _ApplySheet extends StatefulWidget {
  final Future<void> Function(Map<String, dynamic>) onSubmit;
  const _ApplySheet({required this.onSubmit});

  @override
  State<_ApplySheet> createState() => _ApplySheetState();
}

class _ApplySheetState extends State<_ApplySheet> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl    = TextEditingController();
  final _contactCtrl = TextEditingController();
  final _emailCtrl   = TextEditingController();
  final _phoneCtrl   = TextEditingController();
  final _domainCtrl  = TextEditingController();
  final _addressCtrl = TextEditingController();
  final _notesCtrl   = TextEditingController();

  String _selectedType = _orgTypes.first;
  double _discountPercent = 10;
  bool _submitting = false;

  @override
  void dispose() {
    _nameCtrl.dispose();
    _contactCtrl.dispose();
    _emailCtrl.dispose();
    _phoneCtrl.dispose();
    _domainCtrl.dispose();
    _addressCtrl.dispose();
    _notesCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _submitting = true);
    try {
      await widget.onSubmit({
        'name': _nameCtrl.text.trim(),
        'type': _selectedType.toLowerCase(),
        'contact_person': _contactCtrl.text.trim(),
        'contact_email': _emailCtrl.text.trim(),
        'phone': _phoneCtrl.text.trim(),
        'staff_email_domain': _domainCtrl.text.trim(),
        'discount_percent': _discountPercent.round(),
        'address': _addressCtrl.text.trim(),
        'notes': _notesCtrl.text.trim(),
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: _kRed),
        );
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.of(context).viewInsets.bottom;
    return Container(
      decoration: const BoxDecoration(
        color: _kSurface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + bottom),
      child: Form(
        key: _formKey,
        child: ListView(
          shrinkWrap: true,
          children: [
            // Handle bar
            Center(
              child: Container(
                margin: const EdgeInsets.symmetric(vertical: 12),
                width: 40,
                height: 4,
                decoration: BoxDecoration(color: _kBorder, borderRadius: BorderRadius.circular(2)),
              ),
            ),
            // Title
            const Text(
              'Apply for Organization Discount',
              style: TextStyle(color: _kWhite, fontSize: 18, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 4),
            const Text(
              'Fill in your organization details. We\'ll review and respond within 2–3 business days.',
              style: TextStyle(color: _kMuted, fontSize: 13, height: 1.4),
            ),
            const SizedBox(height: 20),

            // Organization Name
            _FormLabel('Organization Name *'),
            _Field(
              controller: _nameCtrl,
              hint: 'e.g. ISET Kélibia',
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Required' : null,
            ),
            const SizedBox(height: 14),

            // Organization Type
            _FormLabel('Organization Type *'),
            Container(
              decoration: BoxDecoration(
                color: _kCard,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: _kBorder),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 14),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  value: _selectedType,
                  dropdownColor: _kCard,
                  style: const TextStyle(color: _kWhite, fontSize: 14),
                  iconEnabledColor: _kMuted,
                  isExpanded: true,
                  items: _orgTypes.map((t) => DropdownMenuItem(value: t, child: Text(t))).toList(),
                  onChanged: (v) => setState(() => _selectedType = v!),
                ),
              ),
            ),
            const SizedBox(height: 14),

            // Contact Person
            _FormLabel('Contact Person *'),
            _Field(
              controller: _contactCtrl,
              hint: 'Full name',
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Required' : null,
            ),
            const SizedBox(height: 14),

            // Contact Email
            _FormLabel('Contact Email *'),
            _Field(
              controller: _emailCtrl,
              hint: 'billing@example.com',
              keyboard: TextInputType.emailAddress,
              validator: (v) {
                if (v == null || v.trim().isEmpty) return 'Required';
                if (!v.contains('@')) return 'Invalid email';
                return null;
              },
            ),
            const SizedBox(height: 14),

            // Phone
            _FormLabel('Phone Number'),
            _Field(
              controller: _phoneCtrl,
              hint: '+216 XX XXX XXX',
              keyboard: TextInputType.phone,
            ),
            const SizedBox(height: 14),

            // Staff Email Domain
            _FormLabel('Staff Email Domain (optional)'),
            _Field(
              controller: _domainCtrl,
              hint: 'e.g. university.tn',
              keyboard: TextInputType.url,
            ),
            const SizedBox(height: 14),

            // Discount slider
            _FormLabel('Requested Discount: ${_discountPercent.round()}%'),
            Row(
              children: [
                const Text('10%', style: TextStyle(color: _kMuted, fontSize: 12)),
                Expanded(
                  child: SliderTheme(
                    data: SliderThemeData(
                      activeTrackColor: _kGold,
                      inactiveTrackColor: _kBorder,
                      thumbColor: _kGold,
                      overlayColor: _kGold.withOpacity(0.12),
                      valueIndicatorColor: _kGold,
                      valueIndicatorTextStyle: const TextStyle(color: _kNavy, fontWeight: FontWeight.w700),
                    ),
                    child: Slider(
                      value: _discountPercent,
                      min: 10,
                      max: 50,
                      divisions: 8,
                      label: '${_discountPercent.round()}%',
                      onChanged: (v) => setState(() => _discountPercent = v),
                    ),
                  ),
                ),
                const Text('50%', style: TextStyle(color: _kMuted, fontSize: 12)),
              ],
            ),
            const SizedBox(height: 14),

            // Address
            _FormLabel('Address (optional)'),
            _Field(
              controller: _addressCtrl,
              hint: 'Street, City, Governorate',
            ),
            const SizedBox(height: 14),

            // Notes
            _FormLabel('Additional Notes'),
            _Field(
              controller: _notesCtrl,
              hint: 'Any special requirements or context...',
              maxLines: 3,
            ),
            const SizedBox(height: 24),

            // Submit button
            SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton(
                onPressed: _submitting ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: _kGold,
                  foregroundColor: _kNavy,
                  disabledBackgroundColor: _kGold.withOpacity(0.4),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  elevation: 0,
                ),
                child: _submitting
                    ? const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(strokeWidth: 2.5, color: _kNavy),
                      )
                    : const Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.send_rounded, size: 18),
                          SizedBox(width: 8),
                          Text('Submit Application', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
                        ],
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
// Small helpers
// ─────────────────────────────────────────────────────────────────────────────
class _FormLabel extends StatelessWidget {
  final String text;
  const _FormLabel(this.text);

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 6),
    child: Text(text, style: const TextStyle(color: _kMuted, fontSize: 13, fontWeight: FontWeight.w600)),
  );
}

class _Field extends StatelessWidget {
  final TextEditingController controller;
  final String hint;
  final TextInputType? keyboard;
  final int maxLines;
  final String? Function(String?)? validator;

  const _Field({
    required this.controller,
    required this.hint,
    this.keyboard,
    this.maxLines = 1,
    this.validator,
  });

  @override
  Widget build(BuildContext context) => TextFormField(
    controller: controller,
    keyboardType: keyboard,
    maxLines: maxLines,
    style: const TextStyle(color: _kWhite, fontSize: 14),
    validator: validator,
    decoration: InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(color: _kMuted, fontSize: 14),
      filled: true,
      fillColor: _kCard,
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
        borderSide: const BorderSide(color: _kGold),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: _kRed),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: _kRed),
      ),
      errorStyle: const TextStyle(color: _kRed, fontSize: 11),
    ),
  );
}
