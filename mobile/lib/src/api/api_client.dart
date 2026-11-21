import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'models.dart';

/// Thin JSON client for the Maisha Bora /api/v1 contract.
///
/// Unwraps the {"ok":true,"data":...} envelope, attaches the bearer token,
/// and turns error envelopes into [ApiException].
class ApiClient {
  ApiClient({required this.baseUrl, http.Client? httpClient})
      : _http = httpClient ?? http.Client();

  /// Android emulator reaches the host machine at 10.0.2.2; a physical
  /// phone needs the computer's LAN IP or the public server address.
  static const defaultBaseUrl = 'http://10.0.2.2:8123/api/v1';

  String baseUrl;
  String? token;
  final http.Client _http;

  static const _device = 'android-flutter';

  Uri _uri(String path) {
    final base = baseUrl.trimRight().replaceAll(RegExp(r'/+$'), '');
    return Uri.parse('$base$path');
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
    final request = http.Request(method, _uri(path));
    request.headers.addAll(_headers);
    if (body != null) {
      request.body = jsonEncode(body);
    }

    final http.Response response;
    try {
      final streamed = await _http
          .send(request)
          .timeout(const Duration(seconds: 30));
      response = await http.Response.fromStream(streamed);
    } on TimeoutException {
      throw ApiException(0, 'timeout', 'The server did not respond in time.');
    } on SocketException {
      throw ApiException(
          0, 'network', 'Cannot reach the server. Check the address and your connection.');
    } on http.ClientException {
      throw ApiException(
          0, 'network', 'Cannot reach the server. Check the address and your connection.');
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

  Future<FormMeta> metaForm() async => FormMeta.fromJson(await _get('/meta/form'));

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

  void close() => _http.close();
}
