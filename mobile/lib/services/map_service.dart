import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:latlong2/latlong.dart';

class MapService {
  static const _nominatimBase = 'https://nominatim.openstreetmap.org';
  static const _osrmBase = 'https://router.project-osrm.org';

  // Convert a city/place name → LatLng using OpenStreetMap Nominatim (free, no key)
  Future<LatLng?> geocode(String place) async {
    try {
      final uri = Uri.parse('$_nominatimBase/search').replace(
        queryParameters: {
          'q': '$place, Tunisia',
          'format': 'json',
          'limit': '1',
        },
      );
      final res = await http.get(uri, headers: {
        'User-Agent': 'ForsaDrive/1.0',
        'Accept-Language': 'en',
      }).timeout(const Duration(seconds: 6));
      final data = jsonDecode(res.body) as List;
      if (data.isEmpty) return null;
      final lat = double.tryParse(data[0]['lat'] as String? ?? '');
      final lon = double.tryParse(data[0]['lon'] as String? ?? '');
      if (lat == null || lon == null) return null;
      return LatLng(lat, lon);
    } catch (_) {
      return null;
    }
  }

  // Get driving route polyline using OSRM (free, no key)
  // Falls back to a straight line [from, to] if routing fails
  Future<List<LatLng>> getRoute(LatLng from, LatLng to) async {
    try {
      final url =
          '$_osrmBase/route/v1/driving/${from.longitude},${from.latitude};'
          '${to.longitude},${to.latitude}?geometries=geojson&overview=full';
      final res = await http
          .get(Uri.parse(url))
          .timeout(const Duration(seconds: 8));
      final data = jsonDecode(res.body) as Map<String, dynamic>;
      if (data['code'] != 'Ok') return [from, to];
      final coords =
          ((data['routes'] as List).first['geometry']['coordinates']) as List;
      return coords.map((c) {
        final pair = c as List;
        return LatLng(
          (pair[1] as num).toDouble(),
          (pair[0] as num).toDouble(),
        );
      }).toList();
    } catch (_) {
      return [from, to];
    }
  }
}
