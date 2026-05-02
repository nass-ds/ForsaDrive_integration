import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../providers/auth_provider.dart';
import '../../services/message_service.dart';
import '../../models/message_model.dart';
import '../../utils/app_theme.dart';
import '../../config/api_config.dart';

class MessagesScreen extends StatefulWidget {
  final int? initialUserId;
  final String? initialUserName;
  const MessagesScreen({super.key, this.initialUserId, this.initialUserName});
  @override
  State<MessagesScreen> createState() => _MessagesScreenState();
}

class _MessagesScreenState extends State<MessagesScreen> {
  List<ConversationModel> _conversations = [];
  ConversationModel? _selected;
  List<MessageModel> _messages = [];
  final _msgCtrl = TextEditingController();
  bool _loadingConvs = false;
  bool _loadingMsgs = false;
  bool _sending = false;
  Timer? _msgPollTimer;
  Timer? _convPollTimer;

  @override
  void initState() {
    super.initState();
    _loadConversations(thenOpenUserId: widget.initialUserId, thenOpenUserName: widget.initialUserName);
    // Refresh conversation list (unread counts) in background
    _convPollTimer = Timer.periodic(const Duration(seconds: 15), (_) => _refreshConversations());
  }

  @override
  void dispose() {
    _msgCtrl.dispose();
    _msgPollTimer?.cancel();
    _convPollTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadConversations({int? thenOpenUserId, String? thenOpenUserName}) async {
    if (!mounted) return;
    setState(() => _loadingConvs = true);
    try {
      final list = await context.read<MessageService>().getConversations();
      if (mounted) setState(() => _conversations = list);
      // Auto-open conversation with a specific user (e.g. from notification tap)
      if (thenOpenUserId != null && mounted) {
        final existing = list.where((c) => c.otherId == thenOpenUserId).firstOrNull;
        if (existing != null) {
          _openConversation(existing);
        } else {
          // No conversation yet — send a starter message to create it, then open
          await context.read<MessageService>().sendMessage(
                otherUserId: thenOpenUserId,
                message: 'Hi ${thenOpenUserName ?? ''}! 👋',
              );
          final updated = await context.read<MessageService>().getConversations();
          if (mounted) setState(() => _conversations = updated);
          final created = updated.where((c) => c.otherId == thenOpenUserId).firstOrNull;
          if (created != null && mounted) _openConversation(created);
        }
      }
    } catch (e) {
      if (mounted) _showError(e.toString());
    } finally {
      if (mounted) setState(() => _loadingConvs = false);
    }
  }

  Future<void> _refreshConversations() async {
    if (!mounted) return;
    try {
      final list = await context.read<MessageService>().getConversations();
      if (mounted) setState(() => _conversations = list);
    } catch (_) {}
  }

  Future<void> _openConversation(ConversationModel conv) async {
    _msgPollTimer?.cancel();
    setState(() {
      _selected = conv;
      _loadingMsgs = true;
      _messages = [];
    });
    try {
      final msgs = await context.read<MessageService>().getMessages(conv.id);
      if (mounted) setState(() => _messages = msgs);
    } catch (e) {
      if (mounted) _showError(e.toString());
    } finally {
      if (mounted) setState(() => _loadingMsgs = false);
    }
    // Poll for new messages every 3 seconds while conversation is open
    _msgPollTimer = Timer.periodic(const Duration(seconds: 3), (_) => _pollMessages());
  }

  Future<void> _pollMessages() async {
    if (_selected == null || !mounted) return;
    try {
      final msgs = await context.read<MessageService>().getMessages(_selected!.id);
      if (mounted && msgs.length != _messages.length) {
        setState(() => _messages = msgs);
      }
    } catch (_) {}
  }

  Future<void> _sendMessage() async {
    if (_selected == null || _msgCtrl.text.trim().isEmpty || _sending) return;
    final text = _msgCtrl.text.trim();
    _msgCtrl.clear();
    if (mounted) setState(() => _sending = true);
    try {
      await context.read<MessageService>().sendMessage(
            conversationId: _selected!.id,
            message: text,
          );
      // Immediate refresh after send
      await _pollMessages();
    } catch (e) {
      if (mounted) _showError(e.toString());
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: AppTheme.danger),
    );
  }

