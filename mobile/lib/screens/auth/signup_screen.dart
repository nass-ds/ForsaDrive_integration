import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../utils/app_theme.dart';
import '../../utils/constants.dart';

class SignupScreen extends StatefulWidget {
  const SignupScreen({super.key});
  @override
  State<SignupScreen> createState() => _SignupScreenState();
}

class _SignupScreenState extends State<SignupScreen> {
  final _formKey = GlobalKey<FormState>();
  final _usernameCtrl = TextEditingController();
  final _emailCtrl    = TextEditingController();
  final _phoneCtrl    = TextEditingController();
  final _passCtrl     = TextEditingController();
  final _confirmCtrl  = TextEditingController();

  String? _governorate;
  String? _gender;
  DateTime? _dob;
  bool _isDriver      = false;
  bool _obscurePass   = true;
  bool _obscureConfirm = true;
  bool _loading       = false;
  bool _maybeStudent  = false;

  static const _studentDomainHint = 'Student email detected — you\'ll get 50% off rides automatically!';

  @override
  void dispose() {
    _usernameCtrl.dispose();
    _emailCtrl.dispose();
    _phoneCtrl.dispose();
    _passCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  void _onEmailChanged(String val) {
    final parts = val.toLowerCase().split('@');
    final looksLikeStudent = parts.length == 2 &&
        (parts[1].endsWith('.tn') || parts[1].endsWith('.edu') || parts[1].contains('esprit') || parts[1].contains('enit') || parts[1].contains('insat'));
    if (looksLikeStudent != _maybeStudent) setState(() => _maybeStudent = looksLikeStudent);
  }

  Future<void> _pickDob() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _dob ?? DateTime(now.year - 20),
      firstDate: DateTime(1950),
      lastDate: DateTime(now.year - 16),
      helpText: 'Select date of birth',
      builder: (ctx, child) => Theme(
        data: Theme.of(ctx).copyWith(
          colorScheme: const ColorScheme.dark(
            primary: AppTheme.accent,
            onPrimary: AppTheme.primaryDark,
            surface: AppTheme.darkSurface,
            onSurface: AppTheme.darkTextPrimary,
          ),
        ),
        child: child!,
      ),
    );
    if (picked != null) setState(() => _dob = picked);
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _loading = true);
    final auth = context.read<AuthProvider>();
    final dobStr = _dob != null
        ? '${_dob!.year}-${_dob!.month.toString().padLeft(2, '0')}-${_dob!.day.toString().padLeft(2, '0')}'
        : null;
    final ok = await auth.signup(
      username: _usernameCtrl.text.trim(),
      email: _emailCtrl.text.trim(),
      password: _passCtrl.text,
      region: 'TN',
      isDriver: _isDriver,
      phone: _phoneCtrl.text.trim().isEmpty ? null : _phoneCtrl.text.trim(),
      gender: _gender,
      dateOfBirth: dobStr,
      governorate: _governorate,
    );
    if (!mounted) return;
    setState(() => _loading = false);
    if (ok) {
      context.go('/home');
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(auth.error ?? 'Signup failed'),
          backgroundColor: AppTheme.danger,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: AppTheme.primary,
      body: SafeArea(
        child: Column(
          children: [
            // ─── Top Bar ─────────────────────────────────
            Padding(
              padding: EdgeInsets.symmetric(
                horizontal: size.width * 0.04,
                vertical: 8,
              ),
              child: Row(
                children: [
                  _BackButton(onTap: () => context.go('/login')),
                  const Expanded(
                    child: Text(
                      'Create Account',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: AppTheme.darkTextPrimary,
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  const SizedBox(width: 38),
                ],
              ),
            ),

            // ─── Scrollable Content ──────────────────────
            Expanded(
              child: SingleChildScrollView(
                padding: EdgeInsets.symmetric(
                  horizontal: size.width * 0.065,
                  vertical: 8,
                ),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 440),
                  child: Form(
                    key: _formKey,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _buildLogo(),
                        const SizedBox(height: 6),
                        const Center(
                          child: Text(
                            'Join ForsaDrive today',
                            style: TextStyle(
                              color: AppTheme.darkTextSecondary,
                              fontSize: 14,
                            ),
                          ),
                        ),
                        const SizedBox(height: 28),

                        // ─── Username ────────────────────────
                        const _FieldLabel('Username'),
                        const SizedBox(height: 6),
                        TextFormField(
                          controller: _usernameCtrl,
                          style: _inputStyle,
                          decoration: _inputDeco(
                            icon: Icons.alternate_email,
                            hint: 'Choose a username',
                          ),
                          validator: (v) => v == null || v.trim().length < 3
                              ? 'At least 3 characters'
                              : null,
                        ),
                        const SizedBox(height: 14),

                        // ─── Email ───────────────────────────
                        const _FieldLabel('Email address'),
                        const SizedBox(height: 6),
                        TextFormField(
                          controller: _emailCtrl,
                          keyboardType: TextInputType.emailAddress,
                          style: _inputStyle,
                          onChanged: _onEmailChanged,
                          decoration: _inputDeco(
                            icon: Icons.email_outlined,
                            hint: 'your@email.com',
                          ),
                          validator: (v) => v == null || !v.contains('@')
                              ? 'Valid email required'
                              : null,
                        ),
                        if (_maybeStudent) ...[
                          const SizedBox(height: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                            decoration: BoxDecoration(
                              color: const Color(0xFF134E1A),
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: const Color(0xFF22c55e).withOpacity(0.4)),
                            ),
                            child: const Row(
                              children: [
                                Icon(Icons.school_outlined, color: Color(0xFF22c55e), size: 16),
                                SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    _studentDomainHint,
                                    style: TextStyle(color: Color(0xFF86efac), fontSize: 12),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                        const SizedBox(height: 14),

                        // ─── Phone ───────────────────────────
                        const _FieldLabel('Phone Number (optional)'),
                        const SizedBox(height: 6),
                        TextFormField(
                          controller: _phoneCtrl,
                          keyboardType: TextInputType.phone,
                          style: _inputStyle,
                          decoration: _inputDeco(
                            icon: Icons.phone_outlined,
                            hint: '+216 XX XXX XXX',
                          ),
                        ),
                        const SizedBox(height: 14),

                        // ─── Gender ──────────────────────────
                        const _FieldLabel('Gender'),
                        const SizedBox(height: 6),
                        DropdownButtonFormField<String>(
                          value: _gender,
                          dropdownColor: AppTheme.darkSurface,
                          style: _inputStyle,
                          icon: const Icon(Icons.keyboard_arrow_down, color: AppTheme.darkTextMuted),
                          decoration: _inputDeco(icon: Icons.wc_outlined, hint: 'Select gender'),
                          items: const [
                            DropdownMenuItem(value: 'male',   child: Text('Male')),
                            DropdownMenuItem(value: 'female', child: Text('Female')),
                            DropdownMenuItem(value: 'other',  child: Text('Prefer not to say')),
                          ],
                          onChanged: (v) => setState(() => _gender = v),
                          validator: (v) => v == null ? 'Please select a gender' : null,
                        ),
                        const SizedBox(height: 14),

                        // ─── Date of Birth ───────────────────
                        const _FieldLabel('Date of Birth'),
                        const SizedBox(height: 6),
                        GestureDetector(
                          onTap: _pickDob,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                            decoration: BoxDecoration(
                              color: AppTheme.darkSurface,
                              borderRadius: BorderRadius.circular(AppTheme.radiusMd),
                              border: Border.all(color: AppTheme.darkBorder),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.cake_outlined, color: AppTheme.darkTextMuted, size: 20),
                                const SizedBox(width: 12),
                                Text(
                                  _dob != null
                                      ? '${_dob!.day.toString().padLeft(2, '0')}/${_dob!.month.toString().padLeft(2, '0')}/${_dob!.year}'
                                      : 'Select date of birth',
                                  style: TextStyle(
                                    color: _dob != null ? AppTheme.darkTextPrimary : AppTheme.darkTextMuted,
                                    fontSize: 15,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                        const SizedBox(height: 14),

                        // ─── Governorate ─────────────────────
                        const _FieldLabel('Governorate'),
                        const SizedBox(height: 6),
                        DropdownButtonFormField<String>(
                          value: _governorate,
                          dropdownColor: AppTheme.darkSurface,
                          style: _inputStyle,
                          icon: const Icon(Icons.keyboard_arrow_down, color: AppTheme.darkTextMuted),
                          decoration: _inputDeco(icon: Icons.map_outlined, hint: 'Select governorate'),
                          items: AppConstants.tunisianGovernorates
                              .map((g) => DropdownMenuItem(value: g, child: Text(g)))
                              .toList(),
                          onChanged: (v) => setState(() => _governorate = v),
                        ),
                        const SizedBox(height: 14),

                        // ─── Password ────────────────────────
                        const _FieldLabel('Password'),
                        const SizedBox(height: 6),
                        TextFormField(
                          controller: _passCtrl,
                          obscureText: _obscurePass,
                          style: _inputStyle,
                          decoration: _inputDeco(icon: Icons.lock_outlined, hint: '••••••••').copyWith(
                            suffixIcon: IconButton(
                              icon: Icon(
                                _obscurePass ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                                color: AppTheme.darkTextMuted,
                                size: 20,
                              ),
                              onPressed: () => setState(() => _obscurePass = !_obscurePass),
                            ),
                          ),
                          validator: (v) => v == null || v.length < 6 ? 'At least 6 characters' : null,
                        ),
                        const SizedBox(height: 14),

                        // ─── Confirm Password ────────────────
                        const _FieldLabel('Confirm Password'),
                        const SizedBox(height: 6),
                        TextFormField(
                          controller: _confirmCtrl,
                          obscureText: _obscureConfirm,
                          style: _inputStyle,
                          decoration: _inputDeco(icon: Icons.lock_outlined, hint: '••••••••').copyWith(
                            suffixIcon: IconButton(
                              icon: Icon(
                                _obscureConfirm ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                                color: AppTheme.darkTextMuted,
                                size: 20,
                              ),
                              onPressed: () => setState(() => _obscureConfirm = !_obscureConfirm),
                            ),
                          ),
                          validator: (v) => v != _passCtrl.text ? 'Passwords do not match' : null,
                        ),
                        const SizedBox(height: 16),

                        // ─── Driver Toggle ───────────────────
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                          decoration: BoxDecoration(
                            color: AppTheme.darkSurface,
                            borderRadius: BorderRadius.circular(AppTheme.radiusMd),
                            border: Border.all(color: AppTheme.darkBorder),
                          ),
                          child: Row(
                            children: [
                              Icon(
                                Icons.directions_car_outlined,
                                color: _isDriver ? AppTheme.accent : AppTheme.darkTextMuted,
                                size: 20,
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Text(
                                  'I want to offer rides as a driver',
                                  style: TextStyle(
                                    color: _isDriver ? AppTheme.darkTextPrimary : AppTheme.darkTextSecondary,
                                    fontSize: 14,
                                  ),
                                ),
                              ),
                              Switch(
                                value: _isDriver,
                                activeColor: AppTheme.accent,
                                activeTrackColor: AppTheme.accent.withOpacity(0.3),
                                inactiveThumbColor: AppTheme.textMuted,
                                inactiveTrackColor: AppTheme.darkBorder,
                                onChanged: (v) => setState(() => _isDriver = v),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 24),

                        // ─── Sign Up Button ──────────────────
                        SizedBox(
                          height: 54,
                          child: ElevatedButton(
                            onPressed: _loading ? null : _submit,
                            style: ElevatedButton.styleFrom(
                              backgroundColor: AppTheme.accent,
                              foregroundColor: AppTheme.primaryDark,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(14),
                              ),
                              elevation: 0,
                              textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                            ),
                            child: _loading
                                ? const SizedBox(
                                    width: 22,
                                    height: 22,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2.5,
                                      color: AppTheme.primaryDark,
                                    ),
                                  )
                                : const Text('Create Account'),
                          ),
                        ),
                        const SizedBox(height: 20),

                        // ─── Sign In Link ────────────────────
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Text(
                              'Already have an account? ',
                              style: TextStyle(color: AppTheme.darkTextSecondary, fontSize: 14),
                            ),
                            GestureDetector(
                              onTap: () => context.go('/login'),
                              child: const Text(
                                'Sign In',
                                style: TextStyle(
                                  color: AppTheme.accent,
                                  fontSize: 14,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 32),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  static const _inputStyle = TextStyle(color: AppTheme.darkTextPrimary, fontSize: 15);

  InputDecoration _inputDeco({required IconData icon, required String hint}) {
    return AppTheme.inputDecoration(label: '', prefixIcon: icon, hint: hint).copyWith(
      labelText: null,
      floatingLabelBehavior: FloatingLabelBehavior.never,
    );
  }

  Widget _buildLogo() {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            gradient: const LinearGradient(colors: [AppTheme.accent, AppTheme.accentDark]),
            borderRadius: BorderRadius.circular(10),
            boxShadow: [BoxShadow(color: AppTheme.accent.withOpacity(0.3), blurRadius: 16, offset: const Offset(0, 6))],
          ),
          child: const Icon(Icons.flash_on, color: AppTheme.primaryDark, size: 22),
        ),
        const SizedBox(width: 8),
        RichText(
          text: const TextSpan(
            children: [
              TextSpan(
                text: 'Forsa',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppTheme.darkTextPrimary, letterSpacing: -0.5),
              ),
              TextSpan(
                text: 'Drive',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppTheme.accent, letterSpacing: -0.5),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

// ─── Reusable Widgets ────────────────────────────────────────────────────────

class _FieldLabel extends StatelessWidget {
  final String text;
  const _FieldLabel(this.text);
  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: const TextStyle(fontSize: 13, color: AppTheme.darkTextSecondary, fontWeight: FontWeight.w500),
    );
  }
}

class _BackButton extends StatelessWidget {
  final VoidCallback onTap;
  const _BackButton({required this.onTap});
  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 38,
        height: 38,
        decoration: BoxDecoration(
          color: AppTheme.darkSurface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppTheme.darkBorder),
        ),
        child: const Icon(Icons.arrow_back_ios_new, color: AppTheme.darkTextPrimary, size: 16),
      ),
    );
  }
}
