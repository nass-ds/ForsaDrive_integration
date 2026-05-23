import 'dart:async';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:intl/intl.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../utils/app_theme.dart';
import '../../config/api_config.dart';
import '../../widgets/boost_tier_picker.dart';

class FeedScreen extends StatefulWidget {
  const FeedScreen({super.key});
  @override
  State<FeedScreen> createState() => _FeedScreenState();
}

class _FeedScreenState extends State<FeedScreen> with SingleTickerProviderStateMixin {
  List<dynamic> _posts = [];
  bool _loading = false;
  String _filter = 'all';
  Timer? _refreshTimer;

  static const _tabs = [
    ('all', 'All', Icons.dynamic_feed),
    ('driver_offer', 'Rides', Icons.directions_car),
    ('passenger_request', 'Requests', Icons.person_search),
    ('group_post', 'Groups', Icons.groups),
  ];

  @override
  void initState() {
    super.initState();
    _loadFeed();
    _refreshTimer = Timer.periodic(const Duration(seconds: 30), (_) => _loadFeed(silent: true));
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadFeed({bool silent = false}) async {
    if (!mounted) return;
    if (!silent) setState(() => _loading = true);
    try {
      final params = _filter == 'all' ? <String, String>{} : {'type': _filter};
      final res = await context.read<ApiService>().get('/feed/', params: params);
      if (mounted) setState(() => _posts = res['posts'] as List<dynamic>? ?? []);
    } catch (e) {
      if (mounted && !silent) _showError(e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(msg), backgroundColor: AppTheme.danger));
  }

  Future<void> _toggleLike(Map<String, dynamic> post) async {
    final postId = post['id'] as int;
    final wasLiked = post['is_liked'] == true;
    // Optimistic update
    setState(() {
      post['is_liked'] = !wasLiked;
      post['likes_count'] = (post['likes_count'] as int? ?? 0) + (wasLiked ? -1 : 1);
    });
    try {
      await context.read<ApiService>().post('/feed/like', {'post_id': postId});
    } catch (_) {
      // Revert on failure
      if (mounted) setState(() {
        post['is_liked'] = wasLiked;
        post['likes_count'] = (post['likes_count'] as int? ?? 0) + (wasLiked ? 1 : -1);
      });
    }
  }

  Future<void> _deletePost(int postId) async {
    try {
      await context.read<ApiService>().post('/feed/delete', {'post_id': postId});
      if (mounted) setState(() => _posts.removeWhere((p) => p['id'] == postId));
    } catch (e) {
      if (mounted) _showError(e.toString());
    }
  }

  Future<void> _boostPost(Map<String, dynamic> post) async {
    final tier = await showBoostTierPicker(
      context,
      title: 'Boost this Post',
      subtitle:
          'Pick how long your post stays pinned to the top of the feed. The fee is deducted from your wallet.',
    );
    if (tier == null || !mounted) return;
    try {
      await context
          .read<ApiService>()
          .post('/feed/boost', {'post_id': post['id'], 'tier': tier});
      if (mounted) {
        setState(() => post['is_boosted'] = 1);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Post boosted! Now appearing at the top of the feed.'),
            backgroundColor: Colors.amber,
          ),
        );
        _loadFeed(silent: true);
      }
    } catch (e) {
      if (mounted) _showError(e.toString());
    }
  }

  void _openCreateSheet() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _CreatePostSheet(
        onCreated: () {
          Navigator.pop(context);
          _loadFeed();
        },
      ),
    );
  }

  void _openComments(Map<String, dynamic> post) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _CommentsSheet(post: post),
    );
  }

  @override
  Widget build(BuildContext context) {
    final me = context.read<AuthProvider>().user;

    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        title: const Text('Community Feed'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(44),
          child: SizedBox(
            height: 44,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              children: _tabs.map((tab) {
                final selected = _filter == tab.$1;
                return Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ChoiceChip(
                    avatar: Icon(tab.$3, size: 14,
                        color: selected ? Colors.white : AppTheme.textSecondary),
                    label: Text(tab.$2),
                    selected: selected,
                    onSelected: (_) {
                      setState(() => _filter = tab.$1);
                      _loadFeed();
                    },
                    selectedColor: Colors.white.withOpacity(0.25),
                    labelStyle: TextStyle(
                      color: selected ? Colors.white : AppTheme.textSecondary,
                      fontSize: 12,
                    ),
                    side: BorderSide.none,
                    backgroundColor: Colors.white.withOpacity(0.1),
                  ),
                );
              }).toList(),
            ),
          ),
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openCreateSheet,
        icon: const Icon(Icons.edit),
        label: const Text('Post'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _posts.isEmpty
              ? _EmptyFeed(onPost: _openCreateSheet)
              : RefreshIndicator(
                  onRefresh: _loadFeed,
                  child: ListView.builder(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    itemCount: _posts.length,
                    itemBuilder: (ctx, i) {
                      final post = _posts[i] as Map<String, dynamic>;
                      final isOwner = post['user_id'] == me?.id;
                      return _PostCard(
                        post: post,
                        isOwner: isOwner,
                        onLike: () => _toggleLike(post),
                        onComment: () => _openComments(post),
                        onDelete: () => _deletePost(post['id'] as int),
                        onBook: post['ride_id'] != null
                            ? () => context.go('/ride/${post['ride_id']}')
                            : null,
                        onBoost: (isOwner && post['is_boosted'] != 1)
                            ? () => _boostPost(post)
                            : null,
                      );
                    },
                  ),
                ),
    );
  }
}

