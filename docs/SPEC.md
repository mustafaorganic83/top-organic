# Specification | المواصفات

> **This file is superseded by the full architecture.**
> The authoritative, detailed specification now lives in
> [`docs/architecture/`](architecture/README.md) — 8 documents covering all 20 sections
> (requirements, system/modular design, hybrid & multi-tenant, data/API/cache/storage,
> sync/queue/WebSocket/notifications, security/logging/audit, DevOps/CI-CD).

> **هذا الملف استُبدل بالمعمارية الكاملة.**
> المواصفات التفصيلية المعتمدة أصبحت في [`docs/architecture/`](architecture/README.md).

---

## High-level scope | النطاق العام

**Top Organic** is an Enterprise Hybrid Restaurant ERP Platform for large chains in Iraq,
built on **Laravel 12 / PHP 8.3 / MySQL / Redis** with **Flutter** clients (POS / KDS / mobile).

Core modules to be built (per the architecture): **menu, orders, tables, billing, staff,
reports**, plus the cross-cutting **sync engine**, **localization (Arabic RTL)**,
**multi-branch / multi-tenant** foundations, and **security/audit**.

Start with **Core Setup** (Laravel project + module skeleton, RBAC, Sanctum auth,
Iraq region defaults), then implement each module against the architecture docs.
