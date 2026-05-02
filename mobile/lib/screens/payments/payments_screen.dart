import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../providers/auth_provider.dart';
import '../../services/user_service.dart';
import '../../models/payment_model.dart';
import '../../utils/app_theme.dart';

class PaymentsScreen extends StatefulWidget {
  const PaymentsScreen({super.key});
  @override
  State<PaymentsScreen> createState() => _PaymentsScreenState();
}

class _PaymentsScreenState extends State<PaymentsScreen> {
  List<PaymentModel> _payments = [];
  double _balance = 0;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final result = await context.read<UserService>().getPayments();
      setState(() { _payments = result.payments; _balance = result.balance; });
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      setState(() => _loading = false);
    }
  }

  Future<void> _deposit() async {
    final ctrl = TextEditingController();
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Add Funds'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          const Text('Enter amount to add to your wallet:'),
          const SizedBox(height: 12),
          TextField(
            controller: ctrl,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Amount (TND)', prefixText: 'TND '),
            autofocus: true,
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Deposit')),
        ],
      ),
    );
    if (confirm != true || !mounted) return;
    final amount = double.tryParse(ctrl.text);
    if (amount == null || amount <= 0) return;
    try {
      final newBalance = await context.read<UserService>().deposit(amount);
      context.read<AuthProvider>().updateUser(context.read<AuthProvider>().user!.copyWith(balance: newBalance));
      _load();
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Deposit successful!'), backgroundColor: AppTheme.success));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Wallet & Payments'), backgroundColor: AppTheme.primary, foregroundColor: Colors.white),
      body: Column(
        children: [
          // Balance card
          Container(
            width: double.infinity,
            margin: const EdgeInsets.all(16),
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              gradient: const LinearGradient(colors: [AppTheme.primary, Color(0xFF1a2d4a)]),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Column(
              children: [
                const Text('Wallet Balance', style: TextStyle(color: Colors.white70, fontSize: 14)),
                const SizedBox(height: 8),
                Text('${_balance.toStringAsFixed(2)} TND', style: const TextStyle(color: Colors.white, fontSize: 36, fontWeight: FontWeight.w800)),
                const SizedBox(height: 20),
                ElevatedButton.icon(
                  onPressed: _deposit,
                  icon: const Icon(Icons.add),
                  label: const Text('Add Funds'),
                  style: ElevatedButton.styleFrom(backgroundColor: AppTheme.accent, foregroundColor: AppTheme.primary),
                ),
              ],
            ),
          ),
          // Transactions
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _payments.isEmpty
                    ? const Center(child: Text('No transactions yet', style: TextStyle(color: AppTheme.textSecondary)))
                    : RefreshIndicator(
                        onRefresh: _load,
                        child: ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 16),
                          itemCount: _payments.length,
                          itemBuilder: (ctx, i) {
                            final p = _payments[i];
                            final isCredit = p.isCredit;
                            return ListTile(
                              leading: Container(
                                width: 44, height: 44,
                                decoration: BoxDecoration(
                                  color: (isCredit ? AppTheme.success : AppTheme.danger).withOpacity(0.1),
                                  borderRadius: BorderRadius.circular(10),
                                ),
                                child: Icon(isCredit ? Icons.arrow_downward : Icons.arrow_upward, color: isCredit ? AppTheme.success : AppTheme.danger, size: 20),
                              ),
                              title: Text(p.description ?? p.type, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                              subtitle: Text(DateFormat('MMM d, yyyy • h:mm a').format(p.createdAt), style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                              trailing: Text(
                                '${isCredit ? '+' : ''}${p.amount.toStringAsFixed(2)} TND',
                                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15, color: isCredit ? AppTheme.success : AppTheme.danger),
                              ),
                            );
                          },
                        ),
                      ),
          ),
        ],
      ),
    );
  }
}