// ── Post card ─────────────────────────────────────────────────────────────────

class _PostCard extends StatelessWidget {
  final Map<String, dynamic> post;
  final bool isOwner;
  final VoidCallback onLike;
  final VoidCallback onComment;
  final VoidCallback onDelete;
  final VoidCallback? onBook;
  final VoidCallback? onBoost;

  const _PostCard({
    required this.post,
    required this.isOwner,
    required this.onLike,
    required this.onComment,
    required this.onDelete,
    this.onBook,
    this.onBoost,
  });

  static const _typeConfig = {
    'driver_offer': (Icons.directions_car, Colors.blue, 'Ride Offer'),
    'passenger_request': (Icons.person_search, Colors.orange, 'Looking for Ride'),
    'group_post': (Icons.groups, Colors.purple, 'Group Ride'),
  };

  @override
  Widget build(BuildContext context) {
    final type = post['type'] as String? ?? 'driver_offer';
    final cfg = _typeConfig[type] ??
        (Icons.article as IconData, Colors.grey, 'Post');
    final typeIcon = cfg.$1 as IconData;
    final typeColor = cfg.$2 as Color;
    final typeLabel = cfg.$3 as String;

    final isBoosted = post['is_boosted'] == 1;
    final likesCount = post['likes_count'] as int? ?? 0;
    final commentsCount = post['comments_count'] as int? ?? 0;
    final isLiked = post['is_liked'] == true;
    final authorScore = (post['author_score'] as num?)?.toDouble() ?? 0;
    final pic = post['author_pic'] as String?;
    final date = DateTime.tryParse(post['created_at'] as String? ?? '');

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      elevation: isBoosted ? 3 : 1,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Boosted banner
          if (isBoosted)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              decoration: BoxDecoration(
                color: Colors.amber.shade100,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
              ),
              child: Row(children: [
                const Icon(Icons.bolt, size: 12, color: Colors.amber),
                const SizedBox(width: 4),
                Text('Featured', style: TextStyle(fontSize: 11, color: Colors.amber.shade900, fontWeight: FontWeight.w700)),
              ]),
            ),

          Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Author row
                Row(children: [
                  CircleAvatar(
                    radius: 20,
                    backgroundImage: pic != null ? NetworkImage(pic) : null,
                    onBackgroundImageError: pic != null ? (_, __) {} : null,
                    child: pic == null ? const Icon(Icons.person) : null,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Text(post['author_name'] as String? ?? 'User',
                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                        if (post['author_is_student'] == 1) ...[
                          const SizedBox(width: 4),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                            decoration: BoxDecoration(
                              color: Colors.green.shade100,
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: Text('Student',
                                style: TextStyle(fontSize: 9, color: Colors.green.shade800,
                                    fontWeight: FontWeight.w700)),
                          ),
                        ],
                      ]),
                      Row(children: [
                        Icon(Icons.star, size: 11, color: Colors.amber.shade600),
                        const SizedBox(width: 2),
                        Text(authorScore.toStringAsFixed(1),
                            style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                        if (date != null) ...[
                          const Text(' · ', style: TextStyle(color: AppTheme.textSecondary)),
                          Text(_timeAgo(date), style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                        ],
                      ]),
                    ]),
                  ),
                  // Type badge
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: typeColor.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      Icon(typeIcon, size: 12, color: typeColor),
                      const SizedBox(width: 4),
                      Text(typeLabel, style: TextStyle(fontSize: 10, color: typeColor, fontWeight: FontWeight.w600)),
                    ]),
                  ),
                  if (isOwner) ...[
                    const SizedBox(width: 4),
                    PopupMenuButton<String>(
                      icon: const Icon(Icons.more_vert, size: 18, color: AppTheme.textSecondary),
                      onSelected: (v) { if (v == 'delete') onDelete(); },
                      itemBuilder: (_) => [
                        const PopupMenuItem(value: 'delete', child: Row(children: [
                          Icon(Icons.delete_outline, size: 16, color: AppTheme.danger),
                          SizedBox(width: 8), Text('Delete post', style: TextStyle(color: AppTheme.danger)),
                        ])),
                      ],
                    ),
                  ],
                ]),

                const SizedBox(height: 12),

                // Content
                Text(post['content'] as String? ?? '', style: const TextStyle(fontSize: 15, height: 1.4)),

                // Route info
                if ((post['from_location'] ?? post['to_location']) != null) ...[
                  const SizedBox(height: 10),
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: AppTheme.bgLight,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: AppTheme.border),
                    ),
                    child: Column(children: [
                      if (post['from_location'] != null)
                        _RouteRow(icon: Icons.radio_button_checked, color: Colors.green,
                            label: post['from_location'] as String),
                      if (post['to_location'] != null) ...[
                        const Padding(
                          padding: EdgeInsets.only(left: 11),
                          child: SizedBox(height: 8, child: VerticalDivider(thickness: 1)),
                        ),
                        _RouteRow(icon: Icons.location_on, color: AppTheme.danger,
                            label: post['to_location'] as String),
                      ],
                      if (post['departure_date'] != null) ...[
                        const SizedBox(height: 6),
                        Row(children: [
                          const Icon(Icons.calendar_today, size: 12, color: AppTheme.textSecondary),
                          const SizedBox(width: 6),
                          Text(post['departure_date'] as String,
                              style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                          const SizedBox(width: 12),
                          const Icon(Icons.event_seat, size: 12, color: AppTheme.textSecondary),
                          const SizedBox(width: 4),
                          Text('${post['seats'] ?? 1} seat(s)',
                              style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                        ]),
                      ],
                    ]),
                  ),
                ],

                const SizedBox(height: 12),

                // Action buttons
                Row(children: [
                  _ActionBtn(
                    icon: isLiked ? Icons.favorite : Icons.favorite_border,
                    label: '$likesCount',
                    color: isLiked ? Colors.red : AppTheme.textSecondary,
                    onTap: onLike,
                  ),
                  const SizedBox(width: 4),
                  _ActionBtn(
                    icon: Icons.chat_bubble_outline,
                    label: '$commentsCount',
                    color: AppTheme.textSecondary,
                    onTap: onComment,
                  ),
                  if (onBoost != null) ...[
                    const SizedBox(width: 4),
                    _ActionBtn(
                      icon: Icons.bolt,
                      label: 'Boost',
                      color: Colors.amber.shade700,
                      onTap: onBoost!,
                    ),
                  ],
                  const Spacer(),
                  if (onBook != null)
                    FilledButton.icon(
                      onPressed: onBook,
                      icon: const Icon(Icons.arrow_forward, size: 14),
                      label: const Text('Book Now', style: TextStyle(fontSize: 13)),
                      style: FilledButton.styleFrom(
                        backgroundColor: AppTheme.primary,
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                        minimumSize: Size.zero,
                      ),
                    ),
                ]),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _timeAgo(DateTime date) {
    final diff = DateTime.now().difference(date);
    if (diff.inMinutes < 1) return 'just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    return '${diff.inDays}d ago';
  }
}

