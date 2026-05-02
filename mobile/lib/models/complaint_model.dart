class ComplaintModel {
  final int id;
  final int againstUserId;
  final String againstName;
  final String type;
  final String description;
  final String status;
  final DateTime createdAt;

  const ComplaintModel({
    required this.id,
    required this.againstUserId,
    required this.againstName,
    required this.type,
    required this.description,
    required this.status,
    required this.createdAt,
  });

  factory ComplaintModel.fromJson(Map<String, dynamic> json) => ComplaintModel(
        id: json['id'] as int,
        againstUserId: json['against_user_id'] as int,
        againstName: json['against_name'] as String? ?? 'User',
        type: json['type'] as String? ?? 'other',
        description: json['description'] as String? ?? '',
        status: json['status'] as String? ?? 'pending',
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? '') ?? DateTime.now(),
      );
}