  @override
  Widget build(BuildContext context) {
    final userId = context.read<AuthProvider>().user!.id;
    final isWide = MediaQuery.of(context).size.width > 700;

    return Scaffold(
      appBar: AppBar(
        title: Text(_selected != null && !isWide ? _selected!.otherName : 'Messages'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        leading: _selected != null && !isWide
            ? IconButton(
                icon: const Icon(Icons.arrow_back),
                onPressed: () {
                  _msgPollTimer?.cancel();
                  setState(() => _selected = null);
                },
              )
            : null,
      ),
      body: isWide
          ? Row(children: [
              SizedBox(
                width: 300,
                child: _ConvList(
                  convs: _conversations,
                  selected: _selected,
                  loading: _loadingConvs,
                  onSelect: _openConversation,
                ),
              ),
              if (_selected != null)
                Expanded(
                  child: _ChatView(
                    key: ValueKey(_selected!.id),
                    conv: _selected!,
                    messages: _messages,
                    currentUserId: userId,
                    ctrl: _msgCtrl,
                    onSend: _sendMessage,
                    loading: _loadingMsgs,
                    sending: _sending,
                  ),
                ),
            ])
          : _selected == null
              ? _ConvList(
                  convs: _conversations,
                  selected: null,
                  loading: _loadingConvs,
                  onSelect: _openConversation,
                )
              : _ChatView(
                  key: ValueKey(_selected!.id),
                  conv: _selected!,
                  messages: _messages,
                  currentUserId: userId,
                  ctrl: _msgCtrl,
                  onSend: _sendMessage,
                  loading: _loadingMsgs,
                  sending: _sending,
                ),
    );
  }
}

// ── Conversation list ─────────────────────────────────────────────────────────

class _ConvList extends StatelessWidget {
  final List<ConversationModel> convs;
  final ConversationModel? selected;
  final bool loading;
  final void Function(ConversationModel) onSelect;
  const _ConvList({
    required this.convs,
    required this.selected,
    required this.loading,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    if (loading) return const Center(child: CircularProgressIndicator());
    if (convs.isEmpty) {
      return const Center(
        child: Text('No conversations yet', style: TextStyle(color: AppTheme.textSecondary)),
      );
    }
    return Container(
      decoration: const BoxDecoration(
        border: Border(right: BorderSide(color: AppTheme.border)),
      ),
      child: ListView.builder(
        itemCount: convs.length,
        itemBuilder: (ctx, i) {
          final c = convs[i];
          final isSelected = selected?.id == c.id;
          return ListTile(
            selected: isSelected,
            selectedTileColor: AppTheme.primary.withOpacity(0.08),
            leading: CircleAvatar(
              backgroundImage: NetworkImage(ApiConfig.profilePicture(c.otherPic)),
              onBackgroundImageError: (_, __) {},
            ),
            title: Text(c.otherName, style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text(
              c.lastMessage ?? '',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary),
            ),
            trailing: c.unread > 0
                ? Container(
                    width: 20,
                    height: 20,
                    decoration: const BoxDecoration(
                      color: AppTheme.primary,
                      shape: BoxShape.circle,
                    ),
                    child: Center(
                      child: Text(
                        '${c.unread}',
                        style: const TextStyle(
                            color: Colors.white, fontSize: 10, fontWeight: FontWeight.w700),
                      ),
                    ),
                  )
                : null,
            onTap: () => onSelect(c),
          );
        },
      ),
    );
  }
}

// ── Chat view ─────────────────────────────────────────────────────────────────

class _ChatView extends StatefulWidget {
  final ConversationModel conv;
  final List<MessageModel> messages;
  final int currentUserId;
  final TextEditingController ctrl;
  final VoidCallback onSend;
  final bool loading;
  final bool sending;

  const _ChatView({
    super.key,
    required this.conv,
    required this.messages,
    required this.currentUserId,
    required this.ctrl,
    required this.onSend,
    required this.loading,
    required this.sending,
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
    // Auto-scroll when new messages arrive
    if (widget.messages.length != old.messages.length) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _scrollToBottom());
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    WidgetsBinding.instance.addPostFrameCallback((_) => _scrollToBottom());
  }

