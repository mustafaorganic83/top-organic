import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';

/// A single kitchen command captured while offline. Shaped as a sync operation
/// so it can be replayed verbatim through `POST /api/v1/sales/sync/push`, which
/// allow-lists the `kitchen.ticket.*` commands.
class QueuedCommand {
  QueuedCommand({
    required this.clientOperationId,
    required this.ticketId,
    required this.command,
    required this.deviceSequence,
    required this.payload,
  });

  final String clientOperationId;
  final String ticketId;
  final String command;
  final int deviceSequence;
  final Map<String, dynamic> payload;

  Map<String, dynamic> toSyncOperation() => {
        'client_operation_id': clientOperationId,
        'entity_type': 'kds_ticket',
        'entity_id': ticketId,
        'command': command,
        'device_sequence': deviceSequence,
        'logical_clock': deviceSequence,
        'payload': payload,
      };

  Map<String, dynamic> toJson() => {
        'client_operation_id': clientOperationId,
        'ticket_id': ticketId,
        'command': command,
        'device_sequence': deviceSequence,
        'payload': payload,
      };

  factory QueuedCommand.fromJson(Map<String, dynamic> j) => QueuedCommand(
        clientOperationId: j['client_operation_id'] as String,
        ticketId: j['ticket_id'] as String,
        command: j['command'] as String,
        deviceSequence: j['device_sequence'] as int,
        payload: (j['payload'] as Map<String, dynamic>?) ?? const {},
      );
}

/// Durable FIFO queue backed by SharedPreferences. Survives app restarts so no
/// kitchen action is lost across a connectivity gap.
class OfflineQueue {
  OfflineQueue(this._config, {http.Client? client, SharedPreferences? prefs})
      : _client = client ?? http.Client(),
        _prefs = prefs;

  static const _storageKey = 'kds_offline_queue';
  static const _sequenceKey = 'kds_device_sequence';

  final KdsConfig _config;
  final http.Client _client;
  SharedPreferences? _prefs;

  Future<SharedPreferences> get _store async => _prefs ??= await SharedPreferences.getInstance();

  Future<int> nextSequence() async {
    final store = await _store;
    final next = (store.getInt(_sequenceKey) ?? 0) + 1;
    await store.setInt(_sequenceKey, next);
    return next;
  }

  Future<List<QueuedCommand>> pending() async {
    final store = await _store;
    final raw = store.getStringList(_storageKey) ?? const [];
    return raw.map((e) => QueuedCommand.fromJson(jsonDecode(e) as Map<String, dynamic>)).toList();
  }

  Future<int> get length async => (await pending()).length;

  Future<void> enqueue(QueuedCommand command) async {
    final store = await _store;
    final raw = store.getStringList(_storageKey) ?? <String>[];
    raw.add(jsonEncode(command.toJson()));
    await store.setStringList(_storageKey, raw);
  }

  Future<void> _replace(List<QueuedCommand> commands) async {
    final store = await _store;
    await store.setStringList(_storageKey, commands.map((c) => jsonEncode(c.toJson())).toList());
  }

  /// Attempts to flush the whole queue in one push batch. On success the queue
  /// is cleared; on network failure it is left intact for the next attempt.
  /// Returns the number of commands accepted by the server.
  Future<int> flush() async {
    final commands = await pending();
    if (commands.isEmpty) return 0;
    final response = await _client
        .post(
          Uri.parse('${_config.baseUrl}/sales/sync/push'),
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ${_config.token}',
          },
          body: jsonEncode({
            'client_batch_id': 'kds-${DateTime.now().microsecondsSinceEpoch}',
            'operations': commands.map((c) => c.toSyncOperation()).toList(),
          }),
        )
        .timeout(const Duration(seconds: 15));
    if (response.statusCode >= 200 && response.statusCode < 300) {
      await _replace(const []);
      return commands.length;
    }
    // 4xx means the batch was rejected wholesale; drop it so it does not wedge
    // the queue forever. 5xx/network errors keep it for retry.
    if (response.statusCode >= 400 && response.statusCode < 500) {
      await _replace(const []);
    }
    return 0;
  }

  void close() => _client.close();
}
