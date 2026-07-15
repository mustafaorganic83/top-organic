# Offline Sync API v1

All sync routes live under `/api/v1/sales/sync` and require API bearer auth, a trusted `tenant + branch` context, and an authorized `pos.device`. Scope fields are prohibited in every payload.

---

## Overview

The sync layer lets branch-edge devices operate fully offline and converge asynchronously with the server. The protocol has three phases:

1. **Push** — the edge replays its offline command queue to the server in batches.
2. **Pull** — the edge polls for server-side changes (change-log entries and tombstones) via a cursor-based feed.
3. **Cursor acknowledgement** — the edge records its durable pull high-water mark so recovery starts at the right point.

---

## Push

### `POST /api/v1/sales/sync/push`

Permission: `sales.sync.push` + `pos.device`. Returns `200`.

#### Request

```json
{
  "client_batch_id": "01J...",
  "schema_version": 1,
  "operations": [
    {
      "client_operation_id": "POS-01:2026-07-15:seq-001",
      "entity_type": "order",
      "entity_id": "01J...",
      "command": "order.create",
      "device_sequence": 1,
      "logical_clock": 0,
      "payload": {
        "type": "dine_in",
        "currency": "IQD",
        "table_session_id": "01J..."
      }
    }
  ]
}
```

| Field | Type | Constraints |
|-------|------|------------|
| `client_batch_id` | ULID | Required; unique per device per batch |
| `schema_version` | integer | Optional; min 1 |
| `operations` | array | 1–200 items (`push_batch_limit`) |
| `operations[].client_operation_id` | string | Required; `[A-Za-z0-9._:-]+`, max 128 chars |
| `operations[].entity_type` | string | Required; max 64 chars |
| `operations[].entity_id` | ULID | Required |
| `operations[].command` | string | Required; `[a-z][a-z0-9_.]{1,63}` (lowercase) |
| `operations[].device_sequence` | integer | Required; 1–9223372036854775807; **contiguous per device** |
| `operations[].logical_clock` | integer | Optional; 0–9223372036854775807 |
| `operations[].payload` | object | Optional; see payload safety rules |

#### Payload safety rules

The server validates every push payload recursively and rejects (422) if:
- Any value is a **floating-point number**. All money must be integers; all quantities must be strings.
- Any key (case-insensitive) is one of: `tenant_id`, `branch_id`, `device_id`, `actor_id`, `user_id`, `approved_by`, `pan`, `card_number`, `cvv`, `cvc`, `password`, `token`, `provider_reference`, `provider_snapshot`.

#### Device sequence contiguity

Operations in a batch must have **unique, positive** `device_sequence` values. Across batches, sequences must be strictly contiguous: if the device's last acknowledged sequence is `N`, the next batch must start at `N+1`. A gap returns `409 SALES_INVALID_STATE` with `{ "expected_sequence": N+1 }`.

Within a batch the server sorts sequences and applies them in order. The server advances the device's sequence tracker atomically after the entire batch succeeds.

#### Idempotent batch replay

If `client_batch_id` was already processed:
- If `operation_count` matches: returns stored receipts with `result: "duplicate"`.
- If `operation_count` differs: returns `409 SALES_IDEMPOTENCY_CONFLICT`.

An individual `client_operation_id` seen before:
- Fingerprint matches: returns stored receipt (may be part of a fresh batch).
- Fingerprint differs: returns `409 SALES_IDEMPOTENCY_CONFLICT`.

#### Fingerprint

The fingerprint is computed as:
```
SHA-256(entity_type + "|" + entity_id + "|" + command + "|" + device_sequence + "|" + logical_clock + "|" + canonical_json(payload))
```
`canonical_json`: object keys are sorted recursively; lists preserve order; no float values. The client must compute and store the same fingerprint to detect replay conflicts early.

#### Operation outcomes

| `result` | `result_code` | Meaning |
|----------|--------------|---------|
| `applied` | command name | Operation applied; `entity_revision` is the new `lock_version` |
| `duplicate` | original code | Previously applied; same outcome returned |
| `conflict` | `SALES_STALE_VERSION`, `SALES_TERMINAL_ORDER`, or `SALES_INVALID_STATE` | Not applied; quarantined for review while sibling operations continue |
| `rejected` | `SALES_SCOPE_VIOLATION` | Command not in the offline allow-list; not applied |

**All-or-nothing vs. durable outcomes:** The batch runs in a single database transaction. An unrecoverable error (not stale-version/terminal/invalid-state) aborts the entire batch. Conflicts and rejections are **durable** outcomes: they do not abort sibling operations; they are recorded and returned as per-operation results. The batch state becomes `applied` regardless.

#### Allowed commands (offline allow-list)

| Command | Entity type |
|---------|------------|
| `order.create` | `order` |
| `order.item.add` | `order` |
| `order.item.update` | `order` |
| `order.item.remove` | `order` |
| `order.customer.set` | `order` |
| `order.delivery.set` | `order` |
| `order.place` | `order` |
| `order.state` | `order` |
| `order.charges.replace` | `order` |
| `order.tip.add` | `order` |
| `payment.capture` | `order` |
| `pos.cash.movement` | drawer session |
| `pos.table.open` | table |
| `pos.table.close` | table session |

