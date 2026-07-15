# Top Organic — Enterprise Hybrid Restaurant ERP | البنية المعمارية

**Document set:** Complete software architecture for an enterprise hybrid restaurant ERP
platform targeting large restaurants and restaurant chains, **Iraq-first**.

**Status:** Approved architecture blueprint and source of truth. Implementation is in progress;
the identity foundation is implemented and linked below.

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend framework | **Laravel 12** |
| Language | **PHP 8.3** |
| Primary datastore | **MySQL 8** |
| Cache / broker / locks | **Redis 7** |
| Async processing | **Laravel Queue** (Redis / Horizon) |
| Realtime | **WebSockets** (Laravel Reverb / Echo) |
| Mobile & POS clients | **Flutter** (Android/iOS/desktop) |
| Client ↔ server contract | **REST API** (versioned, JSON) |
| Packaging & runtime | **Docker** / Docker Compose |

---

## Document Index

| # | Document | Sections |
|---|----------|----------|
| 01 | [Requirements](01-requirements.md) | Functional + Non-Functional |
| 02 | [System & Modular Architecture](02-system-architecture.md) | Software architecture, modular architecture |
| 03 | [Hybrid, Multi-Branch & SaaS](03-hybrid-multibranch-saas.md) | Offline/online hybrid, multi-branch, future multi-tenant SaaS |
| 04 | [Data, API, Cache & Storage](04-data-api-cache-storage.md) | Database, API, cache, file storage |
| 05 | [Realtime & Async](05-realtime-sync-queue-ws-notify.md) | Sync engine, queue, WebSocket, notifications |
| 06 | [Security, Logging & Audit](06-security-logging-audit.md) | Security, logging, audit |
| 07 | [DevOps](07-devops-deployment-cicd.md) | Deployment, CI/CD |
| 08 | [Production Database Design](database/README.md) | ER diagrams, complete schema catalog, constraints, isolation, history, sync, audit |

Identity implementation references: [security and operations](../security/authentication-authorization.md),
[API v1](../api/authentication-v1.md), [OpenAPI](../api/openapi-v1.yaml), and
[Flutter flow](../flutter/authentication-flow.md).

---

## Project Context — Iraq First

The platform is built **primarily for Iraq**. Arabic is the primary experience, not a
translation layer added later.

| Setting | Default |
|---------|---------|
| Primary language | **Arabic (RTL)** |
| Secondary language | English (LTR) |
| Country | Republic of Iraq |
| Primary currency | **IQD** (Iraqi Dinar) |
| Secondary currency | USD |
| Time zone | **Asia/Baghdad** |
| Locale | **ar-IQ** |
| Date format | `dd/MM/yyyy` |
| Number format | Arabic locale |

**Non-negotiable localization rules**

- The UI is **designed in Arabic first**; every screen supports **RTL by default**, with
  LTR as a mirror mode for English.
- Database and APIs store and serve **multilingual content** (Arabic + English) for every
  user-facing entity (products, categories, modifiers, branches, roles, etc.).
- All reports, invoices, receipts and printed documents render **Arabic perfectly**
  (correct shaping, RTL layout, Arabic-Indic or Western digits configurable).
- Full **Arabic Unicode** support for employee, customer, supplier and product names.
- Complies with **Iraqi business practices**: Iraqi phone numbers, Iraqi addresses,
  **configurable** Iraqi tax rules, configurable Iraqi working week and holidays.
- IQD currency formatting and future tax configuration are **settings, not code**.
- **Every module is localization-ready**; switching to English requires **no architecture
  change** — only locale/translation data.

---

## Localization Strategy (cross-cutting)

1. **Content i18n (data):** translatable attributes use a normalized `translations` model
   (`translatable_id`, `translatable_type`, `locale`, `field`, `value`) or JSON columns,
   with a resolved-locale fallback chain `ar-IQ → ar → en`.
2. **UI i18n (labels):** Laravel language files (`lang/ar`, `lang/en`) on the server;
   Flutter ARB/intl bundles on clients. Keys are shared and versioned.
3. **Formatting i18n:** locale-aware currency (IQD 0 decimals, USD 2 decimals), dates
   (`dd/MM/yyyy`, Asia/Baghdad), and number/digit shaping resolved centrally.
4. **RTL/LTR:** direction derived from active locale; Flutter `Directionality` and
   server-rendered PDFs both honor it. Bidi-safe templates for mixed Arabic/Latin/numbers.
5. **Documents:** PDF/print pipeline uses Arabic-capable fonts and RTL layout; Excel export
   sets RTL sheet direction; barcode labels, WhatsApp and SMS templates are Arabic-first
   with English variants selectable per recipient/branch.
6. **Configurable regional packs:** tax, phone/address validation, week/holidays, currency
   and digit style live in a `RegionSettings` layer so a future non-Iraq deployment is
   configuration, not a rewrite.

---

## Architecture Principles

- **Modular monolith first**, service-extraction-ready. Clear module boundaries and
  contracts so high-load concerns (sync, reporting) can be split out later.
- **Offline-first at the edge.** POS and Kitchen Display keep operating without internet;
  the cloud is the system of record and reconciler.
- **Multi-branch native.** Branch is a first-class scoping dimension from day one.
- **Multi-tenant ready.** Data model and access layer are designed so a future SaaS mode is
  an enablement, not a migration.
- **Localization-ready everywhere.** No hard-coded strings, currencies, or date formats.
- **API-first.** All clients (Flutter POS, KDS, mobile, admin) consume the same versioned
  REST contract; realtime is an additive channel, never the only path.
- **Secure & auditable by default.** RBAC, tenant/branch isolation, full audit trail.
- **Idempotent & eventually consistent** across the offline/online boundary.
