class ConversationModel {
  final int id;
  final int otherId;
  final String otherName;
  final String? otherPic;
  final String? lastMessage;
  final DateTime? lastAt;
  final int unread;

  const ConversationModel({
    required this.id,
    required this.otherId,
    required this.otherName,
    this.otherPic,
    this.lastMessage,
    this.lastAt,
    required this.unread,
  });

  factory ConversationModel.fromJson(Map<String, dynamic> json) => ConversationModel(
        id: json['id'] as int,
        otherId: json['other_id'] as int,
        otherName: json['other_name'] as String? ?? 'User',
        otherPic: json['other_pic'] as String?,
        lastMessage: json['last_message'] as String?,
        lastAt: json['last_at'] != null ? DateTime.tryParse(json['last_at'] as String) : null,
        unread: json['unread'] as int? ?? 0,
      );
}

class MessageModel {
  final int id;
  final int conversationId;
  final int senderId;
  final String body;
  final bool isRead;
  final String? senderName;
  final String? senderPic;
  final DateTime createdAt;

  const MessageModel({
    required this.id,
    required this.conversationId,
    required this.senderId,
    required this.body,
    required this.isRead,
    this.senderName,
    this.senderPic,
    required this.createdAt,
  });

  factory MessageModel.fromJson(Map<String, dynamic> json) => MessageModel(
        id: json['id'] as int,
        conversationId: json['conversation_id'] as int,
        senderId: json['sender_id'] as int,
        body: json['body'] as String? ?? '',
        isRead: json['is_read'] == 1 || json['is_read'] == true,
        senderName: json['sender_name'] as String?,
        senderPic: json['sender_pic'] as String?,
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? '') ?? DateTime.now(),
      );
}
