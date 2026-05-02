import 'package:shared_preferences/shared_preferences.dart';
import '../models/user_model.dart';
import 'api_service.dart';

class AuthService {
  final ApiService _api;
  static const _tokenKey = 'auth_token';

  AuthService(this._api);

  Future<String?> getSavedToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_tokenKey);
  }

  Future<void> _saveToken(String token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey, token);
    _api.setToken(token);
  }

  Future<void> clearToken() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
    _api.setToken(null);
  }

  Future<({UserModel user, String token})> login(String email, String password) async {
    final res = await _api.post('/auth/login', {'email': email, 'password': password});
    final token = res['token'] as String;
    await _saveToken(token);
    return (user: UserModel.fromJson(res['user'] as Map<String, dynamic>), token: token);
  }

  Future<({UserModel user, String token})> signup({
    required String username,
    required String email,
    required String password,
    required String region,
    bool isDriver = false,
    String? phone,
    String? gender,
    String? dateOfBirth,
    String? governorate,
  }) async {
    final res = await _api.post('/auth/signup', {
      'username': username,
      'email': email,
      'password': password,
      'region': region,
      'is_driver': isDriver,
      if (phone != null) 'phone': phone,
      if (gender != null) 'gender': gender,
      if (dateOfBirth != null) 'date_of_birth': dateOfBirth,
      if (governorate != null) 'governorate': governorate,
    });
    final token = res['token'] as String;
    await _saveToken(token);
    return (user: UserModel.fromJson(res['user'] as Map<String, dynamic>), token: token);
  }

  Future<UserModel> getMe() async {
    final res = await _api.get('/auth/me');
    return UserModel.fromJson(res['user'] as Map<String, dynamic>);
  }

  Future<void> logout() async {
    try {
      await _api.post('/auth/logout', {});
    } catch (_) {}
    await clearToken();
  }

  Future<void> initToken() async {
    final token = await getSavedToken();
    if (token != null) _api.setToken(token);
  }
}
