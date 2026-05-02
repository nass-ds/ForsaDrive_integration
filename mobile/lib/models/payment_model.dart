class PaymentModel {
  final int id;
  final double amount;
  final String type;
  final String? description;
  final DateTime createdAt;

  const PaymentModel({
    required this.id,
    required this.amount,
    required this.type,
    this.description,
    required this.createdAt,
  });

  factory PaymentModel.fromJson(Map<String, dynamic> json) => PaymentModel(
        id: json['id'] as int,
        amount: (json['amount'] as num).toDouble(),
        type: json['type'] as String? ?? 'charge',
        description: json['description'] as String?,
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? '') ?? DateTime.now(),
      );

  bool get isCredit => amount > 0;
}
