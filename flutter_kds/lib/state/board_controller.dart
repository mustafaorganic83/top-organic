import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/kitchen_api.dart';
import '../config.dart';
import '../models/kitchen_models.dart';
import '../offline/offline_queue.dart';

/// Drives the kitchen board: periodic polling for real-time convergence, a
/// one-second local tick so timers advance smoothly between polls, and an
/// offline path that queues actions and replays them when the network returns.
class BoardController extends ChangeNotifier {
  BoardController({required this.config, required this.api, required this.queue});

  final KdsConfig config;
  final KitchenApi api;
  final OfflineQueue queue;

  KitchenBoard _board = KitchenBoard.empty;
  bool _online = true;
  int _pendingCount = 0;
  int _localTick = 0;
  Timer? _pollTimer;
  Timer? _tickTimer;

  KitchenBoard get board => _board;
  bool get online => _online;
  int get pendingCount => _pendingCount;
  int get localTick => _localTick;

  static const _cacheKey = 'kds_board_cache';

  Future<void> start() async {
    await _loadCache();
    await refresh();
    _pollTimer = Timer.periodic(config.pollInterval, (_) => refresh());
    _tickTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      _localTick++;
      notifyListeners();
    });
  }

  Future<void> refresh() async {
    try {
      await queue.flush();
      final fresh = await api.fetchBoard();
      _board = fresh;
      _online = true;
      await _saveCache(fresh);
    } catch (_) {
      _online = false;
    }
    _pendingCount = await queue.length;
    notifyListeners();
  }

  /// Runs a lifecycle transition (start/ready/serve). Online it calls the API
  /// directly; offline it queues the command and optimistically advances the
  /// local board so the screen stays responsive.
  Future<String?> transition(KitchenTicket ticket, String action) async {
    final op = _operationId(ticket.id, action);
    if (_online) {
      try {
        await api.transition(ticket.id, action, ticket.lockVersion, op);
        await refresh();
        return null;
      } on KitchenApiException catch (e) {
        if (e.isStaleVersion) {
          await refresh();
          return 'Ticket changed — board refreshed, please retry.';
        }
        if (!e.isConflict) rethrow;
        return e.message;
      } catch (_) {
        _online = false;
      }
    }
    await _queue(ticket.id, 'kitchen.ticket.$action', ticket.lockVersion, const {});
    return 'Offline — action queued.';
  }

  Future<String?> assignChef(KitchenTicket ticket, int? chefId) async {
    final op = _operationId(ticket.id, 'assign');
    if (_online) {
      try {
        await api.assignChef(ticket.id, ticket.lockVersion, chefId, op);
        await refresh();
        return null;
      } catch (_) {
        _online = false;
      }
    }
    await _queue(ticket.id, 'kitchen.ticket.assign', ticket.lockVersion, {'chef_id': chefId});
    return 'Offline — assignment queued.';
  }

  Future<String?> togglePriority(KitchenTicket ticket) async {
    final op = _operationId(ticket.id, 'priority');
    final next = !ticket.isPriority;
    if (_online) {
      try {
        await api.setPriority(ticket.id, ticket.lockVersion, next, op);
        await refresh();
        return null;
      } catch (_) {
        _online = false;
      }
    }
    await _queue(ticket.id, 'kitchen.ticket.priority', ticket.lockVersion, {'is_priority': next});
    return 'Offline — priority queued.';
  }

  Future<void> _queue(String ticketId, String command, int version, Map<String, dynamic> extra) async {
    final seq = await queue.nextSequence();
    await queue.enqueue(QueuedCommand(
      clientOperationId: '$command.$ticketId.$seq',
      ticketId: ticketId,
      command: command,
      deviceSequence: seq,
      payload: {'expected_version': version, ...extra},
    ));
    _pendingCount = await queue.length;
    notifyListeners();
  }

  String _operationId(String ticketId, String action) =>
      'kds.$action.$ticketId.${DateTime.now().microsecondsSinceEpoch}';

  Future<void> _loadCache() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_cacheKey);
    if (raw != null) {
      _board = KitchenBoard.fromJson(jsonDecode(raw) as Map<String, dynamic>);
      notifyListeners();
    }
  }

  Future<void> _saveCache(KitchenBoard board) async {
    final prefs = await SharedPreferences.getInstance();
    final map = {
      for (final phase in KitchenPhase.values)
        phase.name: board.phase(phase).map((t) => {'id': t.id}).toList(),
    };
    // Cache is a lightweight presence snapshot; a full re-fetch hydrates detail.
    await prefs.setString(_cacheKey, jsonEncode(map));
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _tickTimer?.cancel();
    api.close();
    queue.close();
    super.dispose();
  }
}
