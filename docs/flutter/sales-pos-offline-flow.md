# Flutter Sales POS — Offline Flow (Architecture Contract)

> **Note:** No Flutter application exists yet. This document specifies the required architecture, data model, and API-integration flow that a Flutter POS app must implement based on the implemented Laravel backend contract. It is a flow/contract document, not a description of existing code.

---

## 1. Secure bootstrap and device identity

A Flutter POS app must never store API access JWTs in plain text. Use the platform secure enclave:
- **Android:** `EncryptedSharedPreferences` or `flutter_secure_storage`.
- **iOS/desktop:** Keychain / OS credential store via `flutter_secure_storage`.

Never log token values, gift-card tokens, or any field in the prohibited list (`pan`, `card_number`, `cvv`, `cvc`, `password`, `provider_reference`, `provider_snapshot`, etc.).

### Bootstrap sequence

1. On first launch, register the device via `POST /api/v1/devices/register` (device type `pos` or `desktop`).
2. Wait for approval (`status: authorized`) via polling `GET /api/v1/admin/devices/{device}` or a WebSocket push.
3. Log in with `tenant_slug + identifier + password + branch_id + device_id` → store `access_token` + `refresh_token` in secure storage.
4. Optionally request an offline grant via `POST /api/v1/offline-grants` → store the `grant_token` (offline JWT) separately in secure storage.
5. Call `GET /api/v1/me` to confirm tenant/branch/device context and load permissions.

---

## 2. Local SQLite schema

The app must mirror the server entities it works with offline. Recommended tables:

| Table | Key columns | Notes |
|-------|------------|-------|
| `orders` | `id TEXT PK`, `lock_version INTEGER`, `state TEXT`, `currency TEXT`, `total_amount INTEGER`, ... | Full order fields; no float columns |
| `order_items` | `id TEXT PK`, `order_id TEXT`, `lock_version INTEGER`, ... | Items with `quantity TEXT` (canonical decimal) |
| `table_sessions` | `id TEXT PK`, `dining_table_id TEXT`, `state TEXT`, `lock_version INTEGER` | |
| `kds_tickets` | `id TEXT PK`, `order_id TEXT`, `state TEXT`, `lock_version INTEGER` | |
| `customers` | `id TEXT PK`, `lock_version INTEGER` | No raw phone/email; store display-safe fields only |
| `sync_outbox` | `id TEXT PK`, `client_operation_id TEXT UNIQUE`, `entity_type TEXT`, `entity_id TEXT`, `command TEXT`, `device_sequence INTEGER`, `payload TEXT (JSON)`, `status TEXT`, `created_at INTEGER` | Local command queue |
| `sync_cursor` | `stream TEXT PK`, `last_sequence INTEGER` | Pull high-water marks |
| `sync_conflicts` | `id TEXT PK`, `entity_type TEXT`, `entity_id TEXT`, `conflict_type TEXT`, `state TEXT` | Quarantined conflicts |
| `device_state` | `key TEXT PK`, `value TEXT` | e.g. next_device_sequence, last_batch_id |

**Money rule:** All amount columns are `INTEGER` (minor units). Never use `REAL` / `DOUBLE` / `FLOAT`.
**Quantity rule:** All quantity columns are `TEXT` storing the canonical decimal string.

---

## 3. ULID generation and operation IDs

Generate ULIDs client-side for all new entity IDs and `client_batch_id`. Use a monotonic ULID generator (millisecond-timestamp-prefix + random 80 bits). Dart package: `ulid` or equivalent.

`client_operation_id` format: `<device-code>:<YYYY-MM-DD>:<seq-hex>` or any stable string matching `[A-Za-z0-9._:-]+` (max 128 chars) that is **globally unique per device** and **deterministic for a given intent** (so that a crash-and-retry produces the same ID).

---

## 4. Command queue and contiguous sequence assignment

The `sync_outbox` table is the offline command queue. Rules:

