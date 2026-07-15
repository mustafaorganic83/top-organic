# 04 — Data, API, Cache & File Storage

## Database Strategy

**Engine:** MySQL 8 (InnoDB, `utf8mb4` / `utf8mb4_0900_ai_ci`; binary/ASCII collation for
identifiers and codes). Arabic search/sort behavior must be verified with production fixtures.

### Modeling Principles
- **Scoping columns everywhere:** `tenant_id` (future SaaS) + `branch_id` on all
  tenant/branch-owned tables; composite indexes lead with the scope column.
- **Multilingual content:** translatable fields via a normalized `translations` table
  (`translatable_type`, `translatable_id`, `locale`, `field`, `value`) with fallback
  `ar-IQ → ar → en`. JSON columns allowed for simple label pairs; heavy search uses the
  normalized form.
- **Money:** store as integer **minor units** with an explicit `currency` (IQD has 0 decimals,
  USD 2) plus captured `fx_rate` on dual-currency transactions; never floats.
- **Time:** UTC `timestamps`; display in Asia/Baghdad. Business dates (shift/day) stored
  explicitly to avoid timezone drift in reports.
- **Immutability for finance:** settled invoices/payments are append-only; corrections are new
  rows referencing the original.
- **Soft deletes + versioning** on reference data; **event/outbox tables** for sync & audit.
- **Identifiers:** UUID/ULID primary keys for sync-safe, offline-generated records; human
  sequence numbers (invoices) are branch-prefixed and gap-tolerant.

### Topology & Scaling
- **Cloud:** primary + read replicas; reads for reporting/dashboards routed to replicas.
- **Edge:** local MySQL per edge node holding the branch's working set + reference cache.
- **Partitioning path:** high-volume tables (orders, order_items, stock_moves, audit) are
  time/branch partition-ready; archival of closed periods to cold storage.
- **Migrations:** versioned, backward-compatible (expand/contract) to allow zero-downtime and
  edge rollout skew. Seeders provide region packs (Iraq defaults).

### Read Models
- Denormalized read/report tables built from domain events for fast Arabic PDF/Excel and
  chain dashboards, decoupled from the transactional write model (CQRS-lite).

---

## API Strategy

- **REST, versioned** (`/api/v1/...`), JSON, resource-oriented; additive changes only within a
  version, breaking changes → new version.
- **Contract-first:** OpenAPI spec is the source of truth; generates client stubs and docs;
  contract tests guard it.
- **Auth:** token-based (Laravel Sanctum/Passport) with device-bound tokens for POS/KDS;
  short-lived access + refresh; scopes map to RBAC permissions.
- **Context headers:** `Accept-Language` (locale), `X-Branch-Id`, future `X-Tenant-Id`,
  `X-Device-Id`, and **`Idempotency-Key`** on all writes (critical for offline replay).
- **Envelope:** consistent success/error shape; **localized error messages** (Arabic/English)
  with machine-readable codes; validation errors field-keyed.
- **Sync endpoints:** dedicated `pull` (reference deltas by cursor) and `push` (batch of
  outbox operations) endpoints, batched and idempotent (see doc 05).
- **Pagination/filtering:** cursor-based for large sets; server-side filtering/sorting;
  sparse fieldsets for POS bandwidth economy.
- **Rate limiting & quotas:** per-token/branch/tenant (Redis), stricter for messaging.
- **Compatibility:** clients advertise app/schema version; server negotiates and can flag
  forced upgrades. Realtime (WebSockets) is additive — never the only way to get data.
- **Documentation:** bilingual API docs; example payloads with Arabic content.

---

## Cache Strategy

**Engine:** Redis 7 (cloud cluster; local Redis at each edge).

| Cache use | Pattern | Invalidation |
|-----------|---------|--------------|
| Reference data (menu, prices, tax, region packs) | read-through, versioned keys | on publish / `SettingsChanged` |
| Session/auth & permissions | short TTL | on role/permission change |
| Idempotency keys | SET NX + TTL | expiry after safe window |
| Distributed locks (shift close, sync apply) | Redlock-style | released on completion |
| Rate limiting & quotas | token bucket | rolling window |
| Hot read models / dashboards | cache-aside + TTL | event-driven bust |

- **Key namespacing:** `tenant:{t}:branch:{b}:...` so multi-tenant/multi-branch never collide.
- **Stampede protection:** locks + jittered TTL; edges cache reference data locally to survive
  cloud outages.
- **No source-of-truth in cache:** cache is disposable; MySQL is authoritative.

---

## File Storage Strategy

- **Object storage (S3-compatible)** in the cloud for images, PDF invoices/reports, Excel
  exports, barcode-label assets, and message media; local disk/minio at the edge with async
  upload to cloud when online.
- **Namespacing:** `tenant/{t}/branch/{b}/{domain}/{ulid}` paths → clean isolation & lifecycle.
- **Access:** private buckets, **signed time-limited URLs**; no public direct writes.
- **Documents:** generated PDFs (Arabic-shaped, RTL) and Excel (RTL sheets) stored and served
  via signed URLs; large exports produced by queue workers, not in-request.
- **Fonts/assets:** Arabic-capable fonts bundled with the document pipeline (deterministic
  rendering across environments).
- **Lifecycle:** retention/expiry policies per document type; cold-tier archival for old
  periods; per-tenant export & deletion for compliance.
- **Integrity & AV:** checksums on upload, size/type validation, optional malware scan on
  user-uploaded media.
