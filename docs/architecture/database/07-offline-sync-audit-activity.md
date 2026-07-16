# Offline Synchronization, Audit & Activity

## Synchronization Tables

| Table | Required columns | Keys, authority, retention |
|---|---|---|
| `device_sequences` | scope, device, next device sequence, Lamport clock, last acknowledged | UQ device; locked increment; cloud/edge reconciliation |
| `sync_batches` | scope, device, direction, client/schema versions, operation count, started/completed, state | UQ device+batch ID; AO batch envelope |
| `sync_outbox_operations` | scope, device, operation/idempotency ULIDs, fingerprint, entity type/id, operation, payload version/body, device sequence, logical clock, state, next attempt | UQ tenant+device+idempotency; IX pending scan; edge authority until receipt |
| `sync_inbox_receipts` | scope, device, operation ID, fingerprint, result/status, entity revision, applied_at | UQ tenant+device+operation; cloud AO proof of apply/duplicate/reject |
| `idempotency_records` | tenant, device, key, fingerprint, HTTP/result code, safe result body, entity, created/expires | UQ tenant+device+key; MySQL authoritative; financial retention follows ledger |
| `sync_change_log_entries` | tenant, change sequence, branch target nullable, entity type/id, revision, operation, manifest, occurred_at | UQ tenant+sequence; cloud AO source for pull deltas |
| `sync_pull_cursors` | scope, device, stream/entity type, last sequence/revision, last pull/applied times, state | UQ device+stream; edge cursor update atomic with upserts |
| `sync_entity_heads` | scope, entity type/id, current revision, logical clock, last operation/device | UQ entity; deterministic optimistic/conflict check |
| `sync_tombstones` | tenant, branch target nullable, entity type/id, deletion revision/sequence, deleted_at, retention_until | UQ entity+revision; cloud→edge; blocks stale resurrection |
| `sync_attempts` | outbox operation, attempt number, started/finished, transport/result/error code, retry_at | UQ operation+attempt; AO; safe diagnostics only |
| `sync_conflicts` | scope, operation, entity, conflict type, local/remote revisions+safe snapshots, state, risk | UQ operation; IX scope+state+created; quarantine |
| `sync_conflict_actions` | conflict, action, proposal/result, actor/approver, occurred_at, compensating operation | AO resolution history; dual control when required |
| `sync_dead_letters` | scope, operation, terminal reason, payload hash/location, failed_at, requeue actor/time | AO until reviewed retention; payload access restricted |
| `sync_schema_compatibility` | payload version, min/max server/client versions, state, effective times | platform-global AO compatibility registry |

### Outbox Operation Contract

Every operation stores:

- `id`, `tenant_id`, `branch_id`, `device_id`, `batch_id`.
- Client `idempotency_key`, SHA-256 canonical request fingerprint, operation/entity identity.
- Payload format version, encrypted/redacted JSON payload, per-device sequence, Lamport clock.
- State `pending/in_flight/applied/duplicate/conflict/rejected/dead_letter`, retry count and time.
- Created, sent, acknowledged timestamps and the stable server result code.

The business mutation and outbox insert commit in one local transaction. Each batch item is
applied in its own cloud transaction so one conflict does not roll back independent operations.
Financial aggregates may request ordered serialization by aggregate ID.

### Replay and Ordering Rules

1. Verify authenticated tenant/branch/device against payload scope; never accept scope from body.
2. Lock/find idempotency record. Same key+fingerprint returns original result; different
   fingerprint is a security rejection.
3. Reject unsupported schema; quarantine valid competing state; retry only transient failures.
4. Apply mutation/event + inbox receipt + idempotency result + change log + audit atomically.
5. Edge marks outbox acknowledged only after durable receipt, then advances local state.
6. Pull upserts/tombstones and cursor advancement commit atomically; crash replays safely.
7. Timestamps never determine causality; device sequence, aggregate sequence, revision and
   Lamport clock do.

## Cloud / Edge Placement

| Dataset | Cloud | Branch edge | Device-local SQLite |
|---|---|---|---|
| Company catalog/policy/version manifests | authoritative | branch-filtered replica | needed branch projection |
| Branch settings/availability/routing | reconciler | operational authority when offline | needed device projection |
| Orders/KDS/shifts/payments/stock events | system of record after apply | operational source + outbox | device-local source only in small-branch mode |
| Consolidated reporting, audit archive | authoritative | optional recent subset | never full copy |
| Outbox/retry/dead-letter | receipts only | local full queue | local full queue for direct-sync mode |
| Idempotency/inbox receipts/change log | authoritative | acknowledgement cache | acknowledgement cache |

## Immutable Audit Tables

### `audit_events`

Required fields: ULID, tenant, branch nullable, per-scope audit sequence, category/action,
target type/id, actor type/user/service, device/session, source channel, result, reason,
before/after or diff (redacted), request/correlation/trace/idempotency IDs, occurred/recorded
times, previous hash and current hash. It is append-only.

Indexes: `(tenant_id,branch_id,recorded_at)`, tenant+actor+time, tenant+target+time,
tenant+category+time, correlation ID, and UQ tenant+branch+audit sequence.

### Supporting Audit Tables

| Table | Purpose |
|---|---|
| `audit_hash_heads` | latest sequence/hash per tenant+branch; locked when appending hash chain |
| `audit_access_events` | who searched/exported sensitive audit data and why |
| `audit_export_manifests` | filters, row count, checksum, file reference, requester/approver |
| `erasure_requests` | data subject/scope, legal basis, state, approvals and completion |
| `anonymization_events` | request, entity/fields transformed, before/after checksums; no original PII |

Database grants deny normal update/delete. Hash chaining is optional per policy but, once enabled
for a branch, cannot be disabled without an audited policy revision. Audit payload allowlists
exclude secrets, token values, PAN/CVV, raw provider credentials, and unnecessary PII.

## User-Facing Activity Logs

Audit and activity are separate: audit is compliance evidence; activity is a localized feed.

| Table | Required columns and keys |
|---|---|
| `activity_events` | tenant/branch nullable, event key/version, actor, safe target, localized-variable JSON, severity, occurred/expiry; IX audience/time |
| `activity_recipients` | event, user or role/branch audience, delivered/read/dismissed times; UQ event+recipient |
| `activity_links` | event, relation type, safe internal target type/id; UQ event+relation+target |

Activity metadata contains translation variables, never rendered trusted HTML or sensitive audit
snapshots. Expiry may hard-delete feed rows after audit evidence remains intact.

## Operational Logs

Request traces, debug logs, queue telemetry, metrics, and stack traces go to centralized
observability storage, not transactional MySQL. MySQL may store `operational_incident_refs`
(tenant/branch, correlation/trace ID, external evidence URL, severity, retention) to connect
business/audit events to external logs without duplicating them.

`operational_incident_refs` is company-owned with optional recording branch, UQ
`(tenant_id,correlation_id,evidence_url_hash)` and IX `(tenant_id,severity,created_at)`; it stores
only links/checksums and classification, never copied logs or credentials.