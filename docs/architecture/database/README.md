# Production Database Design

**Status:** design-only source of truth; no Laravel models, migrations, or application code.
This set refines architecture documents 01–07 into an implementable MySQL 8 / edge-SQLite
database contract for Top Organic.

## Coverage

| Document | Contents |
|---|---|
| [01](01-er-diagrams.md) | Bounded ER diagrams and cardinalities |
| [02](02-platform-identity-catalog.md) | Company, branch, RBAC, devices, catalog, pricing, recipes |
| [03](03-pos-kds-billing.md) | Tables, shifts, orders, KDS, payments, invoices, tax snapshots |
| [04](04-operations-and-engagement.md) | Inventory, procurement, CRM, delivery, HR, notifications, reporting |
| [05](05-integrity-indexes-isolation.md) | Relationships, FKs, indexes, company/branch isolation |
| [06](06-lifecycle-history-versioning.md) | Soft deletes, histories, revisions, publication/version control |
| [07](07-offline-sync-audit-activity.md) | Sync persistence, audit tables, activity logs |
| [08](08-validation-matrix.md) | Traceability, invariants, implementation gates |

## Physical Conventions

- **Cloud engine:** MySQL 8, InnoDB, `utf8mb4`; default collation
  `utf8mb4_0900_ai_ci`. Identifier/code columns use binary or ASCII collation.
- **Edge engine:** MySQL 8 on branch edge; Flutter device-local projections use SQLite.
- **Time:** `DATETIME(3)` in UTC. Business date is a separate `DATE` resolved in the branch
  timezone. Never rely on database-session timezone.
- **Identifiers:** monotonic ULID, encoded as `BINARY(16)` in MySQL and `BLOB(16)` in SQLite;
  APIs/logs render canonical uppercase 26-character ULID. Externally supplied UUIDs remain
  separate opaque identifiers. Human invoice/order numbers are not primary keys.
- **Money:** signed `BIGINT` minor units + ISO `CHAR(3)` currency. FX rates and quantities use
  `DECIMAL(18,8)` and `DECIMAL(18,6)` respectively; never `FLOAT`/`DOUBLE`.
- **Booleans:** `TINYINT(1)` with check constraints. States are `VARCHAR(32)` + checks or
  lookup tables; MySQL `ENUM` is avoided to keep rolling changes backward-compatible.
- **JSON:** limited to immutable snapshots, provider payloads, policy/config values, and sync
  envelopes. Searchable business attributes stay relational or use generated indexed columns.
- Every mutable row has `created_at`, `updated_at`, and `lock_version BIGINT`. Soft-deletable
  rows also have `deleted_at`; immutable ledgers omit update/delete metadata.

## Ownership Classes

| Class | Required scope | Examples |
|---|---|---|
| Platform-global | none | supported locales/currencies, schema compatibility |
| Company-owned | `tenant_id` | users, catalog, suppliers, customers |
| Branch-owned | `tenant_id`, `branch_id` | devices, orders, tables, stock balances |
| Cross-branch | `tenant_id`, explicit source/destination | stock transfers |
| Edge-local | full scope + `device_id` | local outbox, retry/dead-letter state |
| Immutable ledger | scope + event/document identity | payments, stock moves, audit events |

`tenant` means one restaurant company. Physical columns remain `tenant_id` so the design
matches the approved future-SaaS architecture; this is the company-isolation boundary.

## Standard Table Contract

Every implementation must derive the following from this design:

1. Primary key and scope-aware candidate key.
2. Required/nullable columns with precision and defaults.
3. Composite foreign keys that preserve tenant/branch ownership.
4. Unique, lookup, queue, time-range, and FK-supporting indexes.
5. Write authority: cloud, edge, device, or derived projection.
6. Lifecycle: mutable, soft-deleted, versioned, append-only, or ephemeral.
7. Replication direction and conflict policy where synchronized.

## Non-Negotiable Invariants

- Cross-company and accidental cross-branch references fail at the database boundary.
- Settled finance, stock movements, audit events, and applied sync receipts are append-only.
- Corrections are compensating records referencing originals, never destructive updates.
- Business mutation + outbox append commit atomically; pulled data + cursor advance commit
  atomically.
- MySQL is the authoritative idempotency store; Redis is only a concurrency guard.
- Reference-data deletion produces a sync tombstone, preventing stale offline resurrection.
- PII, secrets, full tokens, and card data never enter audit/activity payloads.

## Decisions Requiring Business Approval

- Iraqi tax/service-charge/tip calculation order and taxability.
- Cash-rounding increments and accounting rounding mode per currency.
- Legal retention, erasure/anonymization, and legal-hold periods.
- Exact maximum offline replay window and edge dataset size.

These remain versioned policy; they must not be hard-coded during schema implementation.