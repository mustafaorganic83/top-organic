import '../models/kitchen_models.dart';

/// Formats the live kitchen timer. The server supplies the elapsed baseline at
/// each poll; [localTick] advances it by whole seconds between polls so the
/// display counts up smoothly without hammering the API.
class TimerView {
  const TimerView({required this.text, required this.overdue});

  final String text;
  final bool overdue;
}

TimerView formatTimer(KitchenTicket ticket, int localTick) {
  final timer = ticket.timer;
  // Served tickets show their captured prep time; live tickets count up.
  final base = ticket.phase == KitchenPhase.served ? timer.prepSeconds : timer.elapsedSeconds;
  if (base == null) {
    return const TimerView(text: '--:--', overdue: false);
  }
  final seconds = ticket.phase == KitchenPhase.served ? base : base + localTick;
  final sla = timer.slaSeconds;
  final overdue = sla != null && ticket.phase != KitchenPhase.served && seconds > sla;
  return TimerView(text: _clock(seconds), overdue: overdue || timer.isOverdue);
}

String _clock(int totalSeconds) {
  final s = totalSeconds < 0 ? 0 : totalSeconds;
  final minutes = (s ~/ 60).toString().padLeft(2, '0');
  final secs = (s % 60).toString().padLeft(2, '0');
  return '$minutes:$secs';
}
