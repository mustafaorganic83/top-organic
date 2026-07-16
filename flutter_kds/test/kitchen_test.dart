import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kds/models/kitchen_models.dart';
import 'package:kds/ui/ticket_card.dart';
import 'package:kds/ui/timer_format.dart';

void main() {
  group('KitchenBoard parsing', () {
    test('groups tickets into all four phases', () {
      final board = KitchenBoard.fromJson({
        'preparation': [
          {
            'id': '01hx', 'number': 'KDS-1', 'state': 'queued', 'phase': 'preparation',
            'priority': 100, 'is_priority': false, 'lock_version': 0,
            'timer': {'sla_seconds': 600, 'elapsed_seconds': 30, 'is_overdue': false},
            'items': [
              {'id': 'i1', 'quantity': '2.000000', 'state': 'queued', 'preparation': {'name': 'Burger'}}
            ],
          }
        ],
        'cooking': [],
        'ready': [],
        'served': [],
      });

      expect(board.phase(KitchenPhase.preparation), hasLength(1));
      expect(board.phase(KitchenPhase.cooking), isEmpty);
      final ticket = board.phase(KitchenPhase.preparation).first;
      expect(ticket.number, 'KDS-1');
      expect(ticket.items.first.name, 'Burger');
      expect(ticket.timer.slaSeconds, 600);
    });

    test('tolerates missing fields', () {
      final board = KitchenBoard.fromJson({'preparation': [{}]});
      final ticket = board.phase(KitchenPhase.preparation).first;
      expect(ticket.phase, KitchenPhase.preparation);
      expect(ticket.lockVersion, 0);
    });
  });

  group('formatTimer', () {
    KitchenTicket make(KitchenPhase phase, KitchenTimer timer) => KitchenTicket(
          id: 't', number: 'N', state: 's', phase: phase, priority: 100,
          isPriority: false, lockVersion: 0, timer: timer, items: const [],
        );

    test('counts up from elapsed baseline plus local tick', () {
      final view = formatTimer(
        make(KitchenPhase.cooking, const KitchenTimer(slaSeconds: 300, elapsedSeconds: 65)),
        5,
      );
      expect(view.text, '01:10');
      expect(view.overdue, isFalse);
    });

    test('flags overdue when past SLA', () {
      final view = formatTimer(
        make(KitchenPhase.cooking, const KitchenTimer(slaSeconds: 60, elapsedSeconds: 61)),
        0,
      );
      expect(view.overdue, isTrue);
    });

    test('served ticket shows captured prep time and is never overdue', () {
      final view = formatTimer(
        make(KitchenPhase.served, const KitchenTimer(slaSeconds: 60, prepSeconds: 125)),
        99,
      );
      expect(view.text, '02:05');
      expect(view.overdue, isFalse);
    });
  });

  testWidgets('TicketCard shows number, item and advance action', (tester) async {
    const ticket = KitchenTicket(
      id: 't1', number: 'KDS-9', state: 'queued', phase: KitchenPhase.preparation,
      priority: 100, isPriority: true, lockVersion: 3,
      timer: KitchenTimer(slaSeconds: 600, elapsedSeconds: 10),
      items: [KitchenItem(id: 'i', name: 'Pizza', quantity: '1.000000', state: 'queued')],
    );

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: TicketCard(
          ticket: ticket,
          localTick: 0,
          onAdvance: () {},
          onTogglePriority: () {},
          onAssign: () {},
        ),
      ),
    ));

    expect(find.text('#KDS-9'), findsOneWidget);
    expect(find.textContaining('Pizza'), findsOneWidget);
    expect(find.text('Start'), findsOneWidget);
    expect(find.byIcon(Icons.priority_high), findsOneWidget);
  });
}
