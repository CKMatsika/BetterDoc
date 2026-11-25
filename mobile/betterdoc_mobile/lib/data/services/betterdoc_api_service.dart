import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../../domain/models/betterdoc_models.dart';

class BetterDocApiException implements Exception {
  const BetterDocApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

class BetterDocApiService {
  BetterDocApiService({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  String _normalizeBase(String url) => url.trim().replaceAll(RegExp(r'/+$'), '');

  Map<String, String> _authHeaders(AuthSession session) => {
        'Accept': 'application/json',
        'Authorization': 'Bearer ${session.token}',
      };

  Future<AuthSession> login({
    required String baseUrl,
    required String email,
    required String password,
    String deviceName = 'BetterDoc Flutter Mobile',
  }) async {
    final normalized = _normalizeBase(baseUrl);
    final resp = await _client.post(
      Uri.parse('$normalized/api/v1/mobile/auth/login'),
      headers: const {
        'Accept': 'application/json',
        'Content-Type': 'application/json; charset=UTF-8',
      },
      body: jsonEncode({
        'email': email.trim(),
        'password': password,
        'device_name': deviceName,
      }),
    );

    final body = _decodeJsonMap(resp.body);
    if (resp.statusCode != 200) {
      throw BetterDocApiException(
        body['message'] as String? ?? 'Sign-in failed (HTTP ${resp.statusCode}).',
        statusCode: resp.statusCode,
      );
    }

    final user = (body['user'] as Map<Object?, Object?>? ?? const {}).cast<String, Object?>();
    final tenant = (body['tenant'] as Map<Object?, Object?>? ?? const {}).cast<String, Object?>();

    return AuthSession(
      baseUrl: normalized,
      token: body['token'] as String? ?? '',
      userId: user['id'] as String? ?? '',
      userName: user['name'] as String? ?? email,
      userEmail: user['email'] as String? ?? email,
      clearanceRank: (user['clearance_rank'] as num?)?.toInt() ?? 1,
      tenantName: tenant['name'] as String? ?? 'BetterDoc DMS',
    );
  }

  Future<void> logout(AuthSession session) async {
    try {
      await _client.post(
        Uri.parse('${_normalizeBase(session.baseUrl)}/api/v1/mobile/auth/logout'),
        headers: _authHeaders(session),
      );
    } catch (_) {
      // Best-effort token revocation on logout
    }
  }

  Future<BootstrapData> fetchBootstrap(AuthSession session) async {
    final resp = await _client.get(
      Uri.parse('${_normalizeBase(session.baseUrl)}/api/v1/mobile/bootstrap'),
      headers: _authHeaders(session),
    );
    final body = _decodeJsonMap(resp.body);
    if (resp.statusCode != 200) {
      throw BetterDocApiException(
        body['message'] as String? ?? 'Failed to load workspace metadata.',
        statusCode: resp.statusCode,
      );
    }
    return BootstrapData.fromJson(body);
  }

  Future<List<MobileDocumentItem>> fetchDocuments(
    AuthSession session, {
    String query = '',
  }) async {
    final uri = Uri.parse('${_normalizeBase(session.baseUrl)}/api/v1/documents').replace(
      queryParameters: query.trim().isNotEmpty ? {'q': query.trim()} : null,
    );
    final resp = await _client.get(uri, headers: _authHeaders(session));
    final body = _decodeJsonMap(resp.body);
    if (resp.statusCode != 200) {
      throw BetterDocApiException(
        body['message'] as String? ?? 'Failed to load vault documents.',
        statusCode: resp.statusCode,
      );
    }

    return (body['data'] as List<Object?>? ?? const [])
        .whereType<Map<Object?, Object?>>()
        .map((e) => MobileDocumentItem.fromJson(e.cast<String, Object?>()))
        .toList();
  }

  Future<CaptureResult> captureDocument({
    required AuthSession session,
    required Uint8List bytes,
    required String filename,
    required String cabinetId,
    required String clearanceLevelId,
    required String title,
    bool autoDeskew = true,
    bool forceInvoice = false,
  }) async {
    final uri = Uri.parse('${_normalizeBase(session.baseUrl)}/api/v1/mobile/capture');
    final req = http.MultipartRequest('POST', uri)
      ..headers.addAll(_authHeaders(session))
      ..fields['cabinet_id'] = cabinetId
      ..fields['classification_level_id'] = clearanceLevelId
      ..fields['title'] = title
      ..fields['auto_deskew'] = autoDeskew ? '1' : '0'
      ..fields['force_invoice'] = forceInvoice ? '1' : '0'
      ..files.add(http.MultipartFile.fromBytes('file', bytes, filename: filename));

    final streamed = await _client.send(req);
    final resp = await http.Response.fromStream(streamed);
    final body = _decodeJsonMap(resp.body);

    if (resp.statusCode != 201 && resp.statusCode != 200) {
      throw BetterDocApiException(
        body['message'] as String? ?? 'Capture upload failed (HTTP ${resp.statusCode}).',
        statusCode: resp.statusCode,
      );
    }

    return CaptureResult.fromJson(body);
  }

  Future<ErpProcessResult> processInvoiceToErp({
    required AuthSession session,
    required String documentId,
    required Map<String, Object?> payload,
  }) async {
    final uri = Uri.parse(
      '${_normalizeBase(session.baseUrl)}/api/v1/mobile/documents/$documentId/process-invoice',
    );
    final resp = await _client.post(
      uri,
      headers: {
        ..._authHeaders(session),
        'Content-Type': 'application/json; charset=UTF-8',
      },
      body: jsonEncode(payload),
    );
    final body = _decodeJsonMap(resp.body);
    if (resp.statusCode != 200) {
      throw BetterDocApiException(
        body['message'] as String? ?? 'Accounting ERP push failed (HTTP ${resp.statusCode}).',
        statusCode: resp.statusCode,
      );
    }
    return ErpProcessResult.fromJson(body);
  }

  Future<MobileDocumentItem> updateDocument({
    required AuthSession session,
    required String documentId,
    String? title,
    String? status,
  }) async {
    final uri = Uri.parse(
      '${_normalizeBase(session.baseUrl)}/api/v1/mobile/documents/$documentId',
    );
    final resp = await _client.patch(
      uri,
      headers: {
        ..._authHeaders(session),
        'Content-Type': 'application/json; charset=UTF-8',
      },
      body: jsonEncode({
        if (title != null && title.trim().isNotEmpty) 'title': title.trim(),
        'status': ?status,
      }),
    );
    final body = _decodeJsonMap(resp.body);
    if (resp.statusCode != 200) {
      throw BetterDocApiException(
        body['message'] as String? ?? 'Document update failed.',
        statusCode: resp.statusCode,
      );
    }
    final doc = (body['document'] as Map<Object?, Object?>? ?? const {}).cast<String, Object?>();
    return MobileDocumentItem.fromJson(doc);
  }

  Map<String, Object?> _decodeJsonMap(String raw) {
    if (raw.trim().isEmpty) {
      return const {};
    }
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map<Object?, Object?>) {
        return decoded.cast<String, Object?>();
      }
      return const {};
    } catch (_) {
      return const {};
    }
  }
}
