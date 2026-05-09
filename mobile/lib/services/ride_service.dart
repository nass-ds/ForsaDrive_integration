import '../models/ride_model.dart';
import 'api_service.dart';

class RideService {
  final ApiService _api;
  RideService(this._api);

  Future<List<RideModel>> getAvailableRides({
    String? from,
    String? to,
    String? date,
    int? seats,
    String? sort,
    double? minPrice,
    double? maxPrice,
    double? minRating,
    List<String>? amenities,
  }) async {
    final params = <String, String>{
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
      if (date != null && date.isNotEmpty) 'date': date,
      if (seats != null) 'seats': seats.toString(),
      if (sort != null && sort.isNotEmpty) 'sort': sort,
      if (minPrice != null) 'min_price': minPrice.toString(),
      if (maxPrice != null) 'max_price': maxPrice.toString(),
      if (minRating != null) 'min_rating': minRating.toString(),
      if (amenities != null && amenities.isNotEmpty) 'amenities': amenities.join(','),
    };
    final res = await _api.get('/rides/', params: params);
    final list = res['rides'] as List<dynamic>? ?? [];
    return list.map((e) => RideModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<RideModel>> getMyRides({bool asDriver = false}) async {
    final res = await _api.get('/rides/my');
    final list = res['rides'] as List<dynamic>? ?? [];
    return list.map((e) => RideModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> createRide({
    required String from,
    required String to,
    required String date,
    required String time,
    required int seats,
    required double price,
    String? notes,
  }) async {
    await _api.post('/rides/', {
      'from_location': from,
      'to_location': to,
      'departure_time': '$date $time',
      'available_seats': seats,
      'price': price,
      if (notes != null) 'notes': notes,
    });
  }

  Future<void> cancelRide(int rideId) async {
    await _api.post('/rides/$rideId/cancel', {});
  }

  Future<void> markDeparture(int rideId) async {
    await _api.post('/rides/$rideId/depart', {});
  }

  Future<void> markArrival(int rideId) async {
    await _api.post('/rides/$rideId/arrive', {});
  }

  Future<RideModel> getRideDetail(int rideId) async {
    final res = await _api.get('/rides/$rideId');
    return RideModel.fromJson(res['ride'] as Map<String, dynamic>);
  }

  Future<List<Map<String, dynamic>>> getRidePassengers(int rideId) async {
    final res = await _api.get('/rides/$rideId');
    return List<Map<String, dynamic>>.from(
      (res['ride'] as Map<String, dynamic>?)?['passengers'] as List? ?? [],
    );
  }

  Future<Map<String, dynamic>> getTripReceipt(int rideId) async {
    return await _api.get('/rides/$rideId/receipt');
  }

  Future<void> bookRide(int rideId, int seats, {String? promoCode}) async {
    await _api.post('/bookings/', {
      'ride_id': rideId,
      'seats': seats,
      if (promoCode != null) 'promo_code': promoCode,
    });
  }

  Future<Map<String, dynamic>> validatePromoCode(String code) async {
    return await _api.post('/bookings/validate-promo', {'code': code});
  }

  Future<Map<String, dynamic>> bookGroup({
    required int rideId,
    required int seats,
    String? notes,
    bool splitPayment = false,
    List<Map<String, dynamic>>? passengers,
  }) async {
    return await _api.post('/bookings/group', {
      'ride_id': rideId,
      'seats': seats,
      'split_payment': splitPayment,
      if (notes != null) 'notes': notes,
      if (passengers != null) 'passengers': passengers,
    });
  }

  Future<List<dynamic>> getBookingRequests() async {
    final res = await _api.get('/bookings/requests');
    return res['requests'] as List<dynamic>? ?? [];
  }

  Future<void> respondToBooking(int bookingId, String action) async {
    await _api.post('/bookings/requests/$bookingId', {'action': action});
  }

  Future<void> markCashCollected(int bookingId) async {
    await _api.post('/bookings/$bookingId/cash-collected', {});
  }

  Future<void> cancelBooking(int bookingId) async {
    await _api.delete('/bookings/$bookingId');
  }
}