Any other command is **rejected** (`SALES_SCOPE_VIOLATION`). Rejected operations are not applied and do not abort siblings.

#### Offline payment rules (`payment.capture`)

Payment capture is allowed offline only if:
- Payment method `kind = 'cash'` — always allowed offline.
- Payment method has `supports_offline = true` on the `BranchPaymentMethod` record — allowed offline.
- Payment method `kind = 'gift_card'` — **never** allowed offline (rejected).
- Any other method without `supports_offline` — rejected.

#### Push response

```json
{
  "data": {
    "batch_id": "01J...",
    "results": [
      {
        "client_operation_id": "POS-01:2026-07-15:seq-001",
        "result": "applied",
        "result_code": "order.create",
        "entity_revision": 0,
        "body": { "entity_type": "order", "entity_id": "01J...", "lock_version": 0 }
      }
    ]
  }
}
```

`body` contains only `entity_type`, `entity_id`, and `lock_version`. No payload data, snapshots, or credentials are returned.

---

## Pull

### `GET /api/v1/sales/sync/pull`

Permission: `sales.sync.pull` + `pos.device`. Returns `200`.

Query parameters:

| Param | Type | Default | Constraints |
|-------|------|---------|------------|
| `stream` | string | `default` | `[a-z][a-z0-9_]{1,63}` |
| `cursor` | integer | `0` | 0–9223372036854775807 |
| `limit` | integer | `100` | 1–200 (`pull_page_limit`) |

`cursor = 0` fetches from the beginning of the retained window.

#### Pull response

```json
{
  "data": {
    "entries": [
      {
        "change_sequence": 1001,
        "entity_type": "order",
        "entity_id": "01J...",
        "entity_revision": 2,
        "operation": "update",
        "snapshot": { ... },
        "occurred_at": "2026-07-15T12:01:00Z"
      }
    ],
    "tombstones": [
      {
        "change_sequence": 1002,
        "entity_type": "order_item",
        "entity_id": "01J...",
        "deletion_revision": 3,
        "deleted_at": "2026-07-15T12:02:00Z"
      }
    ],
    "cursor": 1002,
    "has_more": false,
    "server_time": "2026-07-15T12:05:00Z"
  }
}
```

- `entries` are ordered by `change_sequence` ascending.
- `tombstones` cover the same sequence window as `entries` (i.e. `change_sequence > cursor AND <= page_max_sequence`).
- `snapshot` is the current authoritative server state for the entity (same shape as the corresponding API read endpoint). It is `null` if the entity no longer exists or is out of branch scope.
- `has_more: true` means more entries exist; fetch again with the returned `cursor`.

#### Supported entity types in pull snapshots

| `entity_type` | Snapshot shape |
|--------------|----------------|
| `order` | Full order resource (same as `GET /sales/orders/{id}`) |
| `order_item` | `{ id, order_id, line_number, product_variant_id, product_name, variant_name, sku, quantity, unit_price_amount, gross_amount, discount_amount, tax_amount, net_amount, currency, state, course_number, seat_number, notes }` |
| `table_session` | `{ id, dining_table_id, guest_count, state, opened_at, closed_at, lock_version }` |
| `kds_ticket` | Full ticket resource (same as `GET /sales/kds/tickets/{id}`) |
| `print_job` | `{ id, payload_type, document_type, document_id, state, attempt_count, available_at, printed_at, failed_at, lock_version }` |
| `payment` | Full payment resource (same as billing payment) |
| `invoice` | Full invoice resource (same as `GET /sales/billing/invoices/{id}`) |
| `customer` | Full customer resource (same as `GET /sales/customers/{id}`) |
| `product_variant` | `{ id, product_id, name, variant_name, sku, barcode, status }` (only if active in branch catalog) |

Any unrecognized `entity_type` returns `snapshot: null`; the client must tolerate unknown types.

#### Cursor retention and `SALES_RESYNC_REQUIRED`

The server retains change-log entries and tombstones for a rolling window. If `cursor < min(earliest_retained_sequence) - 1`, the server returns `409 SALES_RESYNC_REQUIRED` with:
```json
{ "error": { "code": "SALES_RESYNC_REQUIRED", "details": { "minimum_cursor": 800, "current_cursor": 2050 } } }
```
The client must drop its local state and start a full resync from `cursor=0`.

Tombstone retention: 90 days (`tombstone_retention_days`). Idempotency record retention: 30 days (`idempotency_retention_days`).

---

## Cursor acknowledgement

### `POST /api/v1/sales/sync/cursor`

Permission: `sales.sync.pull` + `pos.device`. Returns `200`.

Records the device's durable pull high-water mark for a stream.

```json
{ "stream": "default", "sequence": 1002, "resync": false }
```

| Field | Rules |
|-------|-------|
| `stream` | Optional; default `default` |
| `sequence` | Required; 0–9223372036854775807 |
| `resync` | Optional boolean; default `false` |

