import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';

class ApiException implements Exception {
  final String message;
  final int? statusCode;
  ApiException(this.message, [this.statusCode]);
  @override
  String toString() => message;
}

class ApiService {
  String? _token;
  void Function()? onUnauthorized;

  void setToken(String? token) => _token = token;

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
      };

  Future<Map<String, dynamic>> get(String endpoint, {Map<String, String>? params}) async {
    var uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
    if (params != null && params.isNotEmpty) {
      uri = uri.replace(queryParameters: params);
    }
    try {
      final response = await http.get(uri, headers: _headers);
      return _handle(response);
    } on http.ClientException {
      throw ApiException('Server unreachable. Make sure the backend is running.');
    }
  }

  Future<Map<String, dynamic>> post(String endpoint, Map<String, dynamic> body) async {
    try {
      final response = await http.post(
        Uri.parse('${ApiConfig.baseUrl}$endpoint'),
        headers: _headers,
        body: jsonEncode(body),
      );
      return _handle(response);
    } on http.ClientException {
      throw ApiException('Server unreachable. Make sure the backend is running.');
    }
  }

  Future<Map<String, dynamic>> delete(String endpoint) async {
    try {
      final response = await http.delete(
        Uri.parse('${ApiConfig.baseUrl}$endpoint'),
        headers: _headers,
      );
      return _handle(response);
    } on http.ClientException {
      throw ApiException('Server unreachable. Make sure the backend is running.');
    }
  }

  Map<String, dynamic> _handle(http.Response response) {
    final data = jsonDecode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return data;
    }
    if (response.statusCode == 401) {
      _token = null;
      onUnauthorized?.call();
    }
    throw ApiException(data['message'] as String? ?? 'Request failed', response.statusCode);
  }
}
