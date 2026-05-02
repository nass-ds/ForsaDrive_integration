import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import '../../l10n/app_localizations.dart';
import '../../providers/auth_provider.dart';
import '../../providers/locale_provider.dart';
import '../../services/user_service.dart';
import '../../utils/app_theme.dart';
import '../../utils/constants.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});
  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tab;

  @override
  void initState() {
    super.initState();
    _tab = TabController(length: 2, vsync: this);
  }

  @override
  void dispose() {
    _tab.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.settings),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        bottom: TabBar(
          controller: _tab,
          labelColor: AppTheme.accent,
          unselectedLabelColor: Colors.white60,
          indicatorColor: AppTheme.accent,
          tabs: [
            Tab(icon: const Icon(Icons.person), text: l10n.profileTab),
            Tab(icon: const Icon(Icons.lock), text: l10n.passwordTab),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tab,
        children: const [
          _ProfileTab(),
          _PasswordTab(),
        ],
      ),
    );
  }
}

// ─── Profile Tab ─────────────────────────────────────────────────────────────

class _ProfileTab extends StatefulWidget {
  const _ProfileTab();
  @override
  State<_ProfileTab> createState() => _ProfileTabState();
}

class _ProfileTabState extends State<_ProfileTab> {
  final _formKey = GlobalKey<FormState>();
  late TextEditingController _usernameCtrl;
  late TextEditingController _phoneCtrl;
  late TextEditingController _bioCtrl;
  String? _gender;
  String? _governorate;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    final user = context.read<AuthProvider>().user!;
    _usernameCtrl = TextEditingController(text: user.username);
    _phoneCtrl = TextEditingController(text: user.phone ?? '');
    _bioCtrl = TextEditingController(text: user.bio ?? '');
    _gender = user.gender;
    _governorate = user.governorate;
  }

  @override
  void dispose() {
    _usernameCtrl.dispose();
    _phoneCtrl.dispose();
    _bioCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final l10n = AppLocalizations.of(context);
    setState(() => _loading = true);
    try {
      await context.read<UserService>().updateProfile({
        'username': _usernameCtrl.text.trim(),
        'phone': _phoneCtrl.text.trim(),
        'bio': _bioCtrl.text.trim(),
        'gender': _gender,
        'governorate': _governorate,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(l10n.profileUpdated),
          backgroundColor: AppTheme.success,
        ));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.danger,
        ));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final user = context.watch<AuthProvider>().user!;
    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: Form(
            key: _formKey,
            child: Column(children: [
              // ── Avatar card ──────────────────────────────────
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(children: [
                    CircleAvatar(
                      radius: 48,
                      backgroundColor: AppTheme.accent,
                      child: Text(
                        user.username[0].toUpperCase(),
                        style: const TextStyle(
                          fontSize: 36,
                          fontWeight: FontWeight.w800,
                          color: AppTheme.primary,
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      user.email,
                      style: const TextStyle(
                        color: AppTheme.textSecondary,
                        fontSize: 13,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      const Icon(Icons.star, color: AppTheme.accent, size: 16),
                      Text(
                        ' ${user.score.toStringAsFixed(1)}',
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                      if (user.isDriver) ...[
                        const SizedBox(width: 8),
                        Chip(
                          label: Text(l10n.driver,
                              style: const TextStyle(fontSize: 11)),
                          backgroundColor: AppTheme.accentLight,
                        ),
                      ],
                      if (user.isStudent) ...[
                        const SizedBox(width: 8),
                        const Chip(
                          label: Text('Student',
                              style: TextStyle(fontSize: 11)),
                          backgroundColor: Color(0xFFDBEAFE),
                        ),
                      ],
                    ]),
                  ]),
                ),
              ),

              // ── Form fields ──────────────────────────────────
              const SizedBox(height: 16),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(children: [
                    TextFormField(
                      controller: _usernameCtrl,
                      decoration: InputDecoration(
                        labelText: l10n.username,
                        prefixIcon: const Icon(Icons.person_outlined),
                      ),
                      validator: (v) => v == null || v.trim().length < 3
                          ? l10n.minChars(3)
                          : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _phoneCtrl,
                      decoration: InputDecoration(
                        labelText: l10n.phone,
                        prefixIcon: const Icon(Icons.phone_outlined),
                      ),
                      keyboardType: TextInputType.phone,
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      value: _gender,
                      decoration: InputDecoration(
                        labelText: l10n.gender,
                        prefixIcon: const Icon(Icons.wc),
                      ),
                      items: [
                        DropdownMenuItem(value: 'male', child: Text(l10n.male)),
                        DropdownMenuItem(
                            value: 'female', child: Text(l10n.female)),
                        DropdownMenuItem(
                            value: 'other', child: Text(l10n.other)),
                      ],
                      onChanged: (v) => setState(() => _gender = v),
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      value: _governorate,
                      decoration: InputDecoration(
                        labelText: l10n.governorate,
                        prefixIcon: const Icon(Icons.location_city),
                      ),
                      items: AppConstants.tunisianGovernorates
                          .map((g) =>
                              DropdownMenuItem(value: g, child: Text(g)))
                          .toList(),
                      onChanged: (v) => setState(() => _governorate = v),
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _bioCtrl,
                      maxLines: 3,
                      decoration: InputDecoration(
                        labelText: l10n.bio,
                        prefixIcon: const Icon(Icons.info_outlined),
                      ),
                    ),
                    const SizedBox(height: 20),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        onPressed: _loading ? null : _save,
                        child: _loading
                            ? const SizedBox(
                                height: 18,
                                width: 18,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2),
                              )
                            : Text(l10n.saveChanges),
                      ),
                    ),
                  ]),
                ),
              ),

              // ── Language picker ──────────────────────────────
              const SizedBox(height: 16),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        const Icon(Icons.language, color: AppTheme.primary),
                        const SizedBox(width: 8),
                        Text(
                          l10n.language,
                          style: const TextStyle(
                            fontWeight: FontWeight.w700,
                            fontSize: 15,
                          ),
                        ),
                      ]),
                      const SizedBox(height: 4),
                      Text(
                        l10n.selectLanguage,
                        style: const TextStyle(
                          color: AppTheme.textSecondary,
                          fontSize: 12,
                        ),
                      ),
                      const SizedBox(height: 14),
                      Row(children: const [
                        _LangChip(code: 'fr', label: '🇫🇷  Français'),
                        SizedBox(width: 8),
                        _LangChip(code: 'en', label: '🇬🇧  English'),
                        SizedBox(width: 8),
                        _LangChip(code: 'ar', label: '🇹🇳  عربي'),
                      ]),
                    ],
                  ),
                ),
              ),

              // ── Become driver ────────────────────────────────
              if (!user.isDriver) ...[
                const SizedBox(height: 16),
                Card(
                  color: AppTheme.accentLight,
                  child: ListTile(
                    leading:
                        const Icon(Icons.drive_eta, color: AppTheme.primary),
                    title: Text(
                      l10n.becomeADriver,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    subtitle: Text(l10n.earnMoneyByOfferingRides),
                    trailing: ElevatedButton(
                      onPressed: () async {
                        final l = AppLocalizations.of(context);
                        try {
                          await context.read<UserService>().becomeDriver();
                          if (mounted) {
                            ScaffoldMessenger.of(context)
                                .showSnackBar(SnackBar(
                              content: Text(l.youAreNowADriver),
                              backgroundColor: AppTheme.success,
                            ));
                          }
                        } catch (e) {
                          if (mounted) {
                            ScaffoldMessenger.of(context)
                                .showSnackBar(SnackBar(
                              content: Text(e.toString()),
                              backgroundColor: AppTheme.danger,
                            ));
                          }
                        }
                      },
                      child: Text(l10n.enable),
                    ),
                  ),
                ),
              ],

              // ── Student verification ─────────────────────────
              const SizedBox(height: 16),
              Card(
                color: user.isStudent
                    ? const Color(0xFFDBEAFE)
                    : const Color(0xFFF0F9FF),
                child: ListTile(
                  leading: Icon(
                    user.isStudent
                        ? Icons.verified_rounded
                        : Icons.school_rounded,
                    color: user.isStudent ? AppTheme.info : AppTheme.primary,
                  ),
                  title: Text(
                    user.isStudent
                        ? l10n.studentVerified
                        : l10n.verifyStudentStatus,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  subtitle: Text(
                    user.isStudent
                        ? l10n.fiftyPctDiscountActive
                        : l10n.getFiftyPctOff,
                  ),
                  trailing: user.isStudent
                      ? Chip(
                          label: Text(l10n.active,
                              style: const TextStyle(fontSize: 11)),
                          backgroundColor: AppTheme.info,
                          labelStyle:
                              const TextStyle(color: Colors.white),
                        )
                      : ElevatedButton(
                          onPressed: () => context.push('/student-verify'),
                          child: Text(l10n.verify),
                        ),
                ),
              ),

              // ── Organizations ────────────────────────────────
              const SizedBox(height: 16),
              Card(
                color: const Color(0xFF0A2040),
                child: ListTile(
                  leading: const Icon(Icons.business_rounded, color: Color(0xFFD4A017)),
                  title: const Text(
                    'Organization Discounts',
                    style: TextStyle(fontWeight: FontWeight.w700, color: Colors.white),
                  ),
                  subtitle: const Text(
                    'Apply for 10–50% off for your company or university',
                    style: TextStyle(color: Color(0xFF8A9BB5)),
                  ),
                  trailing: ElevatedButton(
                    onPressed: () => context.push('/organizations'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFFD4A017),
                      foregroundColor: const Color(0xFF0A1628),
                    ),
                    child: const Text('Apply'),
                  ),
                ),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

// ─── Language chip ────────────────────────────────────────────────────────────

class _LangChip extends StatelessWidget {
  final String code;
  final String label;
  const _LangChip({required this.code, required this.label});

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<LocaleProvider>();
    final isSelected = provider.locale.languageCode == code;
    return Expanded(
      child: GestureDetector(
        onTap: () => provider.setLocale(Locale(code)),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          padding: const EdgeInsets.symmetric(vertical: 10),
          decoration: BoxDecoration(
            color: isSelected ? AppTheme.primary : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: isSelected ? AppTheme.primary : AppTheme.border,
              width: isSelected ? 1.5 : 1,
            ),
          ),
          child: Center(
            child: Text(
              label,
              style: TextStyle(
                fontSize: 12,
                fontWeight:
                    isSelected ? FontWeight.w700 : FontWeight.w500,
                color: isSelected ? Colors.white : AppTheme.textSecondary,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ─── Password Tab ─────────────────────────────────────────────────────────────

class _PasswordTab extends StatefulWidget {
  const _PasswordTab();
  @override
  State<_PasswordTab> createState() => _PasswordTabState();
}

class _PasswordTabState extends State<_PasswordTab> {
  final _formKey = GlobalKey<FormState>();
  final _currentCtrl = TextEditingController();
  final _newCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  bool _loading = false;

  @override
  void dispose() {
    _currentCtrl.dispose();
    _newCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final l10n = AppLocalizations.of(context);
    setState(() => _loading = true);
    try {
      await context
          .read<UserService>()
          .changePassword(_currentCtrl.text, _newCtrl.text);
      _currentCtrl.clear();
      _newCtrl.clear();
      _confirmCtrl.clear();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(l10n.passwordChanged),
          backgroundColor: AppTheme.success,
        ));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.danger,
        ));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440),
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Form(
                key: _formKey,
                child: Column(children: [
                  const Icon(Icons.lock_outline,
                      size: 48, color: AppTheme.primary),
                  const SizedBox(height: 16),
                  Text(
                    l10n.changePasswordTitle,
                    style: const TextStyle(
                        fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 24),
                  TextFormField(
                    controller: _currentCtrl,
                    obscureText: true,
                    decoration: InputDecoration(
                      labelText: l10n.currentPassword,
                      prefixIcon: const Icon(Icons.lock_outlined),
                    ),
                    validator: (v) =>
                        v == null || v.isEmpty ? l10n.required : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _newCtrl,
                    obscureText: true,
                    decoration: InputDecoration(
                      labelText: l10n.newPassword,
                      prefixIcon: const Icon(Icons.lock_outlined),
                    ),
                    validator: (v) =>
                        v == null || v.length < 8 ? l10n.minChars(8) : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _confirmCtrl,
                    obscureText: true,
                    decoration: InputDecoration(
                      labelText: l10n.confirmNewPassword,
                      prefixIcon: const Icon(Icons.lock_outlined),
                    ),
                    validator: (v) =>
                        v != _newCtrl.text ? l10n.passwordsDoNotMatch : null,
                  ),
                  const SizedBox(height: 24),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: _loading ? null : _save,
                      child: _loading
                          ? const SizedBox(
                              height: 18,
                              width: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : Text(l10n.updatePassword),
                    ),
                  ),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}