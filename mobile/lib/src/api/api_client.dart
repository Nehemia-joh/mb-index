import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import 'models.dart';

/// Thin JSON client for the Maisha Bora /api/v1 contract.
///
/// Unwraps the {"ok":true,"data":...} envelope, attaches the bearer token,
/// and turns error envelopes into [ApiException].
class ApiClient {
  ApiClient({required this.baseUrl, http.Client? httpClient})
      : _http = httpClient ?? http.Client();

  /// Production endpoint; the deployed API runs behind HTTPS at
  /// mb-index.nexabie.co.tz. Dev/emulator addresses are entered once under
  /// "Server settings" and remembered.
  static const defaultBaseUrl = 'https://mb-index.nexabie.co.tz/api/v1';

  String baseUrl;
  String? token;
  final http.Client _http;

  static const _device = 'android-flutter';

  Uri _uri(String path) {
    final base = baseUrl.trim().replaceAll(RegExp(r'/+$'), '');
    if (!looksLikeServerUrl('$base$path')) {
      throw ApiException(
          0,
          'bad_url',
          'The server address is not valid — expected '
          'http://<server>:<port>/api/v1.');
    }
    return Uri.parse('$base$path');
  }

  /// Cheap client-side check that [url] points at a plausible server.
  ///
  /// Catches the common field typo of a truncated IP (e.g. `192.168.:8123`):
  /// an all-numeric host must be a complete dotted quad, and any other host
  /// must not end in a dot. No network access — this is a sanity check, not
  /// a reachability test.
  static bool looksLikeServerUrl(String url) {
    final parsed = Uri.tryParse(url.trim());
    if (parsed == null ||
        !parsed.hasScheme ||
        (parsed.scheme != 'http' && parsed.scheme != 'https') ||
        parsed.host.isEmpty) {
      return false;
    }
    final host = parsed.host;
    if (RegExp(r'^[\d.]+$').hasMatch(host)) {
      final parts = host.split('.');
      return parts.length == 4 &&
          parts.every((p) => p.isNotEmpty && p.length <= 3 && int.parse(p) <= 255);
    }
    return !host.endsWith('.');
  }

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        if (token != null && token!.isNotEmpty)
          'Authorization': 'Bearer $token',
      };

  Future<Map<String, dynamic>> _request(
    String method,
    String path, {
    Object? body,
  }) async {
    final target = _uri(path);

    // named for error messages: "cannot reach" is useless without where to
    final host = '${target.host}:${target.port}';

    http.Response response;
    var attempt = 0;
    while (true) {
      attempt++;
      try {
        // fresh Request per attempt: http.Request is not safely re-sendable
        final request = http.Request(method, target);
        request.headers.addAll(_headers);
        if (body != null) {
          request.body = jsonEncode(body);
        }
        final streamed = await _http
            .send(request)
            .timeout(const Duration(seconds: 30));
        response = await http.Response.fromStream(streamed);
        break;
      } on TimeoutException {
        throw ApiException(0, 'timeout', 'The server did not respond in time.');
      } on SocketException catch (e) {
        // The pooled keep-alive socket is the usual suspect: Apache closes
        // idle connections after a few seconds, so a request that starts
        // later (e.g. opening the New tab) can hit a socket the server has
        // already closed. One quick retry on a fresh connection fixes it.
        // GETs only — a save POST may have reached the server already.
        if (attempt < 2 && method == 'GET') {
          await Future<void>.delayed(const Duration(milliseconds: 400));
          continue;
        }
        debugPrint('API network error ($host $method ${target.path}): $e');
        throw ApiException(
            0,
            'network',
            'Cannot reach the server at $host. Check the address '
            'and your connection.');
      } on http.ClientException catch (e) {
        if (attempt < 2 && method == 'GET') {
          await Future<void>.delayed(const Duration(milliseconds: 400));
          continue;
        }
        debugPrint('API network error ($host $method ${target.path}): $e');
        throw ApiException(
            0,
            'network',
            'Cannot reach the server at $host. Check the address '
            'and your connection.');
      }
    }

    Map<String, dynamic> json;
    try {
      final decoded = jsonDecode(utf8.decode(response.bodyBytes));
      if (decoded is! Map<String, dynamic>) {
        throw const FormatException('not an object');
      }
      json = decoded;
    } on FormatException {
      throw ApiException(
          response.statusCode, 'bad_response', 'Unexpected server response.');
    }

    if (response.statusCode >= 200 &&
        response.statusCode < 300 &&
        json['ok'] == true) {
      final data = json['data'];
      if (data is Map<String, dynamic>) return data;
      return {'value': data};
    }

    final error = json['error'];
    final map = error is Map ? error : const {};
    throw ApiException(
      response.statusCode,
      '${map['code'] ?? 'error'}',
      '${map['message'] ?? 'Request failed.'}',
    );
  }

  Future<Map<String, dynamic>> _get(String path) => _request('GET', path);

  Future<Map<String, dynamic>> _post(String path, [Object? body]) =>
      _request('POST', path, body: body);

  Future<({String token, ApiUser user})> login(
      String username, String password) async {
    final data = await _post('/auth/login', {
      'username': username,
      'password': password,
      'device': _device,
    });
    final issued = data['token'] as String? ?? '';
    token = issued;
    final user = ApiUser.fromJson(
        (data['user'] as Map?)?.cast<String, dynamic>() ?? const {});
    return (token: issued, user: user);
  }

  Future<void> logout() async {
    try {
      await _post('/auth/logout');
    } on ApiException {
      // already dead server-side — still clear locally below
    }
    token = null;
  }

  Future<ApiUser> me() async {
    final data = await _get('/auth/me');
    return ApiUser.fromJson(
        (data['user'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  /// Form definitions (item wordings, required fields, subscales) change
  /// only when an administrator edits them — cache them for the app session
  /// so the New tab and the post-save blank form open instantly instead of
  /// waiting ~2 s on the production round-trip. An app restart re-fetches.
  FormMeta? _meta;

  Future<FormMeta> metaForm() async {
    final cached = _meta;
    if (cached != null) return cached;
    final parsed = FormMeta.fromJson(await _get('/meta/form'));
    _meta = parsed;
    return parsed;
  }

  Future<({List<AssessmentSummary> items, int total, bool hasMore, String scope})>
      listAssessments({int page = 1, int perPage = 20}) async {
    final data = await _get('/assessments?page=$page&per_page=$perPage');
    final items = ((data['items'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(AssessmentSummary.fromJson)
        .toList();
    return (
      items: items,
      total: (data['total'] as num?)?.toInt() ?? 0,
      hasMore: data['has_more'] as bool? ?? false,
      scope: data['scope'] as String? ?? 'own',
    );
  }

  /// POST the raw rating form; returns the new id and the scoring report.
  Future<({int id, Report report, AssessmentDetail record})> createAssessment(
      Map<String, dynamic> form) async {
    final data = await _post('/assessments', form);
    return (
      id: (data['id'] as num?)?.toInt() ?? 0,
      report: Report.fromJson(
          (data['report'] as Map?)?.cast<String, dynamic>() ?? const {}),
      record: AssessmentDetail.fromJson(
          (data['record'] as Map?)?.cast<String, dynamic>() ?? const {}),
    );
  }

  Future<AssessmentDetail> getAssessment(int id) async {
    final data = await _get('/assessments/$id');
    return AssessmentDetail.fromJson(
        (data['record'] as Map?)?.cast<String, dynamic>() ?? const {});
  }

  /// PUT the full rating form over an existing record; the server re-scores
  /// it with the same engine as POST and returns the updated record + report.
  /// Never auto-retried — _request only replays GETs on stale sockets.
  Future<({Report report, AssessmentDetail record})> updateAssessment(
      int id, Map<String, dynamic> form) async {
    final data = await _request('PUT', '/assessments/$id', body: form);
    return (
      report: Report.fromJson(
          (data['report'] as Map?)?.cast<String, dynamic>() ?? const {}),
      record: AssessmentDetail.fromJson(
          (data['record'] as Map?)?.cast<String, dynamic>() ?? const {}),
    );
  }

  void close() => _http.close();
}
