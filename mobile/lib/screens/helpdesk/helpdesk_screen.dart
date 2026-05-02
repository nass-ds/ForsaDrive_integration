import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../utils/app_theme.dart';
import '../_shared/access_denied_screen.dart';

class HelpdeskScreen extends StatefulWidget {
  const HelpdeskScreen({super.key});
  @override
  State<HelpdeskScreen> createState() => _HelpdeskScreenState();
}

class _HelpdeskScreenState extends State<HelpdeskScreen> {
  List<dynamic> _conversations = [];
  Map<String, dynamic>? _selectedConv;
  List<dynamic> _messages = [];
  bool _isEscalated = false;
  final _msgCtrl = TextEditingController();
  bool _loadingConvs = false;
  bool _loadingMsgs = false;
  bool _sending = false;
  Timer? _pollTimer;

  @override
  void initState() {
    super.initState();
    _loadConversations();
  }

  @override
  void dispose() {
    _msgCtrl.dispose();
    _pollTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadConversations() async {
    if (!mounted) return;
    setState(() => _loadingConvs = true);
    try {
      final res = await context.read<ApiService>().get('/helpdesk/');
      if (mounted) setState(() => _conversations = res['conversations'] as List<dynamic>? ?? []);
    } catch (e) {
      if (mounted) _showError(e.toString());
    } finally {
      if (mounted) setState(() => _loadingConvs = false);
    }
  }

  Future<void> _openConv(Map<String, dynamic> conv) async {
    _pollTimer?.cancel();
    setState(() { _selectedConv = conv; _loadingMsgs = true; _messages = []; _isEscalated = false; });
    try {
      final res = await context.read<ApiService>().get('/helpdesk/messages',
          params: {'conversation_id': conv['id'].toString()});
      if (mounted) setState(() {
        _messages = res['messages'] as List<dynamic>? ?? [];
        _isEscalated = res['is_escalated'] == true;
      });
    } catch (e) {
      if (mounted) _showError(e.toString());
    } finally {
      if (mounted) setState(() => _loadingMsgs = false);
    }
    // Poll for bot / agent replies every 3 seconds
    _pollTimer = Timer.periodic(const Duration(seconds: 3), (_) => _pollMessages());
  }

  Future<void> _pollMessages() async {
    if (_selectedConv == null || !mounted) return;
    try {
      final res = await context.read<ApiService>().get('/helpdesk/messages',
          params: {'conversation_id': _selectedConv!['id'].toString()});
      if (mounted) {
        final msgs = res['messages'] as List<dynamic>? ?? [];
        final escalated = res['is_escalated'] == true;
        if (msgs.length != _messages.length || escalated != _isEscalated) {
          setState(() { _messages = msgs; _isEscalated = escalated; });
        }
      }
    } catch (_) {}
  }

  Future<void> _sendMessage() async {
    if (_selectedConv == null || _msgCtrl.text.trim().isEmpty || _sending) return;
    final text = _msgCtrl.text.trim();
    _msgCtrl.clear();
    setState(() => _sending = true);
    try {
      await context.read<ApiService>().post('/helpdesk/reply', {
        'conversation_id': _selectedConv!['id'],
        'message': text,
      });
      await _pollMessages();
    } catch (e) {
      if (mounted) _showError(e.toString());
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _escalate() async {
    if (_selectedConv == null) return;
    try {
      await context.read<ApiService>().post('/helpdesk/escalate', {
        'conversation_id': _selectedConv!['id'],
      });
      await _pollMessages();
    } catch (e) {
      if (mounted) _showError(e.toString());
    }
  }

  void _newTicket() {
    final subjectCtrl = TextEditingController();
    final msgCtrl = TextEditingController();
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('New Support Ticket'),
        content: SizedBox(
          width: 400,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: subjectCtrl, decoration: const InputDecoration(labelText: 'Subject')),
            const SizedBox(height: 12),
            TextField(controller: msgCtrl, maxLines: 4,
                decoration: const InputDecoration(labelText: 'Describe your issue...')),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(onPressed: () async {
            if (subjectCtrl.text.isEmpty || msgCtrl.text.isEmpty) return;
            Navigator.pop(ctx);
            try {
              final res = await context.read<ApiService>().post('/helpdesk/new', {
                'subject': subjectCtrl.text,
                'message': msgCtrl.text,
              });
              await _loadConversations();
              // Auto-open the new conversation
              final convId = res['conversation_id'];
              if (convId != null && mounted) {
                final conv = _conversations.firstWhere(
                    (c) => c['id'] == convId, orElse: () => null);
                if (conv != null) _openConv(conv as Map<String, dynamic>);
              }
            } catch (e) {
              if (mounted) _showError(e.toString());
            }
          }, child: const Text('Submit')),
        ],
      ),
    );
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(msg), backgroundColor: AppTheme.danger));
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    if (user == null || (!user.isAdmin && !user.isHelpdeskAgent)) {
      return const AccessDeniedScreen(reason: 'HelpDesk agent access required');
    }

    final isWide = MediaQuery.of(context).size.width > 700;

    return Scaffold(
      appBar: AppBar(
        title: Text(_selectedConv != null && !isWide
            ? (_selectedConv!['subject'] as String? ?? 'Ticket')
            : 'Support Center'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          if (_selectedConv == null || isWide)
            TextButton.icon(
              onPressed: _newTicket,
              icon: const Icon(Icons.add, color: AppTheme.accent),
              label: const Text('New Ticket', style: TextStyle(color: AppTheme.accent)),
            ),
          if (_selectedConv != null && !isWide)
            IconButton(
              icon: const Icon(Icons.arrow_back),
              onPressed: () {
                _pollTimer?.cancel();
                setState(() => _selectedConv = null);
              },
            ),
        ],
      ),
      body: isWide
          ? Row(children: [
              SizedBox(
                width: 300,
                child: _TicketList(
                  convs: _conversations,
                  selected: _selectedConv,
                  loading: _loadingConvs,
                  onSelect: _openConv,
                  onNew: _newTicket,
                ),
              ),
              if (_selectedConv != null)
                Expanded(
                  child: _ChatView(
                    key: ValueKey(_selectedConv!['id']),
                    messages: _messages,
                    ctrl: _msgCtrl,
                    onSend: _sendMessage,
                    onEscalate: _escalate,
                    loading: _loadingMsgs,
                    sending: _sending,
                    conv: _selectedConv!,
                    isEscalated: _isEscalated,
                  ),
                ),
              if (_selectedConv == null) Expanded(child: _EmptyState(onNew: _newTicket)),
            ])
          : _selectedConv == null
              ? _TicketList(
                  convs: _conversations,
                  selected: null,
                  loading: _loadingConvs,
                  onSelect: _openConv,
                  onNew: _newTicket,
                )
              : _ChatView(
                  key: ValueKey(_selectedConv!['id']),
                  messages: _messages,
                  ctrl: _msgCtrl,
                  onSend: _sendMessage,
                  onEscalate: _escalate,
                  loading: _loadingMsgs,
                  sending: _sending,
                  conv: _selectedConv!,
                  isEscalated: _isEscalated,
                ),
    );
  }
}

