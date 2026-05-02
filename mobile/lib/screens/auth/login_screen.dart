import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import '../../l10n/app_localizations.dart';
import '../../providers/auth_provider.dart';
import '../../utils/app_theme.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _obscure = true;
  bool _loading = false;

  @override
  void dispose() {
    _emailCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _loading = true);
    final auth = context.read<AuthProvider>();
    final ok = await auth.login(_emailCtrl.text.trim(), _passCtrl.text);
    if (!mounted) return;
    setState(() => _loading = false);
    if (ok) {
      context.go('/home');
    } else {
      final l10n = AppLocalizations.of(context);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(auth.error ?? l10n.error),
          backgroundColor: AppTheme.danger,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: AppTheme.primary,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: EdgeInsets.symmetric(
              horizontal: size.width * 0.065,
              vertical: 16,
            ),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // ─── Logo ────────────────────────────────────
                    SizedBox(height: size.height * 0.03),
                    _buildLogo(),
                    const SizedBox(height: 8),
                    Center(
                      child: Text(
                        l10n.appTagline,
                        style: const TextStyle(
                          color: AppTheme.darkTextSecondary,
                          fontSize: 14,
                        ),
                        textAlign: TextAlign.center,
                      ),
                    ),

                    // ─── Welcome Text ─────────────────────────────
                    SizedBox(height: size.height * 0.04),
                    Center(
                      child: Text(
                        l10n.welcomeBack,
                        style: const TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w700,
                          color: AppTheme.darkTextPrimary,
                          letterSpacing: -0.5,
                        ),
                      ),
                    ),
                    const SizedBox(height: 6),
                    Center(
                      child: Text(
                        l10n.signInToContinue,
                        style: const TextStyle(
                          color: AppTheme.darkTextSecondary,
                          fontSize: 14,
                        ),
                        textAlign: TextAlign.center,
                      ),
                    ),

                    // ─── Form Fields ──────────────────────────────
                    SizedBox(height: size.height * 0.035),

                    // Email
                    Text(
                      l10n.emailAddress,
                      style: const TextStyle(
                        fontSize: 13,
                        color: AppTheme.darkTextSecondary,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    const SizedBox(height: 8),
                    TextFormField(
                      controller: _emailCtrl,
                      keyboardType: TextInputType.emailAddress,
                      style: const TextStyle(
                        color: AppTheme.darkTextPrimary,
                        fontSize: 15,
                      ),
                      decoration: AppTheme.inputDecoration(
                        label: '',
                        prefixIcon: Icons.email_outlined,
                        hint: 'your@email.com',
                      ).copyWith(
                        labelText: null,
                        floatingLabelBehavior: FloatingLabelBehavior.never,
                      ),
                      validator: (v) => v == null || !v.contains('@')
                          ? l10n.validEmailRequired
                          : null,
                    ),
                    const SizedBox(height: 14),

                    // Password
                    Text(
                      l10n.password,
                      style: const TextStyle(
                        fontSize: 13,
                        color: AppTheme.darkTextSecondary,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    const SizedBox(height: 8),
                    TextFormField(
                      controller: _passCtrl,
                      obscureText: _obscure,
                      style: const TextStyle(
                        color: AppTheme.darkTextPrimary,
                        fontSize: 15,
                      ),
                      decoration: AppTheme.inputDecoration(
                        label: '',
                        prefixIcon: Icons.lock_outlined,
                        hint: '••••••••',
                        suffixIcon: IconButton(
                          icon: Icon(
                            _obscure
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                            color: AppTheme.darkTextMuted,
                            size: 20,
                          ),
                          onPressed: () =>
                              setState(() => _obscure = !_obscure),
                        ),
                      ).copyWith(
                        labelText: null,
                        floatingLabelBehavior: FloatingLabelBehavior.never,
                      ),
                      validator: (v) =>
                          v == null || v.isEmpty ? l10n.passwordRequired : null,
                    ),
                    const SizedBox(height: 8),

                    // Forgot Password
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: TextButton(
                        onPressed: () {
                          // TODO: navigate to forgot password
                        },
                        style: TextButton.styleFrom(
                          padding: EdgeInsets.zero,
                          minimumSize: Size.zero,
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ),
                        child: Text(
                          l10n.forgotPassword,
                          style: const TextStyle(
                            color: AppTheme.accent,
                            fontSize: 13,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 20),

                    // ─── Sign In Button ───────────────────────────
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
                          textStyle: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w700,
                          ),
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
                            : Text(l10n.signIn),
                      ),
                    ),

                    // ─── Divider ──────────────────────────────────
                    const SizedBox(height: 24),
                    Row(
                      children: [
                        const Expanded(
                            child: Divider(color: AppTheme.darkBorder)),
                        Padding(
                          padding:
                              const EdgeInsets.symmetric(horizontal: 16),
                          child: Text(
                            l10n.orContinueWith,
                            style: const TextStyle(
                              color: AppTheme.darkTextMuted,
                              fontSize: 12,
                            ),
                          ),
                        ),
                        const Expanded(
                            child: Divider(color: AppTheme.darkBorder)),
                      ],
                    ),
                    const SizedBox(height: 20),

                    // ─── Social Buttons ───────────────────────────
                    const Row(
                      children: [
                        _SocialButton(label: 'Google', icon: 'G'),
                        SizedBox(width: 12),
                        _SocialButton(label: 'Facebook', icon: 'f'),
                        SizedBox(width: 12),
                        _SocialButton(label: 'Apple', icon: '🍎'),
                      ],
                    ),

                    // ─── Sign Up Link ─────────────────────────────
                    SizedBox(height: size.height * 0.06),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          '${l10n.dontHaveAccount} ',
                          style: const TextStyle(
                            color: AppTheme.darkTextSecondary,
                            fontSize: 14,
                          ),
                        ),
                        GestureDetector(
                          onTap: () => context.go('/signup'),
                          child: Text(
                            l10n.signUpFree,
                            style: const TextStyle(
                              color: AppTheme.accent,
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildLogo() {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [AppTheme.accent, AppTheme.accentDark],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(12),
            boxShadow: [
              BoxShadow(
                color: AppTheme.accent.withOpacity(0.3),
                blurRadius: 20,
                offset: const Offset(0, 8),
              ),
            ],
          ),
          child: const Icon(
            Icons.flash_on,
            color: AppTheme.primaryDark,
            size: 24,
          ),
        ),
        const SizedBox(width: 10),
        RichText(
          text: const TextSpan(
            children: [
              TextSpan(
                text: 'Forsa',
                style: TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w800,
                  color: AppTheme.darkTextPrimary,
                  letterSpacing: -0.5,
                ),
              ),
              TextSpan(
                text: 'Drive',
                style: TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w800,
                  color: AppTheme.accent,
                  letterSpacing: -0.5,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

// ─── Social Login Button ──────────────────────────────────────────────────────

class _SocialButton extends StatelessWidget {
  final String label;
  final String icon;
  const _SocialButton({required this.label, required this.icon});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        height: 50,
        decoration: BoxDecoration(
          color: AppTheme.darkSurface,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppTheme.darkBorder),
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: () {
              // TODO: implement social login
            },
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  icon,
                  style: const TextStyle(
                    fontSize: 18,
                    color: AppTheme.darkTextPrimary,
                  ),
                ),
                const SizedBox(width: 6),
                Text(
                  label,
                  style: const TextStyle(
                    fontSize: 12,
                    color: AppTheme.darkTextSecondary,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}