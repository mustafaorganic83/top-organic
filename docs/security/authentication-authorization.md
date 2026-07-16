# Authentication and Authorization

**Status:** implemented identity foundation for Laravel 12. This document describes the current code, not the future architecture backlog.

## Credential types

| Credential | Consumer | Format | Lifetime and revocation |
|---|---|---|---|
| Web session | Browser admin UI | Server-side Laravel session cookie | Rotated at login, invalidated at logout; CSRF protection applies to browser forms. |
| Access token | API and Flutter online calls | Short-lived signed JWT, `aud=top-organic-api` by default | Valid only while its database `auth_sessions` row, user, tenant, branch grant, device, and version claims remain valid. |
| Refresh token | API token renewal | High-entropy opaque value; only its SHA-256 hash is stored | Rotated once. Reuse revokes the token family and authentication session. Never decode it as a JWT. |
| Offline grant | Authorized POS offline login | Signed JWT, `aud=top-organic-offline-login` by default | Bound to one user, company, branch, and authorized device. It is not accepted by normal API authentication. |

Use different access and offline audiences. Production should sign JWTs with **RS256**, protect the private key on the server, and distribute only the public key to clients that verify offline grants.

## Architecture

1. The client sends company slug, email/Iraqi phone/employee code, password, optional branch/device, and optional remembered-device token.
2. The identifier is normalized, then looked up inside the selected company. Email, phone, and employee code uniqueness is company-scoped.
3. Password and lockout policy are checked. Branch membership and optional device authorization are resolved server-side; context headers cannot override token context.
4. If MFA is required and current-device trust is absent, an incomplete database session and short-lived opaque challenge are created. A successful challenge activates that session.
5. Login returns a short-lived access JWT and an opaque rotating refresh token. Each protected request revalidates token audience, session state, scope, device, and security-version claims against the database.
6. Permission middleware resolves current permissions from the database for the authenticated branch. JWT permission claims are a snapshot, never the final authorization decision.

Browser login uses Laravel's independent `web` guard and never returns API tokens. Accounts requiring MFA are currently rejected by browser login; use the API MFA flow until a browser MFA UI is added.

## Database table map

| Table | Purpose and important invariants |
|---|---|
| `tenants` | Restaurant company boundary and active state. |
| `branches`, `branch_user` | Branch boundary and direct user grants. Branch IDs are ULIDs. |
| `users` | Numeric internal PK plus public ULID; company-scoped email, canonical phone, and employee code; password hash, account/lock fields, MFA flag, and password/security/authorization versions. |
| `tenant_security_policies` | One row per company: lockout, password, token, trust, MFA, and offline limits. |
| `permission_groups`, `permissions` | Global permission catalog. API exposes public permission ULIDs, never numeric PKs. |
| `roles`, `permission_role`, `role_user` | Company roles and legacy/chain-wide assignments. Roles have public ULIDs; system roles are immutable. |
| `user_branch_roles` | Effective, expiring, revocable branch role grants with grant/revoke actors. |
| `devices` | Company/branch POS, kiosk, mobile, or desktop lifecycle; fingerprint uniqueness is company-scoped. |
| `auth_sessions` | API session anchor with branch/device, MFA state, versions, expiry, and revocation. This is distinct from Laravel web `sessions`. |
| `refresh_tokens` | Hashed opaque rotating tokens, family lineage, use, expiry, replacement, and revocation. |
| `remembered_devices` | One hashed trust token per user/device, with expiry, use, and revocation. |
| `mfa_methods` | Pluggable method metadata and encrypted/hashed credential material. |
| `mfa_challenges` | Hashed, expiring, attempt-limited, single-use challenges tied to an API session. |
| `mfa_recovery_codes` | SHA-256 recovery-code hashes and one-time `used_at` marker. |
| `password_histories` | Previous password hashes retained according to company policy. |
| `offline_login_grants` | Hashed signed-grant identity, scope, permission snapshot, versions, expiry, and revocation. |
| `offline_login_receipts` | Idempotent reconnect receipts, unique by grant and client receipt ULID. |
| `audit_logs` | Append-only, tenant/branch-sequenced, hash-chained security/authorization records. |
| `sessions` | Laravel web-session storage when the configured session driver uses the database. Not an API auth session. |

## Security invariants

### Passwords and lockout

- Passwords use Laravel's configured one-way hasher. Plain passwords and recovery codes are never stored or logged.
- Company policy controls minimum length and history. Environment flags require letters, numbers, and optionally symbols.
- Failed attempts and timed lockout are database-backed and transactionally updated. Unknown users/companies receive the same `INVALID_CREDENTIALS` response and a dummy hash check.
- Password change requires the current password, rejects recent reuse, increments password/security versions, and revokes every API session.

### MFA

- MFA is required when company policy requires it or the user has `two_factor_enabled`.
- Challenge values are opaque; only hashes are stored. Challenges expire, have a configured maximum attempt count, and are consumed after success or exhaustion.
- Recovery codes are **currently implemented** and single-use.
- MFA provider adapters are pluggable through `MfaMethodVerifier`. TOTP, SMS, and WebAuthn require an adapter class and provider/credential configuration before those methods can be used; an enrolled method with no adapter fails closed with `MFA_METHOD_UNAVAILABLE`.