class _RouteRow extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String label;
  const _RouteRow({required this.icon, required this.color, required this.label});

  @override
  Widget build(BuildContext context) => Row(children: [
        Icon(icon, size: 14, color: color),
        const SizedBox(width: 8),
        Expanded(child: Text(label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500))),
      ]);
}

class _ActionBtn extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;
  const _ActionBtn({required this.icon, required this.label, required this.color, required this.onTap});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(8),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, size: 18, color: color),
            const SizedBox(width: 4),
            Text(label, style: TextStyle(fontSize: 13, color: color)),
          ]),
        ),
      );
}

// ── Comments bottom sheet ─────────────────────────────────────────────────────

class _CommentsSheet extends StatefulWidget {
  final Map<String, dynamic> post;
  const _CommentsSheet({required this.post});

  @override
  State<_CommentsSheet> createState() => _CommentsSheetState();
}

class _CommentsSheetState extends State<_CommentsSheet> {
  List<dynamic> _comments = [];
  bool _loading = true;
  final _ctrl = TextEditingController();
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _loadComments();
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  Future<void> _loadComments() async {
    try {
      final res = await context.read<ApiService>().get('/feed/comments',
          params: {'post_id': widget.post['id'].toString()});
      if (mounted) setState(() { _comments = res['comments'] as List? ?? []; _loading = false; });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _sendComment() async {
    if (_ctrl.text.trim().isEmpty || _sending) return;
    final text = _ctrl.text.trim();
    _ctrl.clear();
    setState(() => _sending = true);
    try {
      await context.read<ApiService>().post('/feed/comment', {
        'post_id': widget.post['id'],
        'body': text,
      });
      await _loadComments();
    } catch (_) {} finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.6,
      maxChildSize: 0.92,
      minChildSize: 0.4,
      builder: (_, ctrl) => Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        child: Column(children: [
          const SizedBox(height: 8),
          Container(width: 36, height: 4,
              decoration: BoxDecoration(color: Colors.grey[300], borderRadius: BorderRadius.circular(2))),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              const Icon(Icons.chat_bubble_outline, size: 18, color: AppTheme.primary),
              const SizedBox(width: 8),
              Text('Comments (${widget.post['comments_count'] ?? _comments.length})',
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            ]),
          ),
          const Divider(height: 1),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _comments.isEmpty
                    ? const Center(child: Text('No comments yet. Be the first!',
                        style: TextStyle(color: AppTheme.textSecondary)))
                    : ListView.builder(
                        controller: ctrl,
                        padding: const EdgeInsets.all(12),
                        itemCount: _comments.length,
                        itemBuilder: (_, i) {
                          final c = _comments[i] as Map<String, dynamic>;
                          final pic = c['author_pic'] as String?;
                          final date = DateTime.tryParse(c['created_at'] as String? ?? '');
                          return Padding(
                            padding: const EdgeInsets.only(bottom: 12),
                            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              CircleAvatar(
                                radius: 16,
                                backgroundImage: pic != null ? NetworkImage(pic) : null,
                                onBackgroundImageError: pic != null ? (_, __) {} : null,
                                child: pic == null ? const Icon(Icons.person, size: 16) : null,
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                  Row(children: [
                                    Text(c['author_name'] as String? ?? 'User',
                                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                                    if (date != null) ...[
                                      const Text(' · ', style: TextStyle(color: AppTheme.textSecondary)),
                                      Text(_timeAgo(date),
                                          style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                                    ],
                                  ]),
                                  const SizedBox(height: 2),
                                  Text(c['body'] as String? ?? '', style: const TextStyle(fontSize: 14)),
                                ]),
                              ),
                            ]),
                          );
                        },
                      ),
          ),
          const Divider(height: 1),
          Padding(
            padding: EdgeInsets.only(
                left: 12, right: 12, top: 8, bottom: MediaQuery.of(context).viewInsets.bottom + 12),
            child: Row(children: [
              Expanded(
                child: TextField(
                  controller: _ctrl,
                  onSubmitted: (_) => _sendComment(),
                  decoration: InputDecoration(
                    hintText: 'Write a comment...',
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(24), borderSide: BorderSide.none),
                    filled: true, fillColor: Colors.grey[100],
                    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    isDense: true,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              _sending
                  ? const SizedBox(width: 36, height: 36,
                      child: Padding(padding: EdgeInsets.all(8), child: CircularProgressIndicator(strokeWidth: 2)))
                  : IconButton(
                      onPressed: _sendComment,
                      icon: const Icon(Icons.send_rounded),
                      style: IconButton.styleFrom(
                          backgroundColor: AppTheme.primary, foregroundColor: Colors.white,
                          padding: const EdgeInsets.all(8)),
                    ),
            ]),
          ),
        ]),
      ),
    );
  }

  String _timeAgo(DateTime date) {
    final diff = DateTime.now().difference(date);
    if (diff.inMinutes < 1) return 'just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m';
    if (diff.inHours < 24) return '${diff.inHours}h';
    return '${diff.inDays}d';
  }
}

