import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../models/user_model.dart';
import '../../services/user_service.dart';
import '../../utils/app_theme.dart';

class StudentVerificationScreen extends StatefulWidget {
  const StudentVerificationScreen({super.key});

  @override
  State<StudentVerificationScreen> createState() =>
      _StudentVerificationScreenState();
}

class _StudentVerificationScreenState
    extends State<StudentVerificationScreen> {
  // ── Step 1: email ────────────────────────────────────────────────────────────
  int _step = 1; // 1 = email, 2 = OTP
  final _emailFormKey = GlobalKey<FormState>();
  final _emailCtrl = TextEditingController();

  String _pendingEmail = '';
  String _maskedEmail = '';

  // ── Step 2: OTP ──────────────────────────────────────────────────────────────
  final _otpControllers = List.generate(6, (_) => TextEditingController());
  final _otpFocusNodes = List.generate(6, (_) => FocusNode());
  int _resendCooldown = 0;
  Timer? _cooldownTimer;

  // ── Common ───────────────────────────────────────────────────────────────────
  bool _loading = false;
  String? _errorMsg;
  bool _success = false;

  static const _domainSuffixes = [
    'insat.rnu.tn', 'enit.rnu.tn', 'fst.rnu.tn', 'r-iset.tn',
    'iset.rnu.tn',  'esprit.tn',   'tek-up.tn',  'uvt.rnu.tn',
    'isetr.rnu.tn', 'isimm.rnu.tn','isg.rnu.tn', 'ihec.rnu.tn',
  ];

  @override
  void dispose() {
    _emailCtrl.dispose();
    for (final c in _otpControllers) c.dispose();
    for (final f in _otpFocusNodes) f.dispose();
    _cooldownTimer?.cancel();
    super.dispose();
  }

  bool _looksLikeUniEmail(String email) {
    final parts = email.split('@');
    if (parts.length != 2) return false;
    final domain = parts[1].toLowerCase();
    return _domainSuffixes.any((s) => domain == s || domain.endsWith('.$s'));
  }

  void _startResendCooldown() {
    _cooldownTimer?.cancel();
    setState(() => _resendCooldown = 60);
    _cooldownTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) { t.cancel(); return; }
      setState(() {
        if (_resendCooldown > 0) {
          _resendCooldown--;
        } else {
          t.cancel();
        }
      });
    });
  }

  String get _otpValue =>
      _otpControllers.map((c) => c.text).join();

  // ── Step 1: send OTP ─────────────────────────────────────────────────────────
  Future<void> _sendOtp() async {
    if (!_emailFormKey.currentState!.validate()) return;
    setState(() { _loading = true; _errorMsg = null; });
    try {
      final email = _emailCtrl.text.trim().toLowerCase();
      final res = await context.read<UserService>().sendStudentOtp(email);
      if (!mounted) return;
      setState(() {
        _pendingEmail = email;
        _maskedEmail = res['masked_email'] as String? ?? email;
        _step = 2;
      });
      _startResendCooldown();
    } catch (e) {
      if (mounted) setState(() => _errorMsg = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  // ── Step 2: verify OTP ───────────────────────────────────────────────────────
  Future<void> _verifyOtp() async {
    final code = _otpValue;
    if (code.length != 6) {
      setState(() => _errorMsg = 'Please enter all 6 digits.');
      return;
    }
    setState(() { _loading = true; _errorMsg = null; });
    try {
      final res = await context
          .read<UserService>()
          .verifyStudentOtp(_pendingEmail, code);
      if (!mounted) return;

      final auth = context.read<AuthProvider>();
      if (auth.user != null) {
        final updatedUser = res['user'] != null
            ? UserModel.fromJson(res['user'] as Map<String, dynamic>)
            : auth.user!.copyWith(isStudent: true);
        auth.updateUser(updatedUser);
      }
      setState(() => _success = true);
    } catch (e) {
      if (mounted) setState(() => _errorMsg = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _resendOtp() async {
    if (_resendCooldown > 0) return;
    setState(() { _loading = true; _errorMsg = null; });
    try {
      await context.read<UserService>().sendStudentOtp(_pendingEmail);
      if (!mounted) return;
      // Clear OTP boxes
      for (final c in _otpControllers) c.clear();
      _otpFocusNodes.first.requestFocus();
      _startResendCooldown();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('New OTP sent!')),
      );
    } catch (e) {
      if (mounted) setState(() => _errorMsg = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    final alreadyVerified = user?.isStudent ?? false;

    return Scaffold(
      backgroundColor: AppTheme.primary,
      appBar: AppBar(
        backgroundColor: AppTheme.primaryDark,
        foregroundColor: Colors.white,
        title: const Text('Student Verification'),
        elevation: 0,
        leading: _step == 2 && !_success
            ? IconButton(
                icon: const Icon(Icons.arrow_back),
                onPressed: () => setState(() {
                  _step = 1;
                  _errorMsg = null;
                  for (final c in _otpControllers) c.clear();
                  _cooldownTimer?.cancel();
                  _resendCooldown = 0;
                }),
              )
            : null,
      ),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: alreadyVerified || _success
                  ? _SuccessView(fresh: _success)
                  : _step == 1
                      ? _EmailStep(
                          formKey: _emailFormKey,
                          emailCtrl: _emailCtrl,
                          loading: _loading,
                          errorMsg: _errorMsg,
                          looksLikeUniEmail: _looksLikeUniEmail,
                          onSend: _sendOtp,
                        )
                      : _OtpStep(
                          maskedEmail: _maskedEmail,
                          controllers: _otpControllers,
                          focusNodes: _otpFocusNodes,
                          loading: _loading,
                          errorMsg: _errorMsg,
                          resendCooldown: _resendCooldown,
                          onVerify: _verifyOtp,
                          onResend: _resendOtp,
                        ),
            ),
          ),
        ),
      ),
    );
  }
}

// ── Step 1 — Email entry ──────────────────────────────────────────────────────

class _EmailStep extends StatelessWidget {
  final GlobalKey<FormState> formKey;
  final TextEditingController emailCtrl;
  final bool loading;
  final String? errorMsg;
  final bool Function(String) looksLikeUniEmail;
  final VoidCallback onSend;

  const _EmailStep({
    required this.formKey,
    required this.emailCtrl,
    required this.loading,
    required this.errorMsg,
    required this.looksLikeUniEmail,
    required this.onSend,
  });

  @override
  Widget build(BuildContext context) {
    return Form(
      key: formKey,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        // Icon
        Center(
          child: Container(
            width: 80, height: 80,
            decoration: BoxDecoration(
              color: AppTheme.accent.withOpacity(0.12),
              shape: BoxShape.circle,
              border: Border.all(color: AppTheme.accent.withOpacity(0.35), width: 2),
            ),
            child: const Icon(Icons.school_rounded, color: AppTheme.accent, size: 42),
          ),
        ),
        const SizedBox(height: 20),
        const Center(
          child: Text('Verify Student Status',
              style: TextStyle(color: Colors.white, fontSize: 24,
                  fontWeight: FontWeight.w800, letterSpacing: -0.4)),
        ),
        const SizedBox(height: 8),
        const Center(
          child: Text(
            'Enter your official university email.\nWe\'ll send a 6-digit OTP to confirm ownership.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppTheme.darkTextSecondary, fontSize: 14, height: 1.5),
          ),
        ),
        const SizedBox(height: 28),

        // Info banner
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFF112240),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: const Color(0xFF1E3A5F)),
          ),
          child: const Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(Icons.info_outline_rounded, color: AppTheme.info, size: 18),
            SizedBox(width: 10),
            Expanded(
              child: Text(
                'Accepted: @insat.rnu.tn, @esprit.tn, @enit.rnu.tn, '
                '@r-iset.tn, @tek-up.tn and more.',
                style: TextStyle(color: AppTheme.darkTextSecondary, fontSize: 12.5, height: 1.5),
              ),
            ),
          ]),
        ),
        const SizedBox(height: 20),

        // Email field
        TextFormField(
          controller: emailCtrl,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          style: const TextStyle(color: Colors.white),
          decoration: AppTheme.inputDecoration(
            label: 'University Email',
            prefixIcon: Icons.alternate_email_rounded,
            hint: 'e.g. name@insat.rnu.tn',
          ),
          validator: (v) {
            if (v == null || v.trim().isEmpty) return 'Email is required';
            if (!v.contains('@')) return 'Enter a valid email address';
            return null;
          },
        ),
        const SizedBox(height: 16),

        if (errorMsg != null) _ErrorBanner(msg: errorMsg!),

        SizedBox(
          width: double.infinity, height: 52,
          child: ElevatedButton.icon(
            onPressed: loading ? null : onSend,
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.accent,
              foregroundColor: AppTheme.primary,
              disabledBackgroundColor: AppTheme.accent.withOpacity(0.5),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              elevation: 0,
            ),
            icon: loading
                ? const SizedBox(width: 18, height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2.5, color: AppTheme.primary))
                : const Icon(Icons.send_rounded, size: 18),
            label: Text(loading ? 'Sending…' : 'Send OTP',
                style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
          ),
        ),
      ]),
    );
  }
}