// ── Ticket list ───────────────────────────────────────────────────────────────

class _TicketList extends StatelessWidget {
  final List<dynamic> convs;
  final Map<String, dynamic>? selected;
  final bool loading;
  final void Function(Map<String, dynamic>) onSelect;
  final VoidCallback onNew;
  const _TicketList({
    required this.convs,
    required this.selected,
    required this.loading,
    required this.onSelect,
    required this.onNew,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(border: Border(right: BorderSide(color: AppTheme.border))),
      child: loading
          ? const Center(child: CircularProgressIndicator())
          : convs.isEmpty
              ? Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  const Icon(Icons.support_agent, size: 48, color: AppTheme.textSecondary),
                  const SizedBox(height: 8),
                  const Text('No tickets yet', style: TextStyle(color: AppTheme.textSecondary)),
                  const SizedBox(height: 16),
                  ElevatedButton(onPressed: onNew, child: const Text('Open a Ticket')),
                ]))
              : ListView.builder(
                  itemCount: convs.length,
                  itemBuilder: (ctx, i) {
                    final c = convs[i] as Map<String, dynamic>;
                    final isSelected = selected?['id'] == c['id'];
                    final status = c['status'] as String? ?? 'open';
                    final escalated = c['is_escalated'] == 1;
                    return ListTile(
                      selected: isSelected,
                      selectedTileColor: AppTheme.primary.withAlpha(20),
                      leading: Container(
                        width: 40, height: 40,
                        decoration: BoxDecoration(
                          color: status == 'open'
                              ? AppTheme.success.withAlpha(30)
                              : Colors.grey.withAlpha(30),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Icon(
                          escalated ? Icons.support_agent : Icons.smart_toy,
                          color: escalated ? AppTheme.success : Colors.teal,
                          size: 20,
                        ),
                      ),
                      title: Text(
                        c['subject'] as String? ?? 'Support Ticket',
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                        overflow: TextOverflow.ellipsis,
                      ),
                      subtitle: Text(
                        escalated ? 'HUMAN AGENT' : 'BOT',
                        style: TextStyle(
                          fontSize: 10,
                          color: escalated ? AppTheme.success : Colors.teal,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      onTap: () => onSelect(c),
                    );
                  },
                ),
    );
  }
}

