# 01 — Requirements

## Functional Requirements

Grouped by module. Each module is **branch-scoped**, **localization-ready**, and exposed via
the REST API.

### FR-1 Identity, Roles & Access
- Staff accounts with Arabic/English names, Iraqi phone login, PIN for POS fast-login.
- Role-Based Access Control: roles (owner, chain manager, branch manager, cashier, waiter,
  kitchen, accountant, storekeeper, auditor) with granular permissions.
- Branch- and chain-level scoping; a user may be granted one or many branches.
- Session/device management for POS and KDS terminals (device registration & approval).

### FR-2 Menu & Catalog
- Categories, products, variants, modifiers/add-ons, combos, recipes (BOM).
- Multilingual name/description; per-branch availability, price lists and overrides.
- Barcodes/SKUs; Arabic barcode labels; images; allergen/tags; scheduling (breakfast menu).

### FR-3 Point of Sale (POS)
- Arabic-first, offline-capable POS: dine-in, takeaway, delivery, drive-through.
- Table & floor management, order splitting/merging, seat-level items, course firing.
- Order lifecycle: draft → placed → preparing → ready → served → settled → closed.
- Discounts, service charge, tips, price overrides (permissioned), voids/returns.
- Held/parked orders, shift open/close, cash drawer, X/Z reports.

### FR-4 Kitchen Display System (KDS)
- Arabic KDS per station (grill, cold, bar); ticket routing by category/station.
- Real-time order tickets, bump/recall, prep timers, SLA coloring, offline resilience.

### FR-5 Payments & Invoicing
- Multi-tender: cash (IQD/USD), card, wallet, on-account; mixed payments & change.
- Dual-currency handling with configurable IQD⇄USD exchange rate and rounding rules.
- Arabic invoices/receipts; configurable **Iraqi tax** (VAT/sales/service) as settings.
- Refunds, partial payments, deferred/credit sales, customer accounts.

### FR-6 Inventory & Supply Chain
- Stock per branch and central warehouse; units & conversions; recipe deduction on sale.
- Purchase orders, goods receipt, supplier management (Arabic names), returns to supplier.
- Stock counts, wastage, transfers between branches, low-stock alerts, expiry tracking.

### FR-7 Customers & CRM
- Customer profiles (Arabic names, Iraqi phones/addresses), order history, loyalty points.
- Delivery zones/addresses; WhatsApp/SMS engagement; feedback capture.

### FR-8 Delivery & Aggregators
- In-house delivery (driver assignment, live status) and third-party aggregator orders.
- Order source tagging, courier tracking, delivery fees per zone.

### FR-9 HR & Workforce (restaurant-scoped)
- Employees, shifts/rosters, attendance/clock-in, configurable **Iraqi working week &
  holidays**, basic payroll inputs, tips distribution.

### FR-10 Procurement Costing & Pricing
- Cost tracking (moving average), menu-item cost & margin, price list management.

### FR-11 Reporting & Analytics
- Sales, product mix, branch comparison, shift, tax, inventory, wastage, profitability.
- Arabic **PDF** reports and Arabic **Excel** export; scheduled report delivery.
- Chain-level consolidated dashboards across all branches.

### FR-12 Notifications & Messaging
- WhatsApp and SMS in Arabic (order status, receipts, marketing), in-app & push.
- Templated, localized, per-branch sender identity.

### FR-13 Administration & Settings
- Region pack (currency, tax, phone/address, week/holidays, digit style, date format).
- Branch onboarding, menu publishing, device management, feature flags.
- Full audit log browser; data export; backup/restore controls.

### FR-14 Synchronization
- Offline capture at POS/KDS with reliable, idempotent sync to cloud when online.
- Conflict detection/resolution, replay, and per-device sync status visibility.

---

## Non-Functional Requirements

### NFR-1 Performance
- POS local actions (add item, print, pay) respond **< 100 ms** offline (local store).
- Server API p95 **< 300 ms** for common reads; **< 800 ms** for heavy reports (cached).
- KDS ticket propagation **< 1 s** over WebSockets on healthy networks.

### NFR-2 Availability & Resilience
- Branch operations **continue fully offline**; no cloud dependency for selling.
- Cloud target **99.9%** monthly availability; graceful degradation, no data loss.
- Every sync operation is **idempotent** and safely retriable.

### NFR-3 Scalability
- Scale to **hundreds of branches** and thousands of concurrent POS/KDS devices.
- Horizontal scaling of API, queue workers, and WebSocket servers behind Redis.

### NFR-4 Localization & Accessibility
- Arabic RTL correctness across UI, print, PDF, Excel, labels, and messages.
- Locale/currency/date switch with **no architecture change**; digit-shape configurable.
- WCAG-oriented contrast/scaling; large-touch POS ergonomics.

### NFR-5 Security & Compliance
- Encryption in transit (TLS) and at rest; RBAC; least privilege; secrets never in code.
- Tenant/branch data isolation; full audit trail; configurable data retention.
- Alignment with Iraqi business/tax practices (configurable, not hard-coded).

### NFR-6 Data Integrity & Consistency
- Strong consistency inside a branch/order; **eventual consistency** across the sync
  boundary with deterministic conflict rules and monotonic clocks/vector info.
- Financial records are append-only/immutable once settled (corrections via new entries).

### NFR-7 Observability
- Centralized structured logging, metrics, tracing, sync dashboards, alerting.

### NFR-8 Maintainability & Extensibility
- Modular boundaries with contracts; versioned APIs; feature flags; automated tests.
- Region packs and integrations are pluggable; SaaS multi-tenancy is an enablement.

### NFR-9 Deployability
- Reproducible Docker images; one-command environment bring-up; zero-downtime deploys.
- On-prem/edge branch runtime and cloud runtime share the same images/config model.

### NFR-10 Backup & Recovery
- Automated cloud backups with tested restore; edge snapshotting; defined RPO/RTO
  (target **RPO ≤ 5 min** cloud, **RTO ≤ 1 h**; edge RPO bounded by sync interval).
