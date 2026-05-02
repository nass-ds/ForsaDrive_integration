class OrganizationModel {
  final int id;
  final String name;
  final String type;
  final String contactPerson;
  final String contactEmail;
  final String? phone;
  final String? staffEmailDomain;
  final int discountPercent;
  final String? address;
  final String? notes;
  final String status; // pending, approved, rejected
  final String? discountCode;
  final String? rejectionReason;
  final DateTime createdAt;

  const OrganizationModel({
    required this.id,
    required this.name,
    required this.type,
    required this.contactPerson,
    required this.contactEmail,
    this.phone,
    this.staffEmailDomain,
    required this.discountPercent,
    this.address,
    this.notes,
    required this.status,
    this.discountCode,
    this.rejectionReason,
    required this.createdAt,
  });

  factory OrganizationModel.fromJson(Map<String, dynamic> json) =>
      OrganizationModel(
        id: json['id'] as int,
        name: json['name'] as String,
        type: json['type'] as String,
        contactPerson: json['contact_person'] as String,
        contactEmail: json['contact_email'] as String,
        phone: json['phone'] as String?,
        staffEmailDomain: json['staff_email_domain'] as String?,
        discountPercent: (json['discount_percent'] as num?)?.toInt() ?? 10,
        address: json['address'] as String?,
        notes: json['notes'] as String?,
        status: json['status'] as String? ?? 'pending',
        discountCode: json['discount_code'] as String?,
        rejectionReason: json['rejection_reason'] as String?,
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? '') ??
            DateTime.now(),
      );
}
