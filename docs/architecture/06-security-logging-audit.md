# 06 — Security, Logging & Audit

## Security Architecture

Defense-in-depth across identity, data, network, application, and devices.

### Identity & Access
- **Authentication:** token-based (Laravel Sanctum/Passport); short-lived access + refresh
  tokens; **device-bound tokens** for POS/KDS; POS PIN fast-login layered over device trust;
  optional MFA for admin/accountant roles.
- **Authorization (RBAC):** granular permissions grouped into roles; enforced by policies at
  the domain boundary (not just UI). Scopes on API tokens mirror permissions.
- **Tenant/branch isolation:** every request resolves tenant+branch context; **global query
  scopes** and a base repository make an unscoped query impossible; enforced by tests.
- **Device lifecycle:** register → approve → active → revoked; a lost terminal is revoked
  centrally, killing its tokens and channels.

### Data Protection
- **In transit:** TLS everywhere (cloud APIs, WebSockets, edge↔cloud sync); certificate
  management automated.
- **At rest:** encrypted volumes for MySQL/Redis/object storage; application-level encryption
  for sensitive fields (customer PII, credentials); Iraqi phone/PII minimized and access-logged.
- **Secrets:** never in code or images — injected via environment/secret manager; rotated;
  distinct per environment and per tenant where applicable.
- **Money & finance:** immutable settled records; permissioned overrides/voids; dual-control
  for high-risk actions (large discounts/refunds) with audit.

### Application Security
- Server-side validation on all inputs (Arabic Unicode-safe); output encoding; ORM parameter
  binding (no raw string SQL) to prevent injection.
- Protection against CSRF (web/admin), XSS, mass-assignment (explicit fillable/DTOs), SSRF on
  outbound provider calls, and file-upload abuse (type/size/AV checks).
- **Rate limiting & abuse control** per token/branch/tenant (Redis); stricter on auth and
  messaging endpoints.
- **Idempotency guards** double as replay-attack protection on sync/write endpoints.
- Dependency and image scanning in CI; least-privilege service accounts.

### Network & Infrastructure
- Private networking between app, MySQL, Redis, storage; datastores never public.
- WAF/reverse proxy at the edge of the cloud; edge branch nodes expose only LAN services +
  outbound sync (no inbound from internet).
- Segmented environments (dev/staging/prod) with separate credentials and data.

### Compliance & Privacy (Iraq-aware)
- Configurable data retention and PII handling; per-tenant export & right-to-erasure.
- Iraqi tax/business rules are configurable settings; audit proves who changed them and when.
- Access to PII and financial data is role-gated and fully audited.

---

## Logging Strategy

- **Structured JSON logs** (correlation id, tenant, branch, device, user, request id) across
  app, queue workers, WebSocket, and edge nodes.
- **Levels & routing:** debug/info/warn/error/critical; security and financial events routed to
  dedicated streams with tighter retention/alerting.
- **Centralized aggregation:** ship to a central stack (e.g., ELK/OpenSearch or Loki/Grafana);
  edge nodes buffer logs offline and forward on reconnect.
- **Correlation & tracing:** a request/sync operation carries a trace id end-to-end
  (client → API → queue → broadcast) for fast root-cause; optional OpenTelemetry traces.
- **Metrics & alerting:** API latency/error rates, queue depth/lag, sync backlog & conflicts,
  WebSocket connections, provider (WhatsApp/SMS) delivery/failures, DB/Redis health → alerts.
- **PII discipline:** logs **never** contain secrets, full tokens, card data, or raw PII; such
  values are redacted/hashed. Log access is restricted and itself audited.
- **Retention:** operational logs short/medium term; security logs longer; configurable per
  tenant and per environment.

---

## Audit Strategy

Audit is a **first-class, immutable** concern — the source of truth for "who did what, when,
where" across branches and (future) tenants.

### What is Audited
- All security events (login, permission/role change, device approval/revocation, token issue).
- All financial actions (sale, payment, refund, void, discount override, price/tax change,
  cash drawer, shift open/close).
- Reference/config changes (menu, prices, region pack, tax, holidays, settings, feature flags).
- Sync conflicts and their resolutions; data exports and deletions.

### Design
- **Append-only audit store:** immutable records with actor, action, entity + before/after
  snapshot (or diff), tenant/branch/device, source (POS/edge/cloud), timestamp (UTC), and
  correlation id. No updates/deletes on audit rows.
- **Captured via domain events:** listeners write audit entries so coverage is uniform and can't
  be bypassed by a code path that forgets to log.
- **Offline-safe:** audit entries are generated at the edge and synced (idempotently) with the
  transactions they describe — the trail survives offline operation.
- **Tamper-evidence:** optional hash-chaining/sequence per branch so gaps/edits are detectable.
- **Access & reporting:** role-gated Arabic audit browser with filters (actor, entity, date,
  branch); exportable to Arabic PDF/Excel for owners/auditors and Iraqi compliance needs.
- **Retention:** configurable, typically longer than operational logs; financial/audit
  retention aligned with business/tax record-keeping expectations.
