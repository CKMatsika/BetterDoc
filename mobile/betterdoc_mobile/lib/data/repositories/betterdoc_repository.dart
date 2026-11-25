import 'dart:convert';
import 'dart:typed_data';

import 'package:shared_preferences/shared_preferences.dart';

import '../../domain/models/betterdoc_models.dart';
import '../services/betterdoc_api_service.dart';

class BetterDocRepository {
  BetterDocRepository({required BetterDocApiService apiService}) : _api = apiService;

  static const _sessionKey = 'betterdoc_mobile_session_v1';
  static const _offlineQueueKey = 'betterdoc_mobile_offline_queue_v1';

  final BetterDocApiService _api;

  Future<AuthSession?> loadSession() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_sessionKey);
    if (raw == null || raw.isEmpty) {
      return null;
    }
    try {
      final map = (jsonDecode(raw) as Map<Object?, Object?>).cast<String, Object?>();
      final session = AuthSession.fromJson(map);
      return session.token.isNotEmpty ? session : null;
    } catch (_) {
      return null;
    }
  }

  Future<AuthSession> signIn({
    required String baseUrl,
    required String email,
    required String password,
  }) async {
    final session = await _api.login(baseUrl: baseUrl, email: email, password: password);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_sessionKey, jsonEncode(session.toJson()));
    return session;
  }

  Future<void> signOut(AuthSession session) async {
    await _api.logout(session);
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_sessionKey);
  }

  Future<BootstrapData> getBootstrap(AuthSession session) => _api.fetchBootstrap(session);

  Future<List<MobileDocumentItem>> getDocuments(AuthSession session, {String query = ''}) =>
      _api.fetchDocuments(session, query: query);

  Future<CaptureResult> captureDocument({
    required AuthSession session,
    required Uint8List bytes,
    required String filename,
    required String cabinetId,
    required String clearanceLevelId,
    required String title,
    bool forceInvoice = false,
  }) =>
      _api.captureDocument(
        session: session,
        bytes: bytes,
        filename: filename,
        cabinetId: cabinetId,
        clearanceLevelId: clearanceLevelId,
        title: title,
        forceInvoice: forceInvoice,
      );

  Future<ErpProcessResult> processInvoiceToErp({
    required AuthSession session,
    required String documentId,
    required Map<String, Object?> payload,
  }) =>
      _api.processInvoiceToErp(session: session, documentId: documentId, payload: payload);

  Future<MobileDocumentItem> archiveDocument(AuthSession session, String documentId) =>
      _api.updateDocument(session: session, documentId: documentId, status: 'archived');

  Future<List<OfflineQueuedCapture>> listOfflineQueue() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_offlineQueueKey);
    if (raw == null || raw.isEmpty) {
      return const [];
    }
    try {
      final list = jsonDecode(raw) as List<Object?>;
      return list
          .whereType<Map<Object?, Object?>>()
          .map((e) => OfflineQueuedCapture.fromJson(e.cast<String, Object?>()))
          .toList();
    } catch (_) {
      return const [];
    }
  }

  Future<void> enqueueOffline(OfflineQueuedCapture item) async {
    final current = await listOfflineQueue();
    final updated = [item, ...current];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _offlineQueueKey,
      jsonEncode(updated.map((e) => e.toJson()).toList()),
    );
  }

  Future<void> removeOffline(String id) async {
    final current = await listOfflineQueue();
    final updated = current.where((e) => e.id != id).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _offlineQueueKey,
      jsonEncode(updated.map((e) => e.toJson()).toList()),
    );
  }
}
