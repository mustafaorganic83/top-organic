import 'package:flutter/material.dart';

import '../models/kitchen_models.dart';
import '../state/board_controller.dart';
import 'ticket_card.dart';

/// The kitchen board: four phase columns rendered side by side, each a live
/// queue of ticket cards. An online/offline banner surfaces sync state and the
/// number of queued offline actions.
class BoardScreen extends StatefulWidget {
  const BoardScreen({super.key, required this.controller});

  final BoardController controller;

  @override
  State<BoardScreen> createState() => _BoardScreenState();
}

class _BoardScreenState extends State<BoardScreen> {
  @override
  void initState() {
    super.initState();
    widget.controller.start();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.controller,
      builder: (context, _) {
        final c = widget.controller;
        return Scaffold(
          appBar: AppBar(
            title: const Text('Kitchen Display'),
            actions: [
              _StatusChip(online: c.online, pending: c.pendingCount),
              IconButton(icon: const Icon(Icons.refresh), onPressed: c.refresh),
            ],
          ),
          body: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (final phase in KitchenPhase.values)
                Expanded(child: _PhaseColumn(controller: c, phase: phase)),
            ],
          ),
        );
      },
    );
  }
}

class _PhaseColumn extends StatelessWidget {
  const _PhaseColumn({required this.controller, required this.phase});

  final BoardController controller;
  final KitchenPhase phase;

  @override
  Widget build(BuildContext context) {
    final tickets = controller.board.phase(phase);
    return Column(
      children: [
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(10),
          color: Theme.of(context).colorScheme.surfaceContainerHighest,
          child: Text('${phase.label} (${tickets.length})',
              textAlign: TextAlign.center,
              style: const TextStyle(fontWeight: FontWeight.bold)),
        ),
        Expanded(
          child: ListView.builder(
            itemCount: tickets.length,
            itemBuilder: (context, i) {
              final ticket = tickets[i];
              return TicketCard(
                ticket: ticket,
                localTick: controller.localTick,
                onAdvance: () => _run(context, controller.transition(ticket, _action(phase))),
                onTogglePriority: () => _run(context, controller.togglePriority(ticket)),
                onAssign: () => _assign(context, ticket),
              );
            },
          ),
        ),
      ],
    );
  }

  String _action(KitchenPhase p) => switch (p) {
        KitchenPhase.preparation => 'start',
        KitchenPhase.cooking => 'ready',
        KitchenPhase.ready => 'serve',
        KitchenPhase.served => 'serve',
      };

  Future<void> _assign(BuildContext context, KitchenTicket ticket) async {
    final result = await showDialog<int?>(
      context: context,
      builder: (context) {
        final field = TextEditingController(text: ticket.chefId?.toString() ?? '');
        return AlertDialog(
          title: const Text('Assign chef'),
          content: TextField(
            controller: field,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Chef user ID (empty to clear)'),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, ticket.chefId), child: const Text('Cancel')),
            FilledButton(
              onPressed: () => Navigator.pop(context, int.tryParse(field.text.trim())),
              child: const Text('Save'),
            ),
          ],
        );
      },
    );
    if (context.mounted) _run(context, controller.assignChef(ticket, result));
  }

  Future<void> _run(BuildContext context, Future<String?> future) async {
    final message = await future;
    if (message != null && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    }
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.online, required this.pending});

  final bool online;
  final int pending;

  @override
  Widget build(BuildContext context) {
    final label = online ? 'Online' : 'Offline${pending > 0 ? ' · $pending queued' : ''}';
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Chip(
          avatar: Icon(online ? Icons.cloud_done : Icons.cloud_off,
              size: 16, color: online ? Colors.green : Colors.orange),
          label: Text(label),
        ),
      ),
    );
  }
}