// ── Step 2 — OTP entry ───────────────────────────────────────────────────────

class _OtpStep extends StatelessWidget {
  final String maskedEmail;
  final List<TextEditingController> controllers;
  final List<FocusNode> focusNodes;
  final bool loading;
  final String? errorMsg;
  final int resendCooldown;
  final VoidCallback onVerify;
  final VoidCallback onResend;

  const _OtpStep({
    required this.maskedEmail,
    required this.controllers,
    required this.focusNodes,
    required this.loading,
    required this.errorMsg,
    required this.resendCooldown,
    required this.onVerify,
    required this.onResend,
  });

  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      // Icon
      Center(
        child: Container(
          width: 80, height: 80,
          decoration: BoxDecoration(
            color: AppTheme.info.withOpacity(0.12),
            shape: BoxShape.circle,
            border: Border.all(color: AppTheme.info.withOpacity(0.4), width: 2),
          ),
          child: const Icon(Icons.mark_email_read_rounded, color: AppTheme.info, size: 42),
        ),
      ),
      const SizedBox(height: 20),
      const Center(
        child: Text('Check Your Email',
            style: TextStyle(color: Colors.white, fontSize: 24,
                fontWeight: FontWeight.w800, letterSpacing: -0.4)),
      ),
      const SizedBox(height: 8),
      Center(
        child: Text(
          'We sent a 6-digit code to\n$maskedEmail',
          textAlign: TextAlign.center,
          style: const TextStyle(color: AppTheme.darkTextSecondary, fontSize: 14, height: 1.5),
        ),
      ),
      const SizedBox(height: 32),

