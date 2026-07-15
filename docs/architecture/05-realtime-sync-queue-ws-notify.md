# 05 — Synchronization, Queue, WebSocket & Notifications

## Synchronization Engine

The backbone of the offline/online hybrid. Moves reference data cloud→edge and transactional
data edge→cloud **reliably, idempotently, and eventually consistently**.

### Building Blocks
- **Outbox (edge/device):** every local mutation writes its business change **and** an outbox
  record (operation type, entity, payload, `idempotency_key`, device sequence, logical clock)
  in the same local transaction. Guarantees no lost writes.
- **Inbox / cursor (cloud):** pull deltas by monotonic cursor per entity; each side tracks a
  high-water mark so sync resumes exactly where it stopped.
- **Idempotency:** `idempotency_key` (client ULID) + unique constraint + Redis guard make
  every push safely retriable; re-applying an operation is a no-op.
- **Ordering:** per-device monotonic sequence + logical/Lamport clock orders events across
  clock skew; financial events ordered by capture sequence.

### Sync Flow
1. **Push (edge→cloud):** drain outbox in batches to `POST /sync/push`; server validates,
   applies within a transaction, records applied keys, returns per-op results
   (`applied | duplicate | conflict`).
2. **Pull (cloud→edge):** `GET /sync/pull?cursor=...` returns reference deltas
   (menu/prices/tax/settings/users); edge upserts and advances the cursor.
3. **Acknowledge & advance:** edge marks outbox rows done; conflicts are quarantined for
   resolution.

### Conflict Resolution (deterministic)
- **Reference data:** cloud is authoritative → last-writer = cloud publish wins; edge caches.
- **Additive transactional data (orders, payments, stock moves):** **no true conflicts** — they
  are independent immutable events; simply de-duplicated by idempotency key.
- **Mutable shared state (table status, stock levels):** resolved by rule — stock uses
  **event-sourced deltas** (sum of moves), not last-write; table/session state uses
  **last-writer-wins by logical clock** with an audit of overrides.
- **Quarantine & review:** anything non-deterministic raises `ConflictRaised`, is parked, and
  surfaced in an admin Arabic UI for manual resolution — never silently dropped.

### Guarantees & Observability
- At-least-once delivery + idempotent apply = **effectively exactly-once** business effect.
- Per-device sync dashboard: pending count, last sync, lag, quarantined items, replay button.
- Backpressure: batch sizing, exponential backoff, resumable partial batches.
- **Money safety:** settled financial records immutable; corrections are compensating events,
  so replay/duplicate never double-charges.

---

## Queue Strategy

**Engine:** Laravel Queue on **Redis**, managed by **Horizon** (cloud); edge runs a lightweight
worker for local async + upload.

### Queues & Priorities
| Queue | Examples | Priority |
|-------|----------|----------|
| `realtime` | KDS broadcasts, order-status fan-out | highest |
| `sync` | apply push batches, pull reconciliation | high |
| `default` | domain event handlers, read-model updates | normal |
| `documents` | Arabic PDF/Excel/label generation | normal |
| `messaging` | WhatsApp/SMS/push dispatch | throttled |
| `reports` | heavy scheduled/consolidated reports | low |
| `maintenance` | archival, cleanup, backups | low |

### Principles
- **Idempotent, retriable jobs** with capped exponential backoff; poison messages → dead-letter
  queue with alerting.
- **Isolation:** per-tenant/branch throttling and rate limits (esp. `messaging`) to prevent
  noisy neighbors; queue keys namespaced by tenant.
- **Ordering where needed:** per-aggregate serialization (e.g., one order's events) via
  `WithoutOverlapping` locks; otherwise parallel.
- **Scheduling:** Laravel Scheduler drives end-of-day rollups, report delivery, retention.
- **Scaling:** Horizon autoscaling by queue depth; workers are stateless and carry
  tenant/branch/locale context explicitly (never inferred from a request).


---

## WebSocket Strategy

**Engine:** Laravel **Reverb** (first-party WebSocket server) with Laravel Echo clients
(Flutter). Realtime is an **additive** channel — every state is also fetchable via REST, so a
dropped socket never blocks operations.

### Channel Design
- **Private, authorized channels**, namespaced `tenant:{t}:branch:{b}:...` so events never
  cross tenant/branch:
  - `...:kds:{station}` — new/updated/bumped tickets to Kitchen Display.
  - `...:pos` — order/table/payment updates across POS terminals in a branch.
  - `...:manager` — live sales/alerts for managers.
  - `...:devices` — device/session and forced-upgrade signals.
- **Presence channels** for KDS/POS terminals (who is online, station coverage).
- Authorization uses the same RBAC token; channel auth checks branch/tenant grants.

### Delivery Semantics
- WebSockets carry **notifications of change** (small, versioned event with entity id +
  version); clients reconcile via REST when needed — avoids large socket payloads and keeps
  offline/online paths identical.
- **At-least-once, dedup by event id + version;** clients ignore stale/older versions.
- **Reconnect:** on resume, client re-subscribes and does a REST delta pull to catch missed
  events (socket is best-effort, REST is authoritative).
- Broadcasts are dispatched from the `realtime` queue after the domain transaction commits
  (never mid-transaction), guaranteeing clients only see committed state.

### Edge Behavior
- In edge-node mode, the edge hosts a **local Reverb** so KDS/POS get sub-second updates on
  LAN **even fully offline**; cloud broadcasts resume for chain/manager views when online.

### Scaling
- Redis pub/sub backplane fans out across multiple Reverb instances behind a load balancer;
  connection counts scale horizontally; heartbeats + idle timeouts reclaim dead sockets.

---

## Notification Strategy

Unified, **Arabic-first**, multi-channel notification module with pluggable providers.

### Channels
- **WhatsApp** (Arabic order status, receipts, marketing) via Business API provider.
- **SMS** (Arabic templates) via Iraqi telecom/aggregator gateways.
- **Push** (FCM/APNs) to Flutter apps; **in-app** notifications; **email** (optional).

### Design
- **Template engine:** localized, versioned templates per channel/event
  (`ar-IQ` default, `en` variant), with variables, RTL-safe rendering, and per-branch sender
  identity. Templates are data (editable in admin), not code.
- **Provider abstraction (ports/adapters):** WhatsApp/SMS providers are swappable behind a
  common interface; failover to a secondary gateway on provider outage.
- **Delivery pipeline:** events → `messaging` queue → provider adapter → delivery receipts →
  status stored and auditable; **retries + dead-letter** on failure.
- **Preferences & compliance:** per-customer channel opt-in/opt-out, quiet hours, rate limits,
  and marketing-vs-transactional separation.
- **Idempotency & throttling:** dedup keys prevent double-send on sync replay; per-tenant/branch
  throttles protect provider quotas and cost.
- **Observability:** per-message status (queued/sent/delivered/failed), cost tracking, and
  Arabic delivery reports.
