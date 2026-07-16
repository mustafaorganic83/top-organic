# Integrity, Foreign Keys, Indexes & Isolation

## Company and Branch Isolation

`tenant_id` is the restaurant-company boundary. Database credentials are environment-level;
row isolation is enforced by keys/constraints plus application context—not by trusting headers.

### Candidate Keys Required on Parents

| Parent class | Required key |
|---|---|
| Company-owned | PK `(id)` and UQ `(tenant_id,id)` |
| Branch-owned | PK `(id)` and UQ `(tenant_id,branch_id,id)` |
| Branch | PK `(id)` and UQ `(tenant_id,id)` |
| Platform-global | natural/ULID PK; no tenant |

### Composite Foreign-Key Rules

- Company child → company parent: `(tenant_id,parent_id)` references
  `(tenant_id,id)`.
- Branch child → branch parent: `(tenant_id,branch_id,parent_id)` references
  `(tenant_id,branch_id,id)`.
- Branch-owned row → branch: `(tenant_id,branch_id)` references `(tenant_id,id)`.
- Cross-branch transfer uses two FKs: `(tenant_id,from_branch_id)` and
  `(tenant_id,to_branch_id)`; a check requires different branches.
- A branch-owned row may reference a company master with `(tenant_id,master_id)`, but the
  reverse reference is forbidden.
- `branch_id` is nullable only for explicitly company-wide tables. Null never means “all
  branches”; authorization expands allowed branches before querying.
- IDs are immutable: every FK uses `ON UPDATE RESTRICT`.

## Delete and Update Policy

| Relationship | Delete behavior |
|---|---|
| Company/branch with any business data | `RESTRICT`; close/soft-delete instead |
| Draft composition lines | controlled `CASCADE` only before publish/place/post |
| Published reference/version rows | `RESTRICT`; archive and tombstone |
| Settled finance, stock, audit, sync receipts | `RESTRICT`; compensating row only |
| Optional actor/customer after approved anonymization | `SET NULL` only with immutable snapshot |
| Tokens, ephemeral attempts, cache-like projections | retention-driven hard delete allowed |

Database roles must deny `UPDATE`/`DELETE` on immutable ledger tables to routine application
connections; controlled archival uses a separate audited role.

## Soft-Delete Uniqueness

MySQL unique keys permit multiple `NULL` values, so `(tenant_id,code,deleted_at)` does **not**
enforce one active code. Every reusable active key defines a stored/generated marker such as
`active_marker = IF(deleted_at IS NULL,1,NULL)` and a unique key:

Company-owned tables use `UQ (tenant_id,business_key,active_marker)`; branch-owned tables use
`UQ (tenant_id,branch_id,business_key,active_marker)`. A nullable `branch_id` is never included
in a company-wide uniqueness key because MySQL treats `NULL` values as distinct.

Restore fails safely if an active replacement owns the key; resolution must rename, merge, or
keep the historical row archived. Soft-delete updates create history, audit, and sync tombstone
records in the same transaction.

## Index Naming and Ordering

- `pk_<table>`, `uq_<table>_<purpose>`, `ix_<table>_<purpose>`, `fk_<child>_<parent>`.
- Scope leads operational indexes: `(tenant_id,branch_id,...)` for branch queries and
  `(tenant_id,...)` for company queries.
- Equality predicates precede range/sort columns: scope → state/type → time/sequence.
- Every FK has a matching left-prefix index. Do not rely on incidental indexes.
- Avoid redundant prefixes: an index on `(tenant_id,branch_id,state,created_at)` usually makes
  `(tenant_id,branch_id,state)` redundant.
- Index long text through explicit search projections; never index full JSON/text blindly.

## Critical Access-Path Indexes

| Table | Required index / purpose |
|---|---|
| `branches` | UQ tenant+code; IX tenant+status |
| `user_branch_roles` | IX tenant+user+revoked; IX tenant+branch+revoked |
| `devices` | UQ tenant+code; IX scope+status+last_seen |
| `products` | UQ active tenant+SKU; IX tenant+category+status+sort |
| `branch_catalog_items` | UQ scope+variant; IX scope+available+station |
| `price_list_items` | UQ tenant+price-list+variant; covering amount/currency |
| `orders` | UQ scope+number; IX scope+state+created; IX scope+business_date |
| `order_events` | UQ scoped order+sequence; UQ scoped operation ID |
| `kds_tickets` | IX scope+station+state+priority+created |
| `payments` | UQ scoped idempotency/provider reference; IX scope+captured_at+method |
| `invoices` | UQ scope+number; IX scope+business_date+status |
| `stock_balances` | UQ scope+location+item; IX scope+item |
| `stock_movements` | UQ scoped operation ID; IX scope+item+occurred; IX source type/id |
| `purchase_orders` | UQ tenant+number; IX tenant+destination branch+state+date |
| `customers` | UQ active tenant+canonical phone/email; IX tenant+status+last-order |
| `deliveries` | UQ scope+order; IX scope+state+promised_at |
| `notifications` | IX tenant+status+scheduled_at; IX recipient type/id+created |
| `audit_events` | IX scope+occurred; actor+occurred; target type/id+occurred; correlation ID |
| `sync_outbox_operations` | UQ tenant+device+idempotency; IX scope+state+next_attempt+device_sequence |
| `sync_change_log_entries` | UQ tenant+change_sequence; IX tenant+entity type+sequence |

## Check Constraints and Transactional Invariants

- Currency length/uppercase; amounts fit `BIGINT`; quantities and rates are non-negative where
  domain-appropriate; `effective_to > effective_from`; end timestamps follow start timestamps.
- `from_branch_id <> to_branch_id`; tender/base currencies and FX presence are consistent.
- Line/tax/allocation totals are validated transactionally and rechecked on settlement/posting.
- One active table session, drawer session, device session, default address, and equivalent role
  grant is enforced with active-marker unique keys.
- State transitions cannot be fully expressed by checks; append event + projection update under
  aggregate lock and optimistic version check.

## Partitioning, Archival & Scale

- Do **not** partition FK-heavy OLTP tables by default: MySQL partitioning and foreign-key
  constraints are incompatible in common MySQL 8 deployments. Integrity wins over speculative
  partitioning.
- First use scope/time indexes, read replicas, summary tables, and retention archiving.
- Partition only append-only tables that intentionally have no physical FKs (for example a
  detached audit archive) after measured query/volume evidence and DBA approval.
- Archive by tenant + business period with manifest, row counts, min/max IDs, checksum, legal
  hold, export location, and restore test. Never replace immutable source records with opaque
  JSON before the approved retention boundary.

## Isolation Verification

For every relationship, tests must attempt same-ID cross-tenant and cross-branch insertion and
expect a constraint failure. Query-plan tests must assert scope-index use for POS, KDS, sync,
stock, invoice, and audit paths; no production approval without representative `EXPLAIN` plans.