      // 6 OTP boxes
      Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: List.generate(6, (i) => _OtpBox(
          controller: controllers[i],
          focusNode: focusNodes[i],
          nextFocus: i < 5 ? focusNodes[i + 1] : null,
          prevFocus: i > 0 ? focusNodes[i - 1] : null,
          autofocus: i == 0,
        )),
      ),
      const SizedBox(height: 24),

      if (errorMsg != null) ...[
        _ErrorBanner(msg: errorMsg!),
        const SizedBox(height: 16),
      ],

      SizedBox(
        width: double.infinity, height: 52,
        child: ElevatedButton(
          onPressed: loading ? null : onVerify,
          style: ElevatedButton.styleFrom(
            backgroundColor: AppTheme.accent,
            foregroundColor: AppTheme.primary,
            disabledBackgroundColor: AppTheme.accent.withOpacity(0.5),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            elevation: 0,
          ),
          child: loading
              ? const SizedBox(width: 20, height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2.5, color: AppTheme.primary))
              : const Text('Verify',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
        ),
      ),
      const SizedBox(height: 20),

      // Resend row
      Center(
        child: resendCooldown > 0
            ? Text('Resend code in ${resendCooldown}s',
                style: const TextStyle(color: AppTheme.darkTextSecondary, fontSize: 13))
            : TextButton(
                onPressed: onResend,
                child: const Text('Resend code',
                    style: TextStyle(color: AppTheme.accent, fontWeight: FontWeight.w600)),
              ),
      ),
    ]);
  }
}

