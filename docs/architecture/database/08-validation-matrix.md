# Database Design Validation Matrix

## Requirement Traceability

| Required deliverable | Authoritative section |
|---|---|
| ER diagram | [01](01-er-diagrams.md) |
| Database schema | [02](02-platform-identity-catalog.md), [03](03-pos-kds-billing.md), [04](04-operations-and-engagement.md) |
| Relationships & cardinality | [01](01-er-diagrams.md), [05](05-integrity-indexes-isolation.md) |
| Indexes | [05](05-integrity-indexes-isolation.md) |
| Foreign keys | [05](05-integrity-indexes-isolation.md) |
| History tables | [06](06-lifecycle-history-versioning.md) |
| Audit tables | [07](07-offline-sync-audit-activity.md) |
| Soft deletes | [05](05-integrity-indexes-isolation.md), [06](06-lifecycle-history-versioning.md) |
| UUID/ULID strategy | [README](README.md) |
| Branch isolation | [05](05-integrity-indexes-isolation.md) |
| Company isolation | [README](README.md), [05](05-integrity-indexes-isolation.md) |
| Offline synchronization tables | [07](07-offline-sync-audit-activity.md) |
| Version-control tables | [06](06-lifecycle-history-versioning.md) |
| Activity logs | [07](07-offline-sync-audit-activity.md) |

## Functional Module Coverage

| Architecture module | Schema coverage |
|---|---|
| Core/platform | tenants, branches, region/settings/flags/translations/sequences/files |
| Identity/access | users, credentials, roles, permissions, grants, devices, sessions/tokens |
| Catalog/menu | categories, products, variants, modifiers, combos, prices, schedules, recipes |
| POS/tables | floors, tables, reservations, shifts, drawers, orders and events |
| KDS | stations, routing, tickets/items/events |
| Billing | methods, payments/allocations/events/reversals, invoices/tax/credit notes |
| Inventory/procurement | items, warehouses, balances/movements/counts/transfers, suppliers/PO/GRN |
| CRM/loyalty | customers, addresses, consent/preferences, accounts/points, feedback |
| Delivery/integrations | zones, drivers, deliveries/events, aggregator accounts/orders/events |
| HR/workforce | employees, assignments, schedules, attendance, leave, payroll inputs, tips |
| Notifications/reporting | templates/versions, sends/attempts/receipts, reports/runs/documents/facts |
| Sync/audit | outbox/inbox/idempotency/cursors/conflicts/tombstones, audit/activity/history |

## Schema Review Checklist

- [ ] Every physical table has declared ownership: global, company, branch, cross-branch,
  edge-local, ledger, or projection.
- [ ] Every owned parent has the scope-aware candidate key required by document 05.
- [ ] Every FK preserves tenant and, when required, branch scope; every FK has a left-prefix IX.
- [ ] Cross-tenant and cross-branch negative insert tests are specified for every relationship.
- [ ] All active business keys use generated active-marker uniqueness, not nullable-delete keys.
- [ ] All amounts use integer minor units + ISO currency; rates/quantities use exact decimals.
- [ ] UTC event time and branch-local business date are distinct where accounting requires it.
- [ ] Settled finance, stock, loyalty/cash ledgers, and audit are append-only and compensating.
- [ ] Mutable aggregates have `lock_version`, ordered event history, and legal transitions.
- [ ] Published catalog/policy/config revisions are immutable and linked to manifests.
- [ ] Soft delete emits history, audit, change-log entry, and tombstone atomically.
- [ ] PII erasure preserves accounting integrity and records approved anonymization evidence.
- [ ] Idempotency is MySQL-authoritative; same key/different fingerprint is rejected.
- [ ] Outbox+mutation and pull-upsert+cursor are tested as atomic crash boundaries.
- [ ] Batch operations return one durable result per item; partial independent success is safe.
- [ ] Device/aggregate sequence and logical clock—not wall time—control ordering.
- [ ] Edge/cloud authority and replication direction are assigned for every synchronized entity.
- [ ] Query-path indexes cover POS, KDS, sync, stock, invoice, reporting, and audit workloads.
- [ ] Representative `EXPLAIN` plans prove scope/time indexes and bounded row estimates.
- [ ] Partitioning is not used on FK-heavy tables; archive/retention has checksum and restore test.
- [ ] No secret, full token, PAN/CVV, or unnecessary PII is stored in logs/payloads.

## Production Approval Gates

1. **Domain review:** restaurant operations validate order, KDS, shift, stock, procurement,
   delivery, workforce, and correction lifecycles.
2. **Accounting review:** Iraqi advisor approves configurable tax/service/tip order, rounding,
   invoice/credit-note semantics, fiscal dates, and retention.
3. **Security review:** validates composite isolation, token/PII storage, audit grants, erasure,
   legal hold, provider-payload redaction, and backup encryption.
4. **Offline review:** simulated duplicate, crash, stale deletion, out-of-order, conflict,
   schema-skew, partial-batch, and long-disconnection scenarios all converge without data loss.
5. **DBA review:** MySQL version/collation, exact DDL, index selectivity, lock behavior, storage
   growth, replicas, archive/restore, and edge sizing are benchmarked.

## Deferred Until DDL/Migration Phase

- Exact constraint/index names and generated-column expressions.
- MySQL/SQLite migration order and expand/contract scripts.
- Seed data, Laravel models, casts, scopes, policies, repositories, or application code.
- Final retention durations, tax rules, cash rounding, and legal-hold periods.

No application implementation should begin from an isolated table list. The complete database
contract is this document set plus architecture documents 01–07 and the approved decision log.