1. Every mutating POS action writes a row to `sync_outbox` **inside the same SQLite transaction** as the local state update.
2. `device_sequence` must be assigned atomically from a monotonically increasing counter persisted in `device_state`. Never skip or reuse a sequence number.
3. The queue is ordered by `device_sequence` ascending.
4. When online, flush the queue in batches of ≤ 200 operations via `POST /api/v1/sales/sync/push`.
5. On push success, mark flushed rows as `synced`; update local `lock_version` from `entity_revision` in each result.
6. On `conflict` result: move the row to `sync_conflicts` (display to user); do not retry automatically.
7. On `rejected` result: mark row as `rejected` and log a non-sensitive diagnostic (command name only).
8. On `409 SALES_INVALID_STATE` with `expected_sequence`: see sequence gap recovery in `offline-sync-v1.md`.

---

## 5. Online POS flow

When the network is available and the API access JWT is valid:

1. **Shift open:** `POST /pos/shifts` → store shift `id` and `lock_version` locally.
2. **Drawer open:** `POST /pos/drawers/sessions` → store drawer session locally.
3. **Table session:** `POST /pos/table-sessions` if dine-in.
4. **Order creation:** `POST /sales/orders` with `client_operation_id`; store returned order and `lock_version`.
5. **Add/update/remove items:** call respective endpoints; always pass `expected_version`; update local `lock_version` on success.
6. **Place order:** `POST /sales/orders/{order}/place`.
7. **Payment capture:** `POST /sales/billing/payments` with unique `idempotency_key`; store payment record.
8. **Print:** `POST /sales/printing/jobs`; edge device claims via `POST /sales/printing/edge/jobs/claim`.

Every mutating call that requires `pos.device` must include the active device context (resolved from the JWT by the server; not sent by the client).

---

## 6. Offline order, table, cash, and payment flow

When the device is offline, write operations to `sync_outbox` instead of calling the API:

### Offline order creation
```
1. Generate ULID → entity_id
2. Write sync_outbox row:
   command = "order.create"
   entity_type = "order"
   entity_id = <new-ulid>
   device_sequence = next_sequence()
   payload = { type, currency, table_session_id, pos_shift_id, ... }
   (no tenant_id, branch_id, device_id, floats, or sensitive keys)
3. Insert optimistic order row in local `orders` table with state="draft"
```

### Offline item add
```
command = "order.item.add"
entity_id = <order_id>
payload = { expected_version: <local_lock_version>, variant_id, quantity: "2", modifiers: [...], channel: "pos" }
```
After writing, increment local `lock_version` optimistically.

### Offline payment
```
command = "payment.capture"
entity_id = <order_id>
payload = { expected_version, payment_method_id, amount, idempotency_key }
```
Only cash or `supports_offline=true` methods. Never attempt offline capture with gift-card payment method.

### Offline table open/close
```
command = "pos.table.open" / entity_id = <table_id>   payload = { table_id, guest_count }
command = "pos.table.close" / entity_id = <table_session_id>   payload = { expected_version }
```

### Offline cash movement
```
command = "pos.cash.movement" / entity_id = <drawer_session_id>
payload = { type, amount, currency, reason }
```

---

## 7. Push / replay / conflict / pull / ack loop

```
while (online && outbox not empty):
    batch = outbox.take(≤200, ordered by device_sequence)
    client_batch_id = new ULID
    response = POST /sales/sync/push { client_batch_id, operations: batch }
    for result in response.results:
        if result.result in [applied, duplicate]:
            mark outbox row synced
            update local lock_version = result.entity_revision
        elif result.result == conflict:
            quarantine outbox row; save to sync_conflicts
        elif result.result == rejected:
            mark outbox row rejected; log command name

while (online):
    cursor = sync_cursor.get(stream="default")
    response = GET /sales/sync/pull?cursor={cursor}&limit=100
    for entry in response.entries:
        apply snapshot to local table (upsert by entity_id)
    for tombstone in response.tombstones:
        delete local row for tombstone.entity_id + entity_type
    POST /sales/sync/cursor { stream: "default", sequence: response.cursor }
    if not response.has_more: break
```

Pull after successful push to ensure local state reflects any server-side changes triggered by the pushed commands.

---

## 8. Resync UX

When pull returns `409 SALES_RESYNC_REQUIRED`:

1. Show a non-dismissable dialog: "Your device data is out of sync. Reconnecting will refresh all local data."
2. Require explicit user confirmation before proceeding.
3. Clear stale local entity tables only after preserving the unsent outbox.
4. Reset `sync_cursor.last_sequence = 0`.
5. Pull from cursor 0 to `has_more: false`; apply retained snapshots, then refresh authoritative catalog/customer/reference lists through their read APIs where needed.
6. `POST /sales/sync/cursor { stream: "default", sequence: <final_cursor>, resync: true }`.
7. Resume normal operation.

