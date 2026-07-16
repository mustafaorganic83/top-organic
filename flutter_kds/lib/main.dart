import 'package:flutter/material.dart';

import 'api/kitchen_api.dart';
import 'config.dart';
import 'offline/offline_queue.dart';
import 'state/board_controller.dart';
import 'ui/board_screen.dart';

void main() {
  final config = KdsConfig.dev();
  runApp(KdsApp(config: config));
}

class KdsApp extends StatelessWidget {
  KdsApp({super.key, required this.config})
      : controller = BoardController(
          config: config,
          api: KitchenApi(config),
          queue: OfflineQueue(config),
        );

  final KdsConfig config;
  final BoardController controller;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Kitchen Display System',
      theme: ThemeData(
        colorSchemeSeed: Colors.teal,
        useMaterial3: true,
      ),
      home: BoardScreen(controller: controller),
    );
  }
}
