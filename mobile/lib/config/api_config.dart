class ApiConfig {
  /// PHP backend served by XAMPP.
  /// For emulator use 'http://10.0.2.2/ForsaDrive/api'.
  /// For a physical device replace with your PC's local IP, e.g. 'http://192.168.1.X/ForsaDrive/api'.
  static const String baseUrl = 'http://10.0.2.2/ForsaDrive/api';

  /// Static uploads served by the same XAMPP vhost.
  static const String uploadsUrl = 'http://10.0.2.2/ForsaDrive/Src';

  static String profilePicture(String? pic) {
    if (pic == null || pic.isEmpty) return '$uploadsUrl/default.jpg';
    if (pic.startsWith('http')) return pic;
    return '$uploadsUrl/$pic';
  }
}
