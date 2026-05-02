class RideModel {
  final int id;
  final int driverId;
  final String fromLocation;
  final String toLocation;
  final DateTime departureTime;
  final double price;
  final int availableSeats;
  final String status;
  final int? seatsLeft;
  final String? driverName;
  final double? driverScore;
  final String? driverPic;
  final String? vehicleType;
  final String? notes;
  // For passenger's bookings
  final int? bookingId;
  final String? bookingStatus;
  final int? bookedSeats;
  final double? paidAmount;
  // Amenities
  final bool? hasAc;
  final bool? hasWifi;
  final bool? hasMusic;
  final bool? isAccessible;
  // Compatibility score (0–100), null when not computed (e.g. my rides)
  final int? matchScore;

  const RideModel({
    required this.id,
    required this.driverId,
    required this.fromLocation,
    required this.toLocation,
    required this.departureTime,
    required this.price,
    required this.availableSeats,
    required this.status,
    this.seatsLeft,
    this.driverName,
    this.driverScore,
    this.driverPic,
    this.vehicleType,
    this.notes,
    this.bookingId,
    this.bookingStatus,
    this.bookedSeats,
    this.paidAmount,
    this.hasAc,
    this.hasWifi,
    this.hasMusic,
    this.isAccessible,
    this.matchScore,
  });

  factory RideModel.fromJson(Map<String, dynamic> json) => RideModel(
        id: json['id'] as int,
        driverId: json['driver_id'] as int,
        fromLocation: json['from_location'] as String,
        toLocation: json['to_location'] as String,
        departureTime: DateTime.tryParse(json['departure_time'] as String? ?? '') ?? DateTime.now(),
        price: (json['price'] as num?)?.toDouble() ?? 0,
        availableSeats: json['available_seats'] as int? ?? 0,
        status: json['status'] as String? ?? 'open',
        seatsLeft: json['seats_left'] as int?,
        driverName: json['driver_name'] as String?,
        driverScore: (json['driver_score'] as num?)?.toDouble(),
        driverPic: json['driver_pic'] as String?,
        vehicleType: json['vehicle_type'] as String?,
        notes: json['notes'] as String?,
        bookingId: json['booking_id'] as int?,
        bookingStatus: json['booking_status'] as String?,
        bookedSeats: json['booked_seats'] as int?,
        paidAmount: (json['paid_amount'] as num?)?.toDouble(),
        hasAc: json['has_ac'] as bool?,
        hasWifi: json['has_wifi'] as bool?,
        hasMusic: json['has_music'] as bool?,
        isAccessible: json['is_accessible'] as bool?,
        matchScore: json['match_score'] as int?,
      );

  bool get isUpcoming => departureTime.isAfter(DateTime.now());
  double get studentPrice => price * 0.5;
}
