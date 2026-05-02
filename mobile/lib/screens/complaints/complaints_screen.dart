import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../services/user_service.dart';
import '../../models/complaint_model.dart';
import '../../utils/app_theme.dart';
import '../../utils/constants.dart';

class ComplaintsScreen extends StatefulWidget {
  const ComplaintsScreen({super.key});
  @override
  State<ComplaintsScreen> createState() => _ComplaintsScreenState();
}

class _ComplaintsScreenState extends State<ComplaintsScreen> {
  List<ComplaintModel> _complaints = [];
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      _complaints = await context.read<UserService>().getComplaints();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      setState(() => _loading = false);
    }
  }

  void _showNewComplaintDialog() {
    showDialog(context: context, builder: (ctx) => _NewComplaintDialog(onSubmit: (data) async {
      await context.read<UserService>().submitComplaint(data);
      _load();
    }));
  }

  Color _statusColor(String status) => switch (status) {
    'resolved' => AppTheme.success,
    'dismissed' => AppTheme.textSecondary,
    _ => AppTheme.warning,
  };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Complaints'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          TextButton.icon(
            onPressed: _showNewComplaintDialog,
            icon: const Icon(Icons.add, color: AppTheme.accent),
            label: const Text('New Complaint', style: TextStyle(color: AppTheme.accent)),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _complaints.isEmpty
              ? Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  const Icon(Icons.report_outlined, size: 64, color: AppTheme.textSecondary),
                  const SizedBox(height: 12),
                  const Text('No complaints submitted', style: TextStyle(color: AppTheme.textSecondary)),
                  const SizedBox(height: 16),
                  ElevatedButton.icon(onPressed: _showNewComplaintDialog, icon: const Icon(Icons.add), label: const Text('File a Complaint')),
                ]))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.builder(
                    padding: const EdgeInsets.all(16),
                    itemCount: _complaints.length,
                    itemBuilder: (ctx, i) {
                      final c = _complaints[i];
                      return Card(
                        margin: const EdgeInsets.only(bottom: 12),
                        child: ListTile(
                          leading: const Icon(Icons.report, color: AppTheme.danger),
                          title: Text('Against: ${c.againstName}', style: const TextStyle(fontWeight: FontWeight.w600)),
                          subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(c.type.replaceAll('_', ' ').toUpperCase(), style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary, letterSpacing: 0.5)),
                            Text(c.description, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13)),
                            Text(DateFormat('MMM d, yyyy').format(c.createdAt), style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                          ]),
                          trailing: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                            decoration: BoxDecoration(color: _statusColor(c.status).withOpacity(0.1), borderRadius: BorderRadius.circular(8)),
                            child: Text(c.status.toUpperCase(), style: TextStyle(color: _statusColor(c.status), fontSize: 10, fontWeight: FontWeight.w700)),
                          ),
                          isThreeLine: true,
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}

class _NewComplaintDialog extends StatefulWidget {
  final Future<void> Function(Map<String, dynamic>) onSubmit;
  const _NewComplaintDialog({required this.onSubmit});
  @override
  State<_NewComplaintDialog> createState() => _NewComplaintDialogState();
}

class _NewComplaintDialogState extends State<_NewComplaintDialog> {
  final _againstCtrl = TextEditingController();
  final _descCtrl = TextEditingController();
  String _type = 'bad_behavior';
  bool _loading = false;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('File a Complaint'),
      content: SizedBox(
        width: 400,
        child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: _againstCtrl, decoration: const InputDecoration(labelText: 'User ID (against)'), keyboardType: TextInputType.number),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _type,
            decoration: const InputDecoration(labelText: 'Type'),
            items: AppConstants.complaintTypes.map((t) => DropdownMenuItem(value: t, child: Text(t.replaceAll('_', ' ').toUpperCase()))).toList(),
            onChanged: (v) => setState(() => _type = v!),
          ),
          const SizedBox(height: 12),
          TextField(controller: _descCtrl, maxLines: 4, decoration: const InputDecoration(labelText: 'Description', hintText: 'Describe the issue...')),
        ])),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        ElevatedButton(
          onPressed: _loading ? null : () async {
            final againstId = int.tryParse(_againstCtrl.text);
            if (againstId == null || _descCtrl.text.isEmpty) return;
            setState(() => _loading = true);
            try {
              await widget.onSubmit({'against_user_id': againstId, 'type': _type, 'description': _descCtrl.text});
              if (context.mounted) {
                Navigator.pop(context);
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Complaint submitted'), backgroundColor: AppTheme.success));
              }
            } catch (e) {
              if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
            } finally {
              if (mounted) setState(() => _loading = false);
            }
          },
          child: _loading ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Submit'),
        ),
      ],
    );
  }
}