// ── Chat view ─────────────────────────────────────────────────────────────────

class _ChatView extends StatefulWidget {
  final List<dynamic> messages;
  final TextEditingController ctrl;
  final VoidCallback onSend;
  final VoidCallback onEscalate;
  final bool loading;
  final bool sending;
  final Map<String, dynamic> conv;
  final bool isEscalated;

  const _ChatView({
    super.key,
    required this.messages,
    required this.ctrl,
    required this.onSend,
    required this.onEscalate,
    required this.loading,
    required this.sending,
    required this.conv,
    required this.isEscalated,
  });

  @override
  State<_ChatView> createState() => _ChatViewState();
}

class _ChatViewState extends State<_ChatView> {
  final _scroll = ScrollController();

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(_ChatView old) {
    super.didUpdateWidget(old);
    if (widget.messages.length != old.messages.length) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) {
          _scroll.animateTo(
            _scroll.position.maxScrollExtent,
            duration: const Duration(milliseconds: 250),
            curve: Curves.easeOut,
          );
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // Header
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: const BoxDecoration(
              color: AppTheme.bgLight,
              border: Border(bottom: BorderSide(color: AppTheme.border))),
          child: Row(children: [
            Icon(
              widget.isEscalated ? Icons.support_agent : Icons.smart_toy,
              color: widget.isEscalated ? AppTheme.success : Colors.teal,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(widget.conv['subject'] as String? ?? 'Support',
                    style: const TextStyle(fontWeight: FontWeight.w700)),
                Text(
                  widget.isEscalated ? 'Human agent • responding shortly' : 'ForsaDrive Bot • instant replies',
                  style: TextStyle(
                    fontSize: 11,
                    color: widget.isEscalated ? AppTheme.success : Colors.teal,
                  ),
                ),
              ]),
            ),
            if (!widget.isEscalated)
              TextButton.icon(
                onPressed: widget.onEscalate,
                icon: const Icon(Icons.person, size: 14),
                label: const Text('Talk to human', style: TextStyle(fontSize: 12)),
                style: TextButton.styleFrom(foregroundColor: AppTheme.textSecondary),
              ),
          ]),
        ),

        // Messages
        Expanded(
          child: widget.loading
              ? const Center(child: CircularProgressIndicator())
              : ListView.builder(
                  controller: _scroll,
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                  itemCount: widget.messages.length,
                  itemBuilder: (ctx, i) {
                    final m = widget.messages[i] as Map<String, dynamic>;
                    return _MessageBubble(message: m);
                  },
                ),
        ),

        // Escalation banner
        if (widget.isEscalated)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            color: AppTheme.success.withOpacity(0.08),
            child: const Row(children: [
              Icon(Icons.support_agent, size: 14, color: AppTheme.success),
              SizedBox(width: 6),
              Text(
                'Connected to human support — team will respond shortly',
                style: TextStyle(fontSize: 12, color: AppTheme.success),
              ),
            ]),
          ),

        // Input
        Container(
          padding: const EdgeInsets.all(12),
          decoration: const BoxDecoration(border: Border(top: BorderSide(color: AppTheme.border))),
          child: Row(children: [
            Expanded(
              child: TextField(
                controller: widget.ctrl,
                onSubmitted: (_) => widget.onSend(),
                decoration: InputDecoration(
                  hintText: 'Type your message...',
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(24), borderSide: BorderSide.none),
                  filled: true,
                  fillColor: Colors.grey[100],
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                ),
              ),
            ),
            const SizedBox(width: 8),
            widget.sending
                ? const SizedBox(
                    width: 44, height: 44,
                    child: Padding(
                      padding: EdgeInsets.all(10),
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ))
                : IconButton(
                    onPressed: widget.onSend,
                    icon: const Icon(Icons.send_rounded),
                    style: IconButton.styleFrom(
                      backgroundColor: AppTheme.primary,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.all(12),
                    ),
                  ),
          ]),
        ),
      ],
    );
  }
}

