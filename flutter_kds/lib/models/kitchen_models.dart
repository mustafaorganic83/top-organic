// Domain models mirroring the Kitchen API payloads. Kept as immutable value
// objects with tolerant JSON parsing so a partial/cached payload never
// crashes the board.

/// The four board phases, in kitchen flow order.
enum KitchenPhase { preparation, cooking, ready, served }

extension KitchenPhaseX on KitchenPhase {
  String get wire => name;
  String get label => switch (this) {
        KitchenPhase.preparation => 'Preparation',
        KitchenPhase.cooking => 'Cooking',
        KitchenPhase.ready => 'Ready',
        KitchenPhase.served => 'Served',
      };
}

class KitchenTimer {
  const KitchenTimer({this.slaSeconds, this.elapsedSeconds, this.prepSeconds, this.isOverdue = false});

  final int? slaSeconds;
  final int? elapsedSeconds;
  final int? prepSeconds;
  final bool isOverdue;

  factory KitchenTimer.fromJson(Map<String, dynamic> j) => KitchenTimer(
        slaSeconds: j['sla_seconds'] as int?,
        elapsedSeconds: j['elapsed_seconds'] as int?,
        prepSeconds: j['prep_seconds'] as int?,
        isOverdue: j['is_overdue'] == true,
      );
}

class KitchenItem {
  const KitchenItem({required this.id, required this.name, required this.quantity, required this.state});

  final String id;
  final String name;
  final String quantity;
  final String state;

  factory KitchenItem.fromJson(Map<String, dynamic> j) {
    final prep = (j['preparation'] as Map<String, dynamic>?) ?? const {};
    return KitchenItem(
      id: j['id'] as String? ?? '',
      name: prep['name'] as String? ?? 'Item',
      quantity: '${j['quantity'] ?? ''}',
      state: j['state'] as String? ?? 'queued',
    );
  }
}

class KitchenTicket {
  const KitchenTicket({
    required this.id,
    required this.number,
    required this.state,
    required this.phase,
    required this.priority,
    required this.isPriority,
    required this.lockVersion,
    required this.timer,
    required this.items,
    this.chefId,
    this.chefName,
    this.stationName,
  });

  final String id;
  final String number;
  final String state;
  final KitchenPhase phase;
  final int priority;
  final bool isPriority;
  final int lockVersion;
  final KitchenTimer timer;
  final List<KitchenItem> items;
  final int? chefId;
  final String? chefName;
  final String? stationName;

  factory KitchenTicket.fromJson(Map<String, dynamic> j) {
    final phaseName = j['phase'] as String? ?? 'preparation';
    return KitchenTicket(
      id: j['id'] as String? ?? '',
      number: j['number'] as String? ?? '',
      state: j['state'] as String? ?? 'queued',
      phase: KitchenPhase.values.firstWhere(
        (p) => p.name == phaseName,
        orElse: () => KitchenPhase.preparation,
      ),
      priority: j['priority'] as int? ?? 100,
      isPriority: j['is_priority'] == true,
      lockVersion: j['lock_version'] as int? ?? 0,
      timer: KitchenTimer.fromJson((j['timer'] as Map<String, dynamic>?) ?? const {}),
      items: ((j['items'] as List<dynamic>?) ?? const [])
          .map((e) => KitchenItem.fromJson(e as Map<String, dynamic>))
          .toList(),
      chefId: j['chef_id'] as int?,
      chefName: j['chef_name'] as String?,
      stationName: (j['station'] as Map<String, dynamic>?)?['name'] as String?,
    );
  }
}

class KitchenStation {
  const KitchenStation({required this.id, required this.code, required this.name, required this.stationType});

  final String id;
  final String code;
  final String name;
  final String stationType;

  factory KitchenStation.fromJson(Map<String, dynamic> j) => KitchenStation(
        id: j['id'] as String? ?? '',
        code: j['code'] as String? ?? '',
        name: j['name'] as String? ?? '',
        stationType: j['station_type'] as String? ?? 'kitchen',
      );
}

/// The full board keyed by phase.
class KitchenBoard {
  const KitchenBoard(this.byPhase);

  final Map<KitchenPhase, List<KitchenTicket>> byPhase;

  List<KitchenTicket> phase(KitchenPhase p) => byPhase[p] ?? const [];

  factory KitchenBoard.fromJson(Map<String, dynamic> j) {
    final map = <KitchenPhase, List<KitchenTicket>>{};
    for (final phase in KitchenPhase.values) {
      final list = (j[phase.name] as List<dynamic>?) ?? const [];
      map[phase] = list
          .map((e) => KitchenTicket.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();
    }
    return KitchenBoard(map);
  }

  static const KitchenBoard empty = KitchenBoard({});
}