  void _scrollToBottom() {
    if (_scroll.hasClients) {
      _scroll.animateTo(
        _scroll.position.maxScrollExtent,
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // Header
        Container(
          padding: const EdgeInsets.all(12),
          decoration: const BoxDecoration(
            border: Border(bottom: BorderSide(color: AppTheme.border)),
          ),
          child: Row(children: [
            CircleAvatar(
              backgroundImage: NetworkImage(ApiConfig.profilePicture(widget.conv.otherPic)),
              onBackgroundImageError: (_, __) {},
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(widget.conv.otherName,
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                Row(children: [
                  Container(
                    width: 8, height: 8,
                    margin: const EdgeInsets.only(right: 4),
                    decoration: const BoxDecoration(color: Colors.green, shape: BoxShape.circle),
                  ),
                  const Text('Online', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                ]),
              ]),
            ),
          ]),
        ),

        // Messages
        Expanded(
          child: widget.loading
              ? const Center(child: CircularProgressIndicator())
              : widget.messages.isEmpty
                  ? const Center(
                      child: Text('No messages yet. Say hello!',
                          style: TextStyle(color: AppTheme.textSecondary)))
                  : ListView.builder(
                      controller: _scroll,
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                      itemCount: widget.messages.length,
                      itemBuilder: (ctx, i) {
                        final m = widget.messages[i];
                        final isMe = m.senderId == widget.currentUserId;
                        final showDate = i == 0 ||
                            !_sameDay(widget.messages[i - 1].createdAt, m.createdAt);
                        return Column(
                          children: [
                            if (showDate) _DateDivider(date: m.createdAt),
                            _MessageBubble(message: m, isMe: isMe),
                          ],
                        );
                      },
                    ),
        ),

        // Input bar
        Container(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
          decoration: const BoxDecoration(
            border: Border(top: BorderSide(color: AppTheme.border)),
          ),
          child: Row(children: [
            Expanded(
              child: TextField(
                controller: widget.ctrl,
                maxLines: null,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => widget.onSend(),
                decoration: InputDecoration(
                  hintText: 'Type a message...',
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(24),
                    borderSide: BorderSide.none,
                  ),
                  filled: true,
                  fillColor: Colors.grey[100],
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                ),
              ),
            ),
            const SizedBox(width: 8),
            widget.sending
                ? const SizedBox(
                    width: 44,
                    height: 44,
                    child: Padding(
                      padding: EdgeInsets.all(10),
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  )
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

  bool _sameDay(DateTime a, DateTime b) =>
      a.year == b.year && a.month == b.month && a.day == b.day;
}

// ── Message bubble ────────────────────────────────────────────────────────────

class _MessageBubble extends StatelessWidget {
  final MessageModel message;
  final bool isMe;
  const _MessageBubble({required this.message, required this.isMe});

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: isMe ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 6),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.65),
        decoration: BoxDecoration(
          color: isMe ? AppTheme.primary : Colors.grey[100],
          borderRadius: BorderRadius.only(
            topLeft: const Radius.circular(16),
            topRight: const Radius.circular(16),
            bottomLeft: isMe ? const Radius.circular(16) : Radius.zero,
            bottomRight: isMe ? Radius.zero : const Radius.circular(16),
          ),
        ),
        child: Column(
          crossAxisAlignment:
              isMe ? CrossAxisAlignment.end : CrossAxisAlignment.start,
          children: [
            Text(
              message.body,
              style: TextStyle(
                color: isMe ? Colors.white : AppTheme.textPrimary,
                fontSize: 14,
              ),
            ),
            const SizedBox(height: 4),
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  DateFormat('h:mm a').format(message.createdAt),
                  style: TextStyle(
                    color: isMe ? Colors.white60 : AppTheme.textSecondary,
                    fontSize: 10,
                  ),
                ),
                if (isMe) ...[
                  const SizedBox(width: 4),
                  Icon(
                    message.isRead ? Icons.done_all : Icons.done,
                    size: 12,
                    color: message.isRead ? Colors.lightBlueAccent : Colors.white60,
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ── Date divider ──────────────────────────────────────────────────────────────

class _DateDivider extends StatelessWidget {
  final DateTime date;
  const _DateDivider({required this.date});

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    String label;
    if (date.year == now.year && date.month == now.month && date.day == now.day) {
      label = 'Today';
    } else if (date.year == now.year &&
        date.month == now.month &&
        date.day == now.day - 1) {
      label = 'Yesterday';
    } else {
      label = DateFormat('MMM d, yyyy').format(date);
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Row(children: [
        const Expanded(child: Divider()),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Text(label,
              style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
        ),
        const Expanded(child: Divider()),
      ]),
    );
  }
}