// ── Message bubble ────────────────────────────────────────────────────────────

class _MessageBubble extends StatelessWidget {
  final Map<String, dynamic> message;
  const _MessageBubble({required this.message});

  @override
  Widget build(BuildContext context) {
    final senderType = message['sender_type'] as String? ?? 'user';
    final isUser = senderType == 'user';
    final isBot = senderType == 'bot';
    final date = DateTime.tryParse(message['created_at'] as String? ?? '');

    return Align(
      alignment: isUser ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.72),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            if (!isUser) ...[
              CircleAvatar(
                radius: 14,
                backgroundColor: isBot ? Colors.teal.withOpacity(0.15) : AppTheme.success.withOpacity(0.15),
                child: Icon(
                  isBot ? Icons.smart_toy : Icons.support_agent,
                  size: 14,
                  color: isBot ? Colors.teal : AppTheme.success,
                ),
              ),
              const SizedBox(width: 6),
            ],
            Flexible(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                decoration: BoxDecoration(
                  color: isUser
                      ? AppTheme.primary
                      : isBot
                          ? Colors.teal.withOpacity(0.08)
                          : Colors.grey[100],
                  borderRadius: BorderRadius.only(
                    topLeft: const Radius.circular(16),
                    topRight: const Radius.circular(16),
                    bottomLeft: isUser ? const Radius.circular(16) : Radius.zero,
                    bottomRight: isUser ? Radius.zero : const Radius.circular(16),
                  ),
                  border: isBot
                      ? Border.all(color: Colors.teal.withOpacity(0.2))
                      : null,
                ),
                child: Column(
                  crossAxisAlignment: isUser ? CrossAxisAlignment.end : CrossAxisAlignment.start,
                  children: [
                    if (!isUser)
                      Text(
                        isBot ? '🤖 ForsaDrive Bot' : '👤 Support Agent',
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: FontWeight.w700,
                          color: isBot ? Colors.teal : AppTheme.success,
                        ),
                      ),
                    if (!isUser) const SizedBox(height: 4),
                    Text(
                      message['body'] as String? ?? '',
                      style: TextStyle(
                        color: isUser ? Colors.white : AppTheme.textPrimary,
                        fontSize: 14,
                        height: 1.4,
                      ),
                    ),
                    if (date != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        DateFormat('h:mm a').format(date),
                        style: TextStyle(
                          color: isUser ? Colors.white60 : AppTheme.textSecondary,
                          fontSize: 10,
                        ),
                      ),
                    ],
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

// ── Empty state ───────────────────────────────────────────────────────────────

class _EmptyState extends StatelessWidget {
  final VoidCallback onNew;
  const _EmptyState({required this.onNew});

  @override
  Widget build(BuildContext context) => Center(
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          const Icon(Icons.smart_toy, size: 72, color: Colors.teal),
          const SizedBox(height: 16),
          const Text('AI-Powered Support', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: AppTheme.primary)),
          const SizedBox(height: 8),
          const Text('Get instant answers from our bot,', style: TextStyle(color: AppTheme.textSecondary)),
          const Text('or escalate to a human agent.', style: TextStyle(color: AppTheme.textSecondary)),
          const SizedBox(height: 20),
          ElevatedButton.icon(onPressed: onNew, icon: const Icon(Icons.add), label: const Text('Open New Ticket')),
        ]),
      );
}