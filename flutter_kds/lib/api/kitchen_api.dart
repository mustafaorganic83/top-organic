import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config.dart';
import '../models/kitchen_models.dart';

/// Raised when the API returns a non-2xx response. Carries the stable error
/// code from the backend envelope so the UI can react (e.g. stale version →
/// refresh and retry).
class KitchenApiException implements Exception {
  KitchenApiException(this.status, this.code, this.message);

  final int status;
  final String code;
  final String message;

  bool get isStaleVersion => code == 'KITCHEN_STALE_VERSION';
  bool get isConflict => status == 409;

  @override
  String toString() => 'KitchenApiException($status $code: $message)';
}

/// Typed client for `/api/v1/kitchen/*`. Network failures propagate as
/// [http.ClientException]/[TimeoutException] so the controller can fall back to
/// the offline queue.
class KitchenApi {
  KitchenApi(this._config, {http.Client? client}) : _client = client ?? http.Client();

  final KdsConfig _config;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ${_config.token}',
      };

  Uri _uri(String path, [Map<String, String>? query]) =>
      Uri.parse('${_config.baseUrl}$path').replace(queryParameters: query);

  Future<KitchenBoard> fetchBoard() async {
    final query = _config.stationId == null ? null : {'station_id': _config.stationId!};
    final data = await _get('/kitchen/board', query);
    return KitchenBoard.fromJson(data as Map<String, dynamic>);
  }

  Future<List<KitchenStation>> fetchStations() async {
    final data = await _get('/kitchen/stations');
    return (data as List<dynamic>)
        .map((e) => KitchenStation.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<KitchenTicket> transition(
    String ticketId,
    String action,
    int expectedVersion,
    String operationId,
  ) async {
    final data = await _post('/kitchen/tickets/$ticketId/$action', {
      'expected_version': expectedVersion,
      'client_operation_id': operationId,
    });
    return KitchenTicket.fromJson(data as Map<String, dynamic>);
  }

  Future<KitchenTicket> assignChef(
    String ticketId,
    int expectedVersion,
    int? chefId,
    String operationId,
  ) async {
    final data = await _post('/kitchen/tickets/$ticketId/assign', {
      'expected_version': expectedVersion,
      'chef_id': chefId,
      'client_operation_id': operationId,
    });
    return KitchenTicket.fromJson(data as Map<String, dynamic>);
  }

  Future<KitchenTicket> setPriority(
    String ticketId,
    int expectedVersion,
    bool isPriority,
    String operationId,
  ) async {
    final data = await _post('/kitchen/tickets/$ticketId/priority', {
      'expected_version': expectedVersion,
      'is_priority': isPriority,
      'client_operation_id': operationId,
    });
    return KitchenTicket.fromJson(data as Map<String, dynamic>);
  }

  Future<dynamic> _get(String path, [Map<String, String>? query]) async {
    final response = await _client
        .get(_uri(path, query), headers: _headers)
        .timeout(const Duration(seconds: 10));
    return _unwrap(response);
  }

  Future<dynamic> _post(String path, Map<String, dynamic> body) async {
    final response = await _client
        .post(_uri(path), headers: _headers, body: jsonEncode(body))
        .timeout(const Duration(seconds: 10));
    return _unwrap(response);
  }

  dynamic _unwrap(http.Response response) {
    final decoded = response.body.isEmpty
        ? <String, dynamic>{}
        : jsonDecode(response.body) as Map<String, dynamic>;
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded['data'];
    }
    final error = (decoded['error'] as Map<String, dynamic>?) ?? const {};
    throw KitchenApiException(
      response.statusCode,
      error['code'] as String? ?? 'UNKNOWN',
      error['message'] as String? ?? 'Request failed',
    );
  }

  void close() => _client.close();
}