// ── Create post bottom sheet ──────────────────────────────────────────────────

class _CreatePostSheet extends StatefulWidget {
  final VoidCallback onCreated;
  const _CreatePostSheet({required this.onCreated});

  @override
  State<_CreatePostSheet> createState() => _CreatePostSheetState();
}

class _CreatePostSheetState extends State<_CreatePostSheet> {
  String _type = 'driver_offer';
  final _contentCtrl = TextEditingController();
  final _fromCtrl = TextEditingController();
  final _toCtrl = TextEditingController();
  final _dateCtrl = TextEditingController();
  int _seats = 1;
  bool _sending = false;

  static const _typeOptions = [
    ('driver_offer', Icons.directions_car, 'Offering a Ride', Colors.blue),
    ('passenger_request', Icons.person_search, 'Looking for a Ride', Colors.orange),
    ('group_post', Icons.groups, 'Group Ride', Colors.purple),
  ];

  @override
  void dispose() {
    _contentCtrl.dispose(); _fromCtrl.dispose(); _toCtrl.dispose(); _dateCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_contentCtrl.text.trim().isEmpty) return;
    setState(() => _sending = true);
    try {
      await context.read<ApiService>().post('/feed/', {
        'type': _type,
        'content': _contentCtrl.text.trim(),
        if (_fromCtrl.text.isNotEmpty) 'from_location': _fromCtrl.text.trim(),
        if (_toCtrl.text.isNotEmpty) 'to_location': _toCtrl.text.trim(),
        if (_dateCtrl.text.isNotEmpty) 'departure_date': _dateCtrl.text.trim(),
        'seats': _seats,
      });
      widget.onCreated();
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final selected = _typeOptions.firstWhere((t) => t.$1 == _type);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        padding: const EdgeInsets.all(20),
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            // Handle
            Center(child: Container(width: 36, height: 4,
                decoration: BoxDecoration(color: Colors.grey[300], borderRadius: BorderRadius.circular(2)))),
            const SizedBox(height: 16),
            const Text('Create a Post', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 16),

            // Type selector
            Row(children: _typeOptions.map((t) {
              final isSelected = _type == t.$1;
              return Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 3),
                  child: InkWell(
                    onTap: () => setState(() => _type = t.$1),
                    borderRadius: BorderRadius.circular(10),
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      decoration: BoxDecoration(
                        color: isSelected ? (t.$4 as Color).withOpacity(0.1) : Colors.grey[50],
                        border: Border.all(color: isSelected ? t.$4 as Color : Colors.grey[200]!),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Column(children: [
                        Icon(t.$2 as IconData, color: isSelected ? t.$4 as Color : Colors.grey, size: 20),
                        const SizedBox(height: 4),
                        Text(t.$3 as String,
                            style: TextStyle(fontSize: 9, color: isSelected ? t.$4 as Color : Colors.grey,
                                fontWeight: FontWeight.w600),
                            textAlign: TextAlign.center),
                      ]),
                    ),
                  ),
                ),
              );
            }).toList()),
            const SizedBox(height: 16),

            // Content
            TextField(
              controller: _contentCtrl,
              maxLines: 3,
              decoration: InputDecoration(
                hintText: _type == 'driver_offer'
                    ? 'Describe your ride (departure time, preferences, price...)'
                    : _type == 'passenger_request'
                        ? 'Where are you going? When do you need the ride?'
                        : 'Describe the group ride and how to join...',
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                contentPadding: const EdgeInsets.all(12),
              ),
            ),
            const SizedBox(height: 12),

            // Route row
            Row(children: [
              Expanded(
                child: TextField(
                  controller: _fromCtrl,
                  decoration: InputDecoration(
                    hintText: 'From',
                    prefixIcon: const Icon(Icons.radio_button_checked, color: Colors.green, size: 18),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    isDense: true,
                  ),
                ),
              ),
              const Padding(padding: EdgeInsets.symmetric(horizontal: 6), child: Icon(Icons.arrow_forward, size: 16, color: AppTheme.textSecondary)),
              Expanded(
                child: TextField(
                  controller: _toCtrl,
                  decoration: InputDecoration(
                    hintText: 'To',
                    prefixIcon: const Icon(Icons.location_on, color: AppTheme.danger, size: 18),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    isDense: true,
                  ),
                ),
              ),
            ]),
            const SizedBox(height: 10),

            // Date + seats row
            Row(children: [
              Expanded(
                child: TextField(
                  controller: _dateCtrl,
                  readOnly: true,
                  onTap: () async {
                    final d = await showDatePicker(
                      context: context,
                      initialDate: DateTime.now(),
                      firstDate: DateTime.now(),
                      lastDate: DateTime.now().add(const Duration(days: 60)),
                    );
                    if (d != null) {
                      _dateCtrl.text = DateFormat('yyyy-MM-dd').format(d);
                    }
                  },
                  decoration: InputDecoration(
                    hintText: 'Date',
                    prefixIcon: const Icon(Icons.calendar_today, size: 16, color: AppTheme.textSecondary),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    isDense: true,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Row(children: [
                const Text('Seats:', style: TextStyle(fontSize: 13)),
                const SizedBox(width: 6),
                IconButton(onPressed: () { if (_seats > 1) setState(() => _seats--); },
                    icon: const Icon(Icons.remove_circle_outline, size: 20), padding: EdgeInsets.zero),
                Text('$_seats', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                IconButton(onPressed: () { if (_seats < 8) setState(() => _seats++); },
                    icon: const Icon(Icons.add_circle_outline, size: 20), padding: EdgeInsets.zero),
              ]),
            ]),
            const SizedBox(height: 16),

            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _sending ? null : _submit,
                icon: _sending
                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : Icon(selected.$2 as IconData),
                label: Text('Post as ${selected.$3}'),
                style: FilledButton.styleFrom(
                  backgroundColor: selected.$4 as Color,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}

// ── Empty state ───────────────────────────────────────────────────────────────

class _EmptyFeed extends StatelessWidget {
  final VoidCallback onPost;
  const _EmptyFeed({required this.onPost});

  @override
  Widget build(BuildContext context) => Center(
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          const Icon(Icons.dynamic_feed, size: 72, color: AppTheme.textSecondary),
          const SizedBox(height: 16),
          const Text('No posts yet', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          const Text('Be the first to share a ride or request one!',
              style: TextStyle(color: AppTheme.textSecondary), textAlign: TextAlign.center),
          const SizedBox(height: 20),
          FilledButton.icon(
            onPressed: onPost,
            icon: const Icon(Icons.edit),
            label: const Text('Create a Post'),
          ),
        ]),
      );
}