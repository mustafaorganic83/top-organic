# 02 — System & Modular Architecture

## Software Architecture

### Style
- **Modular monolith** built on Laravel 12 (PHP 8.3), organized as internal **bounded
  modules** with explicit contracts, deployable as a single service but **extraction-ready**
  (sync, reporting, notifications can become separate services under load).
- **Layered inside each module:** `API/Http` → `Application (services, commands, DTOs)` →
  `Domain (entities, value objects, policies)` → `Infrastructure (Eloquent repos, gateways)`.
- **Hexagonal boundaries:** the domain depends on interfaces (ports); MySQL, Redis, WhatsApp,
  SMS, storage, and aggregators are adapters. This keeps region packs and integrations
  pluggable and testable.

### Runtime Topology (logical)
```
Flutter clients (POS / KDS / Manager mobile / Admin)
        │  REST (HTTPS, versioned)        ▲ WebSockets (Reverb)
        ▼                                 │
┌───────────────────────── Cloud ─────────────────────────┐
│  API Gateway / LB                                        │
│  Laravel App (stateless HTTP)  ── Redis (cache/locks)    │
│  WebSocket servers (Reverb)    ── Queue workers (Horizon)│
│  MySQL (primary + read replicas)                         │
│  Object storage (S3-compatible) · Scheduler · Cron       │
└──────────────────────────────────────────────────────────┘
        ▲ idempotent sync (pull/push)
        │
┌────────────────────── Branch Edge ───────────────────────┐
│  Edge node (Docker): Laravel edge API + MySQL + Redis     │
│  serves POS/KDS on LAN, buffers offline, syncs to cloud   │
└──────────────────────────────────────────────────────────┘
```

### Key Cross-Cutting Concerns
- **Context resolution middleware:** resolves `tenant` (future), `branch`, `locale`,
  `currency`, `timezone`, `region pack`, and `device` on every request and injects them
  into a request-scoped context used by services, formatters, and query scopes.
- **Global query scoping:** automatic `branch_id` (and future `tenant_id`) scoping via
  Eloquent global scopes to prevent cross-branch/tenant leakage.
- **Idempotency layer:** every mutating write accepts an idempotency key (client-generated),
  enforced with Redis + a unique constraint, essential for offline replay.
- **Event bus:** domain events (OrderPlaced, PaymentCaptured, StockAdjusted) drive queues,
  WebSocket broadcasts, notifications, audit, and analytics via listeners.

---

## Modular Architecture

Each module owns its schema slice, service contract, events, permissions, and translations.
Modules communicate **through published interfaces/events**, never by reaching into each
other's tables.

| Module | Responsibility | Publishes (events) | Depends on (contracts) |
|--------|----------------|--------------------|------------------------|
| **Core/Platform** | context, region packs, settings, feature flags, i18n | SettingsChanged | — |
| **Identity & Access** | users, roles, permissions, devices, auth | UserProvisioned, DeviceApproved | Core |
| **Catalog** | menu, products, modifiers, recipes, price lists | ProductPublished, PriceChanged | Core |
| **POS/Orders** | orders, tables, shifts, discounts | OrderPlaced, OrderUpdated | Catalog, Identity, Pricing |
| **KDS** | station routing, ticket state | TicketBumped | POS, Realtime |
| **Payments/Billing** | tenders, invoices, tax, dual-currency | PaymentCaptured, InvoiceIssued | POS, Core(tax/fx) |
| **Inventory** | stock, POs, receipts, transfers, wastage | StockAdjusted, LowStock | Catalog, Suppliers |
| **Suppliers/Procurement** | suppliers, costing | PurchaseReceived | Core |
| **CRM/Loyalty** | customers, addresses, loyalty | CustomerUpserted | Core, Notifications |
| **Delivery** | zones, drivers, aggregators | DeliveryDispatched | POS, CRM |
| **HR/Workforce** | staff, shifts, attendance, holidays | ShiftClosed | Identity, Core |
| **Reporting/Analytics** | read models, PDF/Excel, dashboards | ReportGenerated | (reads events/read DB) |
| **Notifications** | WhatsApp, SMS, push, in-app, templates | NotificationSent | Core |
| **Sync** | offline capture, push/pull, conflict resolution | SyncApplied, ConflictRaised | all (via outbox) |
| **Audit** | immutable audit trail | — (consumes all) | all |

### Module Contracts & Boundaries
- Public surface of a module = its **service interface + DTOs + events + permission set**.
- No direct foreign keys across module tables in the future service-split path; use IDs +
  read models. Within the monolith, FKs are allowed but access goes through repositories.
- **Anti-corruption layer** for external systems (aggregators, payment providers, telecom
  SMS/WhatsApp gateways) so provider changes never leak into the domain.

### Client Application Architecture (Flutter)
- **Clean architecture** per app (POS, KDS, Manager, Admin): presentation (RTL-first
  widgets) → application (BLoC/Riverpod use-cases) → data (repositories) → local store.
- **Offline-first data layer:** local SQLite/Drift + outbox; repositories read local first,
  reconcile via Sync module. Shared packages: `design_system` (RTL/theme), `l10n`,
  `api_client`, `sync_client`, `formatting` (IQD/USD, dates, digits).
- One codebase, role/device-driven navigation; POS and KDS can run on the same or separate
  devices on the branch LAN.

### Testing & Quality Boundaries
- Domain unit tests per module; contract tests on module interfaces & REST endpoints;
  sync/conflict simulation tests; localization snapshot tests (RTL/PDF/receipt rendering).
