# Data Lifecycle, History & Version Control

## Lifecycle Classification

| Class | Tables / examples | Rule |
|---|---|---|
| Soft-deleted masters | branches, users, roles, devices, categories, products, suppliers, customers, employees | `deleted_at` + status; preserve FK target and emit tombstone |
| Versioned reference | policies, settings, prices, recipes, menus, routing, templates, schedules | draft revisions; published revisions immutable |
| Mutable workflow | reservations, open orders, open shifts, purchase orders, deliveries | optimistic locking + event history; freeze at terminal state |
| Append-only ledger | payments/reversals, invoices/credit notes, stock/loyalty/cash movements, attendance events | insert only; compensate rather than edit/delete |
| Immutable audit | audit events and hash chain | insert only; privileged archival only after retention |
| Rebuildable projection | order status, stock balance, daily facts, KDS queue | may rebuild from authoritative ledger/events |
| Ephemeral | access tokens, retry attempts, expired notifications | hard-delete by documented TTL after audit needs are met |

## History Tables

History records **what the entity looked like**; audit records **who did what and why**.
Both are required for controlled entities.

| History table | Source | Snapshot contents |
|---|---|---|
| `tenant_setting_history` | tenant settings | key, typed value, revision, effective interval |
| `branch_setting_history` | branch settings | inherited/resolved value, revision, actor |
| `user_security_history` | user/access lifecycle | status/role/device changes; no credential hashes |
| `catalog_entity_history` | categories/products/variants/modifiers | type/id, revision, complete relational snapshot checksum |
| `price_list_history` | price-list publication | header revision, item manifest checksum, effective interval |
| `recipe_history` | recipes | revision, yield, component-manifest checksum |
| `table_layout_history` | floors/tables | revision, full layout/version checksum |
| `order_events` | order aggregate | ordered business events, not row-diff snapshots |
| `kds_ticket_events` | KDS aggregate | ordered state/bump/recall events |
| `payment_events` | payment aggregate | authorization/capture/failure/provider events |
| `inventory_document_history` | PO/count/transfer workflows | revision/status transition snapshots until posting |
| `delivery_events` | delivery aggregate | ordered status/location events |
| `employment_assignment_history` | workforce | effective-dated branch/role assignment |

Generic history rows contain `tenant_id`, optional `branch_id`, entity type/id, revision,
change-set ID, snapshot/diff JSON, snapshot checksum, changed actor/device, reason, source,
`valid_from`, `valid_to`, and `recorded_at`. Sensitive fields are redacted/encrypted by policy.

## Configuration Version-Control Tables

| Table | Purpose and required keys |
|---|---|
| `config_change_sets` | tenant, title/reason, state `draft/review/approved/published/reverted`, creator/approver, base revision; UQ tenant+change-set number |
| `entity_revisions` | change set, entity type/id, revision, operation, before/after checksum, snapshot/diff; UQ entity+revision |
| `publication_manifests` | tenant, change set, publication number, target type, effective time, checksum; AO after publish |
| `publication_items` | manifest, entity type/id/revision, operation `upsert/delete`; UQ manifest+entity |
| `publication_targets` | manifest, branch or branch group, target revision/state; UQ manifest+target |
| `publication_acknowledgements` | target branch/device, manifest, applied revision/time, result/error; UQ target+manifest |
| `rollback_manifests` | tenant, original manifest, restoring manifest, reason, approvers; AO lineage |
| `schema_versions` | version, compatibility range, checksum, released/retired times; platform-global AO |
| `device_schema_states` | tenant/branch/device, schema/app versions, last compatible sync time | UQ device; blocks unsupported payloads |

Applies to catalog, pricing, recipes, tax/region policy, settings, feature flags, KDS routing,
notification templates, report definitions, and branch configuration. “Rollback” publishes a
new revision matching an older state; it never deletes published history.

## Revision and Effective-Time Rules

- Revision is monotonic per entity; UQ `(tenant_id,entity_type,entity_id,revision)`.
- Publishing uses one transaction for manifest, items, history, change log, and outbox.
- Effective intervals are half-open `[valid_from, valid_to)` and may not overlap for the same
  policy/key/target. MySQL cannot enforce all interval exclusion rules; serialize per aggregate
  and validate under `SELECT ... FOR UPDATE`.
- `lock_version` detects stale concurrent edits; publish checks expected base revision.
- Every edge-applied reference row stores source revision and publication-manifest ID.

## Soft Deletes and Tombstones

Soft delete is allowed only when references must survive and the entity may be restored.

1. Set status + `deleted_at`, increment revision, write history and audit.
2. Append `sync_tombstones` with entity identity and deletion revision.
3. Pull clients apply tombstone only when its revision is newer than local state.
4. Re-creation uses a new ULID; restoration retains the original ULID and creates a new revision.
5. Hard purge requires expired retention, no legal hold, archived evidence, and an audited job.

Published catalog rows remain resolvable by historical orders even when archived. Archived
items cannot be sold; a stale edge sale is rejected/quarantined according to sync policy.

## PII Erasure and Anonymization

- Never delete financial/audit facts required for accounting integrity.
- Replace customer/employee direct identifiers with irreversible tenant-scoped anonymized
  values after approved erasure; keep non-identifying transaction totals and immutable snapshots.
- Consent/erasure request, approval, fields transformed, checksum, and completion are audited.
- Legal hold overrides purge but not access controls; periods await legal/accounting approval.

## Retention Registry

`retention_policies` stores tenant, data class, hot/archive/purge durations, legal-hold behavior,
policy revision, approval and effective dates. `legal_holds` stores tenant, subject/scope,
reason, start/release approvals. `archive_manifests` stores scope/period, counts, checksums,
object location, encryption-key reference, and restore-test result.