// ── Single OTP digit box ──────────────────────────────────────────────────────

class _OtpBox extends StatelessWidget {
  final TextEditingController controller;
  final FocusNode focusNode;
  final FocusNode? nextFocus;
  final FocusNode? prevFocus;
  final bool autofocus;

  const _OtpBox({
    required this.controller,
    required this.focusNode,
    this.nextFocus,
    this.prevFocus,
    this.autofocus = false,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 44, height: 54,
      margin: const EdgeInsets.symmetric(horizontal: 4),
      decoration: BoxDecoration(
        color: const Color(0xFF112240),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF1E3A5F), width: 1.5),
      ),
      child: TextField(
        controller: controller,
        focusNode: focusNode,
        autofocus: autofocus,
        textAlign: TextAlign.center,
        keyboardType: TextInputType.number,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        maxLength: 1,
        style: const TextStyle(
            color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800),
        decoration: const InputDecoration(
          counterText: '',
          border: InputBorder.none,
        ),
        onChanged: (v) {
          if (v.isNotEmpty) {
            nextFocus?.requestFocus();
          } else {
            prevFocus?.requestFocus();
          }
        },
      ),
    );
  }
}

// ── Shared error banner ───────────────────────────────────────────────────────

class _ErrorBanner extends StatelessWidget {
  final String msg;
  const _ErrorBanner({required this.msg});

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: 16),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppTheme.danger.withOpacity(0.1),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppTheme.danger.withOpacity(0.4)),
        ),
        child: Row(children: [
          const Icon(Icons.error_outline_rounded, color: AppTheme.danger, size: 18),
          const SizedBox(width: 10),
          Expanded(
              child: Text(msg,
                  style: const TextStyle(color: AppTheme.danger, fontSize: 13))),
        ]),
      );
}

// ── Success / Already-verified view ──────────────────────────────────────────

class _SuccessView extends StatelessWidget {
  final bool fresh;
  const _SuccessView({required this.fresh});

  @override
  Widget build(BuildContext context) {
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Container(
        width: 96, height: 96,
        decoration: BoxDecoration(
          color: AppTheme.success.withOpacity(0.15),
          shape: BoxShape.circle,
          border: Border.all(color: AppTheme.success, width: 2),
        ),
        child: const Icon(Icons.verified_rounded, color: AppTheme.success, size: 52),
      ),
      const SizedBox(height: 24),
      Text(
        fresh ? 'Verified!' : 'Already Verified',
        style: const TextStyle(color: Colors.white, fontSize: 26,
            fontWeight: FontWeight.w800, letterSpacing: -0.5),
      ),
      const SizedBox(height: 10),
      Text(
        fresh
            ? 'Your student status has been confirmed.\nYou now get 50% off on every ride.'
            : 'Your account already has verified student status.\nEnjoy 50% off on every ride.',
        textAlign: TextAlign.center,
        style: const TextStyle(
            color: AppTheme.darkTextSecondary, fontSize: 15, height: 1.5),
      ),
      const SizedBox(height: 32),
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
        decoration: BoxDecoration(
          color: AppTheme.success.withOpacity(0.1),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppTheme.success.withOpacity(0.3)),
        ),
        child: const Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.discount_rounded, color: AppTheme.success, size: 20),
          SizedBox(width: 10),
          Text('50% student discount active',
              style: TextStyle(color: AppTheme.success,
                  fontWeight: FontWeight.w700, fontSize: 14)),
        ]),
      ),
      const SizedBox(height: 24),
      SizedBox(
        width: double.infinity,
        child: OutlinedButton(
          onPressed: () => Navigator.of(context).pop(),
          style: OutlinedButton.styleFrom(
            foregroundColor: Colors.white,
            side: const BorderSide(color: Color(0xFF1E3A5F)),
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: const Text('Back to Settings'),
        ),
      ),
    ]);
  }
}
