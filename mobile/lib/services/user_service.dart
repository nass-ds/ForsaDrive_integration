import '../models/vehicle_model.dart';
import '../models/payment_model.dart';
import '../models/notification_model.dart';
import '../models/complaint_model.dart';
import 'api_service.dart';

class UserService {
  final ApiService _api;
  UserService(this._api);

  // ── Vehicles ──────────────────────────────────────────────────────────────

  Future<List<VehicleModel>> getVehicles() async {
    final res = await _api.get('/vehicles/');
    final list = res['vehicles'] as List<dynamic>? ?? [];
    return list.map((e) => VehicleModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> addVehicle(Map<String, dynamic> data) async {
    await _api.post('/vehicles/', data);
  }

  Future<void> deleteVehicle(int id) async {
    await _api.post('/vehicles/', {'action': 'delete', 'id': id});
  }

  // ── Payments ──────────────────────────────────────────────────────────────

  Future<({List<PaymentModel> payments, double balance})> getPayments() async {
    final res = await _api.get('/payments/');
    final list = res['payments'] as List<dynamic>? ?? [];
    return (
      payments: list.map((e) => PaymentModel.fromJson(e as Map<String, dynamic>)).toList(),
      balance: (res['balance'] as num?)?.toDouble() ?? 0.0,
    );
  }

  Future<double> deposit(double amount) async {
    final res = await _api.post('/payments/', {'action': 'deposit', 'amount': amount});
    return (res['new_balance'] as num?)?.toDouble() ?? 0.0;
  }

  // ── Notifications ─────────────────────────────────────────────────────────

  Future<({List<NotificationModel> notifications, int unread})> getNotifications() async {
    final res = await _api.get('/notifications/');
    final list = res['notifications'] as List<dynamic>? ?? [];
    return (
      notifications: list.map((e) => NotificationModel.fromJson(e as Map<String, dynamic>)).toList(),
      unread: res['unread_count'] as int? ?? 0,
    );
  }

  Future<void> markAllNotificationsRead() async {
    await _api.post('/notifications/', {'action': 'read_all'});
  }

  // ── Profile ───────────────────────────────────────────────────────────────

  Future<void> updateProfile(Map<String, dynamic> data) async {
    await _api.post('/profile/', {'action': 'update', ...data});
  }

  Future<void> changePassword(String current, String newPass) async {
    await _api.post('/profile/', {
      'action': 'change_password',
      'current_password': current,
      'new_password': newPass,
    });
  }

  Future<void> becomeDriver() async {
    await _api.post('/profile/', {'action': 'become_driver'});
  }

  // ── Student verification ──────────────────────────────────────────────────

  Future<Map<String, dynamic>> sendStudentOtp(String email) async {
    return await _api.post('/student/send-otp', {'email': email});
  }

  Future<Map<String, dynamic>> verifyStudentOtp(String email, String code) async {
    return await _api.post('/student/verify-otp', {'email': email, 'code': code});
  }

  // ── Student domains ───────────────────────────────────────────────────────

  Future<List<Map<String, dynamic>>> getStudentDomains() async {
    final res = await _api.get('/student/domains');
    final list = res['details'] as List<dynamic>? ?? [];
    return list.map((e) => e as Map<String, dynamic>).toList();
  }

  // ── Complaints ────────────────────────────────────────────────────────────

  Future<List<ComplaintModel>> getComplaints() async {
    final res = await _api.get('/complaints/');
    final list = res['complaints'] as List<dynamic>? ?? [];
    return list.map((e) => ComplaintModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> submitComplaint(Map<String, dynamic> data) async {
    await _api.post('/complaints/', data);
  }

  // ── Ratings ───────────────────────────────────────────────────────────────

  Future<List<dynamic>> getRatings() async {
    final res = await _api.get('/ratings/');
    return res['ratings'] as List<dynamic>? ?? [];
  }

  Future<void> submitRating(int rideId, int toUserId, int score, String comment) async {
    await _api.post('/ratings/', {
      'ride_id': rideId,
      'to_user_id': toUserId,
      'score': score,
      'comment': comment,
    });
  }

  // ── Admin (web-only; mobile access is blocked at navigation level) ────────

  Future<Map<String, dynamic>> getAdminData(String tab) async {
    return await _api.get('/admin/', params: {'tab': tab});
  }

  Future<void> adminAction(Map<String, dynamic> data) async {
    await _api.post('/admin/', data);
  }

  Future<Map<String, dynamic>> getStudentDomainsList() async {
    return await _api.get('/admin/student-domains');
  }

  Future<void> addStudentDomain(String domain, String label) async {
    await _api.post('/admin/student-domains', {'domain': domain, 'label': label});
  }

  Future<void> removeStudentDomain(int id) async {
    await _api.delete('/admin/student-domains/$id');
  }

  Future<List<Map<String, dynamic>>> getAdminOrganizations() async {
    final res = await _api.get('/admin/organizations');
    final list = res['organizations'] as List<dynamic>? ?? [];
    return list.map((e) => e as Map<String, dynamic>).toList();
  }

  Future<void> reviewOrganization(int id, String action, {String? rejectionReason}) async {
    await _api.post('/admin/organizations/$id/review', {
      'action': action,
      if (rejectionReason != null) 'rejection_reason': rejectionReason,
    });
  }
}
