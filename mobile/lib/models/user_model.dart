class UserModel {
  final int id;
  final String username;
  final String email;
  final bool isDriver;
  final bool isStudent;
  final bool isAdmin;
  final bool isHelpdeskAgent;
  final String region;
  final double score;
  final double balance;
  final String? picture;
  final String? phone;
  final String? bio;
  final String? gender;
  final String? governorate;

  const UserModel({
    required this.id,
    required this.username,
    required this.email,
    required this.isDriver,
    required this.isStudent,
    required this.isAdmin,
    required this.isHelpdeskAgent,
    required this.region,
    required this.score,
    required this.balance,
    this.picture,
    this.phone,
    this.bio,
    this.gender,
    this.governorate,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) => UserModel(
        id: json['id'] as int,
        username: json['username'] as String,
        email: json['email'] as String,
        isDriver: json['is_driver'] == true || json['is_driver'] == 1,
        isStudent: json['is_student'] == true || json['is_student'] == 1,
        isAdmin: json['is_admin'] == true || json['is_admin'] == 1,
        isHelpdeskAgent: json['is_helpdesk_agent'] == true || json['is_helpdesk_agent'] == 1,
        region: json['region'] as String? ?? 'TN',
        score: (json['score'] as num?)?.toDouble() ?? 5.0,
        balance: (json['balance'] as num?)?.toDouble() ?? 0.0,
        picture: json['picture'] as String?,
        phone: json['phone'] as String?,
        bio: json['bio'] as String?,
        gender: json['gender'] as String?,
        governorate: json['governorate'] as String?,
      );

  UserModel copyWith({
    double? balance,
    bool? isDriver,
    bool? isStudent,
    String? username,
    String? phone,
    String? bio,
    String? gender,
    String? governorate,
    String? picture,
  }) =>
      UserModel(
        id: id,
        username: username ?? this.username,
        email: email,
        isDriver: isDriver ?? this.isDriver,
        isStudent: isStudent ?? this.isStudent,
        isAdmin: isAdmin,
        isHelpdeskAgent: isHelpdeskAgent,
        region: region,
        score: score,
        balance: balance ?? this.balance,
        picture: picture ?? this.picture,
        phone: phone ?? this.phone,
        bio: bio ?? this.bio,
        gender: gender ?? this.gender,
        governorate: governorate ?? this.governorate,
      );
}
