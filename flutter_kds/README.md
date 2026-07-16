# Kitchen Display System (Flutter)

Tablet/kitchen-screen client for the Top Organic Kitchen Management API
(`/api/v1/kitchen/*`).

## Features

- **Station board** with the four phase columns — Preparation, Cooking,
  Ready, Served — mirroring the backend queues.
- **Live kitchen timer** per ticket: elapsed time, SLA, and an overdue
  highlight, refreshed every second locally and reconciled from the server.
- **Chef assignment** and **priority** flagging from the ticket card.
- **Lifecycle actions**: start → ready → serve, each optimistic-locked with
  `expected_version` and an idempotent `client_operation_id`.
- **Offline operation**: the last board is cached; actions taken offline are
  queued and replayed against `/api/v1/sales/sync/push` when connectivity
  returns, using the same `kitchen.ticket.*` commands the backend allows.
- **Real-time synchronization**: the board polls on a short interval and after
  every action, so multiple screens converge.

## Configuration

Set the API base URL and bearer token at runtime via `KdsConfig`
(see `lib/config.dart`). The token is obtained from
`POST /api/v1/auth/login` with a branch and an authorized device.

## Run

```
cd flutter_kds
flutter pub get
flutter run
```

## Test

```
flutter test
```

## Layout

- `lib/config.dart` — runtime configuration.
- `lib/models/` — `KitchenTicket`, `KitchenStation`, board + timer models.
- `lib/api/kitchen_api.dart` — typed HTTP client.
- `lib/offline/offline_queue.dart` — durable command queue + replay.
- `lib/state/board_controller.dart` — polling, local timers, offline merge.
- `lib/ui/` — board screen and ticket card widgets.
