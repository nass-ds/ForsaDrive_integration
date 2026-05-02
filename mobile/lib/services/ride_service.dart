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
  }) async {
    final params = <String, String>{
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
      if (date != null && date.isNotEmpty) 'date': date,
      if (seats != null) 'seats': seats.toString(),
      if (sort != null && sort.isNotEmpty) 'sort': sort,
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
    required String departureTime,
    required int seats,
    required double price,
    String? notes,
  }) async {
    await _api.post('/rides/', {
      'from_location': from,
      'to_location': to,
      'departure_time': departureTime,
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

  Future<void> bookRide(int rideId, int seats) async {
    await _api.post('/bookings/', {
      'ride_id': rideId,
      'seats': seats,
    });
  }

  Future<Map<String, dynamic>> bookGroup({
    required int rideId,
    required int seats,
    String? notes,
  }) async {
    return await _api.post('/bookings/group', {
      'ride_id': rideId,
      'seats': seats,
      if (notes != null) 'notes': notes,
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
