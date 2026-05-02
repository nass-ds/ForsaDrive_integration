class NotificationModel {
  final int id;
  final String type;
  final String title;
  final String body;
  final bool isRead;
  final String? link;
  final DateTime createdAt;
  final int? relatedUserId;
  final String? relatedUserName;

  const NotificationModel({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.isRead,
    this.link,
    required this.createdAt,
    this.relatedUserId,
    this.relatedUserName,
  });

  factory NotificationModel.fromJson(Map<String, dynamic> json) => NotificationModel(
        id: json['id'] as int,
        type: json['type'] as String? ?? 'info',
        title: json['title'] as String? ?? '',
        body: json['body'] as String? ?? '',
        isRead: json['is_read'] == 1 || json['is_read'] == true,
        link: json['link'] as String?,
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? '') ?? DateTime.now(),
        relatedUserId: json['related_user_id'] as int?,
        relatedUserName: json['related_user_name'] as String?,
      );
}