Do **not** clear `sync_outbox` during resync — unsent commands may still apply after resync. Resolve sequence conflicts per the gap-recovery algorithm.

---

## 9. KDS integration

The KDS screen is primarily edge-driven. Recommended pattern:

1. Load initial ticket queue: `GET /sales/kds/tickets?state=queued` (paginated).
2. Subscribe to WebSocket channel for real-time ticket updates (if Reverb/Echo is available).
3. On ticket action (start/ready/bump/recall):
   - If online: call the endpoint directly with `expected_version` and `client_operation_id`.
   - The KDS state machine is strict (wrong-state transitions return 409); the UI must reflect current state.
4. Dispatch: `POST /sales/kds/dispatch` requires `pos.device:edge` — only edge-type devices (pos, desktop) can dispatch.
5. KDS transitions through offline sync are **not supported** — KDS actions are not in the offline command allow-list. KDS devices must remain online.

---

## 10. Edge print integration

1. Edge device polls `POST /sales/printing/edge/jobs/claim` (requires `pos.device:edge`).
2. If `data: null`, no pending jobs — wait and retry.
3. If a job is returned, render the `payload` field according to the edge-print protocol:
   - `protocol: "top-organic.edge-print"`, `version: 1`.
   - `payload_type` determines the document shape.
   - For `qr_verification`: generate QR from `document.claims`; display/print `document.signature` for in-store verification.
   - For `barcode_label`: use `document.barcode` and `document.sku`.
4. On print success: `POST /sales/printing/edge/jobs/{job}/complete { "expected_version": N }`.
5. On failure: `POST /sales/printing/edge/jobs/{job}/fail { "expected_version": N, "error_code": "PRINTER_OFFLINE", "error_message": "..." }`.
6. On transient failure with retry: `POST /sales/printing/edge/jobs/{job}/retry { "expected_version": N }` (only for `state=failed`, `attempt_count < 5`).

Never reconstruct or sign QR verification claims on the client. Always use the server-provided `document` payload.

---

## 11. Error handling and state management

### Repository interface (recommended)

```dart
abstract class OrderRepository {
  Future<Order> create(CreateOrderCommand cmd);
  Future<Order> addItem(String orderId, AddItemCommand cmd);
  // ... other mutating methods
  Future<void> queueOffline(SyncOperation op);  // writes to sync_outbox
}
```

Implement a `ConnectivityAwareOrderRepository` that delegates online calls to the API client and offline calls to the local SQLite + outbox. The connectivity state is determined by a `ConnectivityService` (check network + API reachability).

### Stale version handling

On `409 SALES_STALE_VERSION` from a direct API call:
1. Re-fetch the entity (`GET /sales/orders/{id}` etc.) to get the latest `lock_version`.
2. Re-apply the user's intent on top of the fresh state.
3. Retry the operation once. If it fails again, surface to the user.

### Gift card token

- Store gift card token only in memory during the issuance session.
- Never persist the raw token to SQLite, SharedPreferences, or logs.
- Use `token_last4` for display; always re-enter the full token for balance checks and redemptions.

---

## 12. State management approach

Recommended pattern (BLoC / Riverpod / similar):

- Each screen has a `Cubit` or `StateNotifier` that holds a `sealed class` state (loading / loaded / error).
- Mutating actions dispatch to the repository; optimistic local updates are applied immediately; API success confirms; API failure reverts.
- The sync loop runs as a background isolate or periodic timer; it publishes events to a shared `SyncStatusNotifier`.
- Conflict count is surfaced as a badge on a "Sync Issues" screen accessible from the POS home.

---

## 13. What is explicitly not supported

- No Flutter app or generated code exists; this is a contract specification only.
- No PDF renderer: the backend does not generate PDF; printing uses the edge-print JSON protocol.
- No network event publisher: the outbox relay is a server-side background worker.
- Gift-card offline capture is rejected by the server and must not be offered in the UI when offline.
- KDS state transitions offline are not in the allow-list and will be rejected by the server.
- The `grant_token` (offline JWT) is a separate credential from the API access token and must not be used as an API `Authorization: Bearer` token.
