class ApiConfig {
  /// PHP backend served by XAMPP.
  ///
  /// Default targets the Android emulator loopback to the host (10.0.2.2).
  /// Override at build/run time without editing this file:
  ///   flutter run --dart-define=API_BASE=http://192.168.1.X/ForsaDrive
  ///
  /// Common values:
  /// - Android emulator on the dev machine: leave default.
  /// - Physical phone on same Wi-Fi: --dart-define with your PC's LAN IP.
  /// - iOS simulator: --dart-define=API_BASE=http://localhost/ForsaDrive
  static const String _base = String.fromEnvironment(
    'API_BASE',
    defaultValue: 'http://10.0.2.2/ForsaDrive',
  );

  static const String baseUrl    = '$_base/api';
  static const String uploadsUrl = '$_base/Src';

  static String profilePicture(String? pic) {
    if (pic == null || pic.isEmpty) return '$uploadsUrl/default.jpg';
    if (pic.startsWith('http')) return pic;
    return '$uploadsUrl/$pic';
  }
}
