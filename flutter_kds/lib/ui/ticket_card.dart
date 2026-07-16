import 'package:flutter/material.dart';

import '../models/kitchen_models.dart';
import 'timer_format.dart';

/// A single ticket on the board. Shows the number, station, chef, items, and
/// the live timer, plus the contextual action that advances it to the next
/// phase. Overdue tickets are highlighted; priority tickets carry a flag.
class TicketCard extends StatelessWidget {
  const TicketCard({
    super.key,
    required this.ticket,
    required this.localTick,
    required this.onAdvance,
    required this.onTogglePriority,
    required this.onAssign,
  });

  final KitchenTicket ticket;
  final int localTick;
  final VoidCallback? onAdvance;
  final VoidCallback onTogglePriority;
  final VoidCallback onAssign;

  static const _nextAction = {
    KitchenPhase.preparation: ('start', 'Start'),
    KitchenPhase.cooking: ('ready', 'Ready'),
    KitchenPhase.ready: ('serve', 'Serve'),
  };

  @override
  Widget build(BuildContext context) {
    final timer = formatTimer(ticket, localTick);
    final action = _nextAction[ticket.phase];
    return Card(
      margin: const EdgeInsets.all(6),
      color: timer.overdue ? Colors.red.shade50 : null,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(
          color: ticket.isPriority ? Colors.orange : Colors.transparent,
          width: 2,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('#${ticket.number}',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                ),
                if (ticket.isPriority) const Icon(Icons.priority_high, color: Colors.orange, size: 18),
                Text(
                  timer.text,
                  style: TextStyle(
                    fontFeatures: const [FontFeature.tabularFigures()],
                    color: timer.overdue ? Colors.red : Colors.black87,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
            if (ticket.stationName != null)
              Text(ticket.stationName!, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 4),
            ...ticket.items.map((i) => Text('${i.quantity} × ${i.name}',
                style: const TextStyle(fontSize: 13))),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: Text(
                    ticket.chefName ?? 'Unassigned',
                    style: TextStyle(
                      fontStyle: ticket.chefName == null ? FontStyle.italic : FontStyle.normal,
                      fontSize: 12,
                    ),
                  ),
                ),
                IconButton(
                  tooltip: 'Assign chef',
                  icon: const Icon(Icons.person_add_alt, size: 18),
                  onPressed: onAssign,
                ),
                IconButton(
                  tooltip: 'Toggle priority',
                  icon: Icon(ticket.isPriority ? Icons.flag : Icons.outlined_flag, size: 18),
                  onPressed: onTogglePriority,
                ),
              ],
            ),
            if (action != null)
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: onAdvance,
                  child: Text(action.$2),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