**Regression guard:** Acknowledging a sequence lower than the stored high-water mark returns `409 SALES_STALE_VERSION` unless `resync: true`. Use `resync: true` only when intentionally rewinding (e.g. after a `SALES_RESYNC_REQUIRED` recovery).

Response:
```json
{
  "data": { "stream": "default", "last_sequence": 1002, "state": "active", "last_applied_at": "2026-07-15T12:05:00Z" }
}
```

`state` is `active` (normal) or `resyncing` (cursor was rewound with `resync: true`).

---

## Conflicts

### `GET /api/v1/sales/sync/conflicts`

Permission: `sales.sync.conflicts.view`. Returns `200`. Paginated; only `state = "open"` conflicts for this branch.

### `POST /api/v1/sales/sync/conflicts/{conflict}/resolve`

Permission: `sales.sync.conflicts.resolve`. Returns `200`.

Conflict resolution is **metadata-only**: the server records the human decision but does not re-apply or revert any data. The edge must re-submit corrected operations to achieve the desired state.

Body:
```json
{ "resolution": "discard", "reason": "Cashier confirmed the server state is correct." }
```

`resolution`: `accept_remote | keep_local | discard`.

Conflict response:
```json
{
  "data": {
    "id": "01J...",
    "entity_type": "order",
    "entity_id": "01J...",
    "conflict_type": "SALES_STALE_VERSION",
    "local_revision": 2,
    "remote_revision": 3,
    "risk": "normal",
    "state": "resolved",
    "resolution": "discard",
    "resolved_at": "2026-07-15T13:00:00Z",
    "created_at": "2026-07-15T12:01:00Z"
  }
}
```

`conflict_type` mirrors the `SalesException` error code that caused the conflict: `SALES_STALE_VERSION`, `SALES_TERMINAL_ORDER`, or `SALES_INVALID_STATE`.

---

## Outbox (server-side domain event relay)

The server publishes change-log entries and domain events through an outbox table. Clients do not interact with the outbox directly, but its behaviour affects the consistency guarantee of the pull feed.

| Setting | Value |
|---------|-------|
| Claim limit | 100 events per worker run |
| Claim timeout | 300 s (stale-claimed rows re-claimable) |
| Max publish attempts | 8 |
| Base retry delay | 30 s |
| Retry backoff cap | 3600 s (≈ 1 hour) |
| Dead-letter state | `dead_lettered` after `max_attempts` |

**Dead-letter recovery:** A `dead_lettered` outbox row requires operator intervention (re-queue or discard). The change-log entry for the entity may already exist if the database write succeeded before the relay failure; clients pulling that entity will receive the current snapshot regardless.

---

## Client recovery algorithms

### Normal online-to-offline transition

1. Pull up to `has_more: false` and acknowledge the final cursor.
2. Seed local SQLite tables from received snapshots.
3. Assign monotonically increasing `device_sequence` starting from the server's last acknowledged sequence + 1.

### Resuming after offline period

1. Pull from the stored cursor; apply snapshots; tombstones delete local rows.
2. Push queued commands in batches of ≤ 200; for each `result`:
   - `applied`: update local `lock_version` from `entity_revision`.
   - `duplicate`: treat as `applied`.
   - `conflict`: quarantine the local row; surface to the user for review; the server conflict record is viewable via `GET /sales/sync/conflicts`.
   - `rejected`: log and discard — the command is not in the allow-list.
3. Continue pull until `has_more: false`; acknowledge.

### `SALES_RESYNC_REQUIRED` recovery

1. Clear all local entity tables and the outbox.
2. Reset local cursor to `0`.
3. Pull from cursor 0 to `has_more: false`.
4. Acknowledge with `resync: true`.
5. Resume normal operation.

### Sequence gap recovery

If push returns `409 SALES_INVALID_STATE` with `expected_sequence: N`:
- Discard locally-queued operations with `device_sequence < N`.
- Re-number remaining operations starting at `N`.
- Re-push.

---

## Sync configuration reference

| Key (`config/sales.php`) | Default | Meaning |
|--------------------------|---------|---------|
| `sync.schema_version` | `1` | Schema version header for push batches |
| `sync.push_batch_limit` | `200` | Maximum operations per push batch |
| `sync.pull_page_limit` | `200` | Hard cap on `limit` parameter |
| `sync.pull_page_default` | `100` | Default `limit` when not specified |
| `sync.idempotency_retention_days` | `30` | How long operation receipts are kept |
| `sync.tombstone_retention_days` | `90` | How long tombstones are retained |
| `sync.outbox.claim_limit` | `100` | Events claimed per outbox worker run |
| `sync.outbox.claim_timeout_seconds` | `300` | Stale-claim window |
| `sync.outbox.max_attempts` | `8` | Publish attempts before dead-letter |
| `sync.outbox.retry_seconds` | `30` | Base retry delay (doubles with backoff) |
| `sync.outbox.retry_backoff_cap_seconds` | `3600` | Maximum retry delay |
