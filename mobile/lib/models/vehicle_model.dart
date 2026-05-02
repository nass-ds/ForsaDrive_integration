class VehicleModel {
  final int id;
  final String type;
  final String? make;
  final String? model;
  final int? year;
  final String? color;
  final String? plateNumber;
  final int seats;
  final bool hasAc;
  final bool hasWifi;
  final String luggage;

  const VehicleModel({
    required this.id,
    required this.type,
    this.make,
    this.model,
    this.year,
    this.color,
    this.plateNumber,
    required this.seats,
    required this.hasAc,
    required this.hasWifi,
    required this.luggage,
  });

  factory VehicleModel.fromJson(Map<String, dynamic> json) => VehicleModel(
        id: json['id'] as int,
        type: json['type'] as String? ?? 'car',
        make: json['make'] as String?,
        model: json['model'] as String?,
        year: json['year'] as int?,
        color: json['color'] as String?,
        plateNumber: json['plate_number'] as String?,
        seats: json['seats'] as int? ?? 4,
        hasAc: json['has_ac'] == 1 || json['has_ac'] == true,
        hasWifi: json['has_wifi'] == 1 || json['has_wifi'] == true,
        luggage: json['luggage'] as String? ?? 'medium',
      );

  String get displayName => [make, model, year?.toString()].where((e) => e != null && e.isNotEmpty).join(' ');
}
