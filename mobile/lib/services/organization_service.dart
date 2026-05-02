import '../models/organization_model.dart';
import 'api_service.dart';

class OrganizationService {
  final ApiService _api;
  OrganizationService(this._api);

  Future<List<OrganizationModel>> getMyOrganizations() async {
    final res = await _api.get('/organizations/');
    final list = res['organizations'] as List<dynamic>? ?? [];
    return list
        .map((e) => OrganizationModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<void> applyForOrganization(Map<String, dynamic> data) async {
    await _api.post('/organizations/', {'action': 'apply', ...data});
  }

  Future<Map<String, dynamic>> getMembers(int orgId) async {
    return await _api.get('/organizations/$orgId/members');
  }

  Future<void> addMember(int orgId, {required String name, String? email, String? phone, String role = 'member'}) async {
    await _api.post('/organizations/$orgId/members', {
      'name': name,
      if (email != null && email.isNotEmpty) 'email': email,
      if (phone != null && phone.isNotEmpty) 'phone': phone,
      'role': role,
    });
  }

  Future<void> removeMember(int orgId, int memberId) async {
    await _api.delete('/organizations/$orgId/members/$memberId');
  }
}
