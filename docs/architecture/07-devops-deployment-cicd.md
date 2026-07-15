# 07 — Deployment & CI/CD

## Deployment Strategy

**Everything is containerized with Docker.** Cloud and branch edge share the **same images**
and a common configuration model — only environment/config differs.

### Runtime Components (Docker)
| Component | Role |
|-----------|------|
| `app` (PHP-FPM, Laravel 12) | stateless HTTP API |
| `web` (Nginx) | TLS termination / reverse proxy |
| `reverb` | WebSocket server |
| `worker` (Horizon) | queue workers (realtime/sync/documents/messaging/reports) |
| `scheduler` | Laravel cron (rollups, retention, report delivery) |
| `mysql` | primary (+ replicas in cloud) |
| `redis` | cache, locks, queue, broadcast backplane |
| `minio`/S3 | object storage (edge: local; cloud: S3-compatible) |

### Cloud Deployment
- Orchestrated (Docker Compose for small; **Kubernetes** for scale) with horizontal scaling of
  `app`, `worker`, and `reverb`; MySQL primary + read replicas; Redis (clustered).
- **Stateless app tier** → scale by replicas behind a load balancer; sessions/cache in Redis.
- **Zero-downtime deploys:** rolling updates + health/readiness probes; **expand/contract**
  DB migrations so old and new app versions run during rollout.
- Environments: **dev → staging → production**, isolated credentials/data; infra as code.

### Branch Edge Deployment
- A single **edge stack** image bundle (app-edge + mysql + redis + reverb) deployed to a branch
  mini-server via Docker Compose; brought up with **one command** from a template.
- Runs offline; syncs to cloud when online. Edge upgrades are **staged and version-negotiated**
  so a branch can lag the cloud safely (backward-compatible APIs + migrations).
- Device clients (Flutter POS/KDS) auto-detect edge on LAN, fall back to cloud/local store.

### Configuration & Secrets
- 12-factor config via environment; **region packs** (Iraq defaults: IQD, ar-IQ,
  Asia/Baghdad, tax, week/holidays) shipped as seed data/config, switchable per
  tenant/branch with no image change.
- Secrets from a secret manager/env injection — never baked into images; rotated per env.

### Backup, DR & Rollback
- Automated cloud DB backups + object-storage versioning; **tested restore** drills.
- Edge snapshots; sync bounds edge RPO. Targets: cloud **RPO ≤ 5 min / RTO ≤ 1 h**.
- Rollback: previous image tags kept; contract-safe migrations allow app rollback without DB
  rollback; feature flags disable risky features without redeploy.

### Release Management
- **Semantic versioning** for API and app; client apps advertise version and can be
  force-upgraded via the `devices` channel when a breaking change ships.
- Progressive delivery: **canary a pilot branch**, then chain-wide rollout.

---

## CI/CD Strategy

**Pipeline (GitHub Actions):** on every PR and on merge to protected branches.

### Continuous Integration
1. **Setup:** PHP 8.3, Composer, Node (for asset/Flutter tooling where relevant).
2. **Static quality:** lint (PHP-CS-Fixer/Pint), static analysis (PHPStan/Larastan),
   architecture boundary checks (module dependency rules).
3. **Tests:** unit + feature tests against ephemeral MySQL/Redis services; **sync/conflict
   simulation** tests; **localization tests** (RTL, Arabic PDF/receipt/Excel snapshots).
4. **Contract tests:** validate REST against the OpenAPI spec; break the build on drift.
5. **Flutter CI:** analyze + widget/golden tests (RTL golden screenshots) for POS/KDS/mobile.
6. **Security:** dependency audit (Composer/pub), container image scanning, secret scanning.
7. **Build artifacts:** build and tag Docker images (cloud + edge) and Flutter app bundles;
   push to registry on success.

### Continuous Delivery/Deployment
- **Environments gated:** auto-deploy to **staging** on merge; **production** requires approval.
- **DB migrations** run as a controlled step (expand phase pre-deploy, contract phase post-
  verify) for zero downtime.
- **Progressive rollout:** deploy → smoke/health checks → canary branch → full rollout; auto-
  rollback on failed health metrics.
- **Edge delivery:** edge image bundles published to a channel; branches pull/upgrade on a
  schedule with version negotiation and staged rollout (pilot branches first).
- **Mobile delivery:** Flutter builds shipped via app stores / MDM for managed devices;
  in-app version gate coordinates with API version.

### Quality Gates (block merge/deploy)
- All tests green, coverage threshold met, static analysis clean, no high-severity vulns,
  OpenAPI contract satisfied, migrations reviewed as backward-compatible.

### Observability of Delivery
- Deploy markers correlated with logs/metrics; every release tagged; changelog generated;
  post-deploy synthetic checks on POS→pay→KDS→sync happy path.
