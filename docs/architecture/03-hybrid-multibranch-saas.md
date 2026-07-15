# 03 — Hybrid, Multi-Branch & Multi-Tenant

## Offline / Online Hybrid Architecture

**Principle:** the branch must **sell and cook without internet**. The cloud is the system of
record and reconciler; the edge is the fast, resilient point of operation.

### Deployment Modes per Branch
1. **Edge-node mode (recommended for large branches/chains):** a Docker edge stack runs on a
   branch mini-server — Laravel *edge API* + local MySQL + local Redis. POS/KDS devices talk
   to the edge over LAN. The edge syncs bidirectionally with the cloud.
2. **Device-local mode (small branches):** Flutter POS/KDS embed a local store (SQLite/Drift)
   + outbox and sync directly to the cloud when connectivity returns. Used where an edge
   server is not viable.

Both modes use the **same domain logic and the same sync contract**, so a branch can move
between modes without re-architecture.

### Data Classification
- **Reference data (cloud → edge):** menu, prices, taxes, region packs, users/roles, settings.
  Pulled and cached at the edge; read-mostly.
- **Transactional data (edge → cloud):** orders, payments, KDS tickets, stock moves, shifts.
  Captured locally, queued in an **outbox**, pushed idempotently.
- **Derived data (cloud):** consolidated reports and analytics computed centrally.

### Online/Offline State Machine (edge/device)
`ONLINE → DEGRADED (intermittent) → OFFLINE → RECONCILING → ONLINE`
- Writes always succeed locally and enter the outbox.
- Reference pulls resume on reconnect; transactional pushes drain the outbox in order.
- UI shows a clear sync indicator (Arabic labels) and per-device pending count.

### Consistency Model
- **Inside a branch/order:** strong consistency (local transactions).
- **Across the boundary:** eventual consistency with idempotency keys, monotonic sequence
  numbers per device, and deterministic conflict rules (see Sync Engine, doc 05).
- **Money is authoritative at capture:** settled payments/invoices are immutable; corrections
  are new compensating entries, never edits — safe under replay.

### Time & Numbering
- All timestamps stored UTC, displayed Asia/Baghdad. Devices carry a logical clock to order
  events even with skew. Invoice/receipt numbers are **branch-prefixed** and gap-tolerant
  (e.g., `BR07-2025-000123`) so offline issuance never collides.

---

## Multi-Branch Architecture

**Branch is a first-class dimension** across the whole platform.

- **Scoping:** every transactional row carries `branch_id`; global query scopes enforce it.
  Users are granted one/many branches; chain roles see aggregates across granted branches.
- **Central catalog, local overrides:** menu/prices defined centrally, with **per-branch
  availability, price lists, taxes, and printers/stations**. Publishing pushes to edges.
- **Inter-branch operations:** stock transfers, central warehouse issue/receipt, shared
  customers/loyalty, and cross-branch reporting.
- **Branch autonomy:** each branch runs independently (edge/device offline) yet rolls up to
  chain-level dashboards when online.
- **Configuration inheritance:** `chain → region pack → branch → device` override chain, so a
  branch can differ (e.g., a branch that also charges in USD) without code changes.
- **Onboarding:** a new branch = create branch record, assign region pack, publish catalog,
  register/approve devices, bring up edge stack image — fully templated.

### Consolidation
- Read models aggregate per branch and per chain (sales, inventory, profitability).
- Reporting module builds chain dashboards from event streams / read replicas, never by
  hammering edge nodes.

---

## Future Multi-Tenant SaaS Architecture

Designed now so SaaS is an **enablement, not a migration**. `tenant` sits **above** `branch`
(one tenant = one restaurant company owning many branches).

### Tenancy Model (phased)
1. **Phase 0 (today): single-tenant, multi-branch.** A `tenant_id` column exists on all
   tenant-owned tables with a single default tenant; global scope already applies it.
2. **Phase 1: shared-DB, row-level multi-tenancy.** Multiple tenants share MySQL; every query
   is tenant-scoped by middleware + global scopes; Redis/cache/queue keys are tenant-prefixed;
   storage paths and WebSocket channels are tenant-namespaced.
3. **Phase 2: isolation tiers for large tenants.** Optional **schema-per-tenant** or
   **database-per-tenant** for enterprise customers needing hard isolation, selected per
   tenant via a tenant registry + connection resolver. Same code path.

### Cross-Cutting Tenant Concerns
- **Tenant context** resolved from auth token/subdomain and injected alongside branch/locale.
- **Isolation guarantees:** no query may run without a resolved tenant scope (enforced in a
  base repository + tests); background jobs carry tenant context explicitly.
- **Provisioning:** tenant registry, plan/feature flags, per-tenant region pack defaults,
  onboarding pipeline (create tenant → seed roles/settings → first branch).
- **Noisy-neighbor control:** per-tenant rate limits, queue priorities, and cache namespaces.
- **Data lifecycle:** per-tenant backup/restore, export, and deletion (right-to-erasure).
- **Billing hooks (future):** usage metering per tenant (branches, orders, messages) feeding a
  subscription/billing service.

### Why it stays cheap to enable
- Because scoping, key-prefixing, storage namespacing, and context injection are built from
  day one, turning on SaaS is configuration + a connection resolver — the domain modules are
  untouched.