### Devices and remembered trust

- Device lifecycle is pending → authorized → revoked. Revocation terminates device sessions.
- Login accepts only an authorized, non-revoked device in the same company and compatible branch.
- Trust can be issued only from an authenticated session on that exact current authorized device. The opaque trust token is hashed at rest and bound to user, company, and device.
- A token from device A cannot suppress MFA on device B. Revocation and expiry restore MFA.

### Sessions and tokens

- Access JWT validation requires the API audience, public user ID, session ID, company/branch/device claims, and password/security/authorization versions to match live state.
- Refresh tokens are opaque, hashed, rotating, and one-use. Replay revokes the family and session.
- Session list returns only the current user's active, unexpired sessions. A user can revoke only their own session; logout-all revokes all of them.
- Removing a branch grant, role, device, user, or company invalidates subsequent requests even if the JWT has not expired.

### Offline login

- Issuance requires company policy, a granted branch, and an authorized device assigned to that branch.
- The signed JWT contains offline audience, public subject, grant/company/branch/device IDs, permission snapshot, version claims, and standard `iat`/`nbf`/`exp`/`jti` claims.
- Normal API auth rejects an offline token because its audience and claims do not describe an API session.
- Offline authorization is limited to the permission snapshot and expiry. Revocation/version changes are observed only after reconnect; clients must therefore keep grants short and constrain offline operations.
- Receipts are idempotent by `(offline_login_grant_id, client_receipt_id)`. Replaying an ID returns the first stored result instead of creating a second event.

### RBAC, scope, and audit

- UI visibility may be role-driven, but server permission middleware and domain checks are authoritative.
- All protected context comes from the validated session. Client tenant/branch/device headers are ignored.
- Company lookups hide foreign resources. Branch-scoped administrators cannot manage devices, grants, or audit data outside their authenticated branch; chain-wide role assignments may span granted branches.
- Roles and permissions are public-ID APIs. Numeric user/role/permission primary keys remain internal.
- Login, logout/revocation, device, role, and offline-grant events are audited without secrets. Audit rows are immutable and hash chained per scope.

## Configuration

| Setting | Purpose |
|---|---|
| `JWT_ALGO` | JWT signing algorithm; use `RS256` in production. |
| `JWT_PUBLIC_KEY`, `JWT_PRIVATE_KEY`, `JWT_PASSPHRASE` | Key file/resource paths and optional secret-manager supplied passphrase. Never embed PEM contents in environment templates or Flutter. |
| `JWT_TTL`, `JWT_REFRESH_TTL` | Package defaults; company policy values control issued identity token/session lifetimes. |
| `JWT_BLACKLIST_ENABLED`, `JWT_BLACKLIST_GRACE_PERIOD` | Package blacklist behavior; keep enabled unless an equivalent revocation design is proven. |
| `IDENTITY_ACCESS_AUDIENCE`, `IDENTITY_OFFLINE_AUDIENCE` | Distinct API and offline audiences. |
| `IDENTITY_REQUIRE_AUTHORIZED_DEVICE` | Require a device for every API login. |
| `IDENTITY_PASSWORD_REQUIRE_*` | Global composition floor layered with company minimum/history policy. |
| `IDENTITY_MFA_CHALLENGE_TTL`, `IDENTITY_MFA_MAX_ATTEMPTS` | Challenge expiry and attempt limit. |
| `identity.mfa.verifiers` | Adapter class list for configured TOTP/SMS/WebAuthn providers. |

Company policy supplies failed-attempt limit, lockout minutes, password minimum/history, access and refresh TTLs, remembered-device days, offline hours, and feature flags.

## Rollout and operations

1. Generate a dedicated production RSA key pair outside the repository. Restrict private-key access to the API runtime; publish the matching public key through the approved app configuration channel.
2. Configure distinct audiences and short access/offline TTLs. Confirm company policies before enabling MFA, remembered devices, required devices, or offline login.
3. Seed the permission catalog/system roles, create company roles, grant initial chain/branch administrators, then register and approve devices.
4. Configure MFA adapters before enabling non-recovery methods. Provision recovery codes through a secure authenticated operational flow and display each plaintext code once.
5. Roll clients in online-only mode first. Enable offline grants only after RS256 verification, encrypted PIN verification, permission enforcement, receipt upload, and reconnect revocation handling are tested.
6. Monitor `login.failed`, lockouts, refresh reuse, device revocation, MFA exhaustion, offline receipt backlog, and audit-chain verification. Alert on repeated cross-scope denials and token replay.
7. Key rotation: add/distribute the new public key, switch signing private key, allow only the maximum token lifetime for overlap, then retire the old key. Current tokens do not carry a dedicated key-discovery endpoint, so coordinate app configuration and server deployment.

Never log bearer/refresh/trust/challenge/offline tokens, passwords, PINs, recovery codes, private keys, or MFA provider secrets.