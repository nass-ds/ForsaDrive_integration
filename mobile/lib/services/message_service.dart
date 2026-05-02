import '../models/message_model.dart';
import 'api_service.dart';

class MessageService {
  final ApiService _api;
  MessageService(this._api);

  Future<List<ConversationModel>> getConversations() async {
    final res = await _api.get('/messages/');
    final list = res['conversations'] as List<dynamic>? ?? [];
    return list.map((e) => ConversationModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<MessageModel>> getMessages(int conversationId) async {
    final res = await _api.get('/messages/', params: {'conversation_id': conversationId.toString()});
    final list = res['messages'] as List<dynamic>? ?? [];
    return list.map((e) => MessageModel.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<Map<String, dynamic>> sendMessage({int? conversationId, int? otherUserId, required String message}) async {
    return await _api.post('/messages/', {
      if (conversationId != null) 'conversation_id': conversationId,
      if (otherUserId != null) 'other_user_id': otherUserId,
      'message': message,
    });
  }
}
