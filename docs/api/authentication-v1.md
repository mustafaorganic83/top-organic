# Authentication API v1

Base path: `/api/v1`. JSON is required for request bodies. The 34 versioned routes below are the implemented identity contract; `/login` and `/logout` are separate browser-session routes.

## Envelopes, authentication, and IDs

Success: `{ "data": ... }`. Lists add `{ "meta": { "current_page", "per_page", "total", "last_page" } }`.

Failure: `{ "error": { "code": "STABLE_CODE", "message": "Human-readable text", "fields": {} } }`; `fields` appears only for validation. Clients branch on `code`, not message.

Send access JWTs as `Authorization: Bearer <access-token>`. Login, MFA completion, refresh, and device registration are public and rate-limited. Access JWTs are short-lived and signed; refresh tokens are opaque rotating secrets. Offline JWTs have a different audience and are never API bearer credentials.

User, role, and permission responses expose public ULIDs. Company, branch, device, session, grant, receipt, challenge, and grant-assignment IDs are ULIDs. Numeric internal user/role/permission keys are never accepted or returned.

## Endpoint contracts (34)

`Auth` below means API bearer auth plus validated company/branch/device context. `Permission` is re-resolved from the database.

| # | Method and path | Auth / input | Success | Principal errors |
|---:|---|---|---|---|
| 1 | `POST /auth/login` | Public. `tenant_slug`, `identifier`, `password`; optional `branch_id`, `device_id`, `remembered_device_token`. | `200` token pair or `202` MFA challenge. | `401 INVALID_CREDENTIALS`; `403 BRANCH_ACCESS_DENIED`, `DEVICE_NOT_AUTHORIZED`; `422 DEVICE_REQUIRED`, validation/identifier; `423 ACCOUNT_LOCKED`. |
| 2 | `POST /auth/mfa/complete` | Public. `challenge_token`, `response`. | `200` token pair. | `401 MFA_CHALLENGE_INVALID`, `MFA_RESPONSE_INVALID`; `503 MFA_METHOD_UNAVAILABLE`. |
| 3 | `POST /auth/refresh` | Public. `refresh_token`. | `200` rotated token pair. | `401 REFRESH_TOKEN_INVALID`, `REFRESH_TOKEN_EXPIRED`, `SESSION_INVALID`; `409 REFRESH_TOKEN_REUSED`. |
| 4 | `POST /devices/register` | Public. Company, optional branch, code/name/type, key fingerprint, optional public key/app/OS. | `201` pending device. | `409 DEVICE_ALREADY_REGISTERED`; `422 DEVICE_REGISTRATION_INVALID`, validation. |
| 5 | `GET /me` | Auth. | `200` current user/context/permissions. | `401 UNAUTHENTICATED`; `403 TENANT_ACCESS_DENIED`, `BRANCH_ACCESS_DENIED`, `DEVICE_NOT_AUTHORIZED`. |
| 6 | `POST /auth/logout` | Auth. | `200 {requires_relogin:true}`. | `401 UNAUTHENTICATED`. |
| 7 | `POST /auth/logout-all` | Auth. | `200` revoked count and relogin flag. | `401 UNAUTHENTICATED`. |
| 8 | `POST /auth/password` | Auth. Current/new/confirmation. | `200` revoked count and relogin flag. | `422 CURRENT_PASSWORD_INVALID`, `PASSWORD_POLICY_VIOLATION`, `PASSWORD_REUSED`, validation. |
| 9 | `POST /auth/change-password` | Alias of #8. | Same as #8. | Same as #8. |
| 10 | `GET /sessions` | Auth. Optional `page`, `per_page`. | `200` active sessions. | `401 UNAUTHENTICATED`; `422` validation. |
| 11 | `DELETE /sessions/{session}` | Auth; own session ULID. | `200 {revoked:true}`. | `404 SESSION_NOT_FOUND`. |
| 12 | `POST /devices/{device}/trust` | Auth on the same current authorized device. | `201` opaque trust token and expiry. | `403 REMEMBER_DEVICE_NOT_ALLOWED`; `404 DEVICE_NOT_FOUND`. |
| 13 | `DELETE /devices/{device}/trust` | Auth; same-company device. | `200 {revoked:true}`. | `404 DEVICE_NOT_FOUND`. |
| 14 | `POST /offline-grants` | Auth. `branch_id`, `device_id`. | `201` grant metadata plus signed offline JWT. | `403 OFFLINE_LOGIN_NOT_ALLOWED`; `422` validation. |
| 15 | `GET /offline-grants` | Auth. Optional pagination. | `200` caller's grants, without tokens. | `401 UNAUTHENTICATED`. |
| 16 | `DELETE /offline-grants/{grant}` | Auth; own grant. Optional `reason`. | `200 {revoked:true}`. | `404 OFFLINE_GRANT_NOT_FOUND`. |
| 17 | `POST /offline-grants/{grant}/receipts` | Auth; own grant. Receipt ULID, result, occurrence time, optional metadata. | `201` stored or previously stored idempotent receipt. | `404 OFFLINE_GRANT_NOT_FOUND`; `422` validation. |
| 18 | `GET /admin/permission-groups` | Permission `identity.permissions.view`. | `200` grouped catalog. | `403 PERMISSION_DENIED`. |
| 19 | `GET /admin/permission-catalog` | Alias of #18. | Same as #18. | Same as #18. |
| 20 | `GET /admin/permissions` | Permission `identity.permissions.view`; pagination. | `200` flat permission list. | `403 PERMISSION_DENIED`; `422` validation. |
| 21 | `GET /admin/roles` | Permission `identity.roles.view`; status/pagination. | `200` company roles. | `403 PERMISSION_DENIED`. |
| 22 | `POST /admin/roles` | Permission `identity.roles.manage`; name/label, optional description/status/permission IDs. | `201` role. | `409 ROLE_ALREADY_EXISTS`; `422 PERMISSION_NOT_FOUND`, validation. |
| 23 | `GET /admin/roles/{role}` | Permission `identity.roles.view`; public role ULID. | `200` role. | `404 ROLE_NOT_FOUND`. |
| 24 | `PATCH /admin/roles/{role}` | Permission `identity.roles.manage`; partial role fields. | `200` role. | `404 ROLE_NOT_FOUND`; `409 ROLE_ALREADY_EXISTS`, `SYSTEM_ROLE_IMMUTABLE`; `422`. |
| 25 | `PUT /admin/roles/{role}/permissions` | Permission `identity.roles.manage`; `permission_ids`. | `200` role with permissions. | `404 ROLE_NOT_FOUND`; `409 SYSTEM_ROLE_IMMUTABLE`; `422 PERMISSION_NOT_FOUND`. |
| 26 | `DELETE /admin/roles/{role}` | Permission `identity.roles.manage`. | `200 {deleted:true}`. | `404 ROLE_NOT_FOUND`; `409 SYSTEM_ROLE_IMMUTABLE`. |
| 27 | `POST /admin/users/{user}/branches/{branch}/roles/{role}` | Permission `identity.roles.assign`; all public/scope ULIDs. | `201` role grant. | `403 BRANCH_SCOPE_VIOLATION`; `404 ROLE_GRANT_TARGET_NOT_FOUND`. |
| 28 | `DELETE /admin/role-grants/{grant}` | Permission `identity.roles.assign`; optional reason. | `200 {revoked:true}`. | `403 BRANCH_SCOPE_VIOLATION`; `404 ROLE_GRANT_NOT_FOUND`. |
| 29 | `GET /admin/devices` | Permission `identity.devices.view`; status/branch/pagination. | `200` scope-filtered devices. | `403 BRANCH_SCOPE_VIOLATION`, `PERMISSION_DENIED`. |
| 30 | `GET /admin/devices/{device}` | Permission `identity.devices.view`. | `200` device, no key/fingerprint. | `403 BRANCH_SCOPE_VIOLATION`; `404 DEVICE_NOT_FOUND`. |
| 31 | `POST /admin/devices/{device}/approve` | Permission `identity.devices.manage`. | `200` authorized device. | `403 BRANCH_SCOPE_VIOLATION`; `404 DEVICE_NOT_FOUND`; `409 DEVICE_REVOKED`. |
| 32 | `POST /admin/devices/{device}/revoke` | Permission `identity.devices.manage`; optional reason. | `200` revoked device. | `403 BRANCH_SCOPE_VIOLATION`; `404 DEVICE_NOT_FOUND`. |
| 33 | `GET /admin/audit` | Permission `identity.audit.view`; category/branch/pagination. | `200` scope-filtered audit list. | `403 BRANCH_SCOPE_VIOLATION`, `PERMISSION_DENIED`. |
| 34 | `GET /admin/audit-logs` | Alias of #33. | Same as #33. | Same as #33. |

## Core payloads

### Login identifiers

- Email is trimmed and lowercased.
- Iraqi mobile input accepts canonical `+9647XXXXXXXXX`, `00964...`, local `07...`, and formatting punctuation; it is matched against canonical `+9647XXXXXXXXX` storage.
- Otherwise the identifier is an employee code, trimmed and uppercased.
- The company slug is mandatory because duplicate identifiers are intentionally allowed in different companies.

Request:

```json
{
  "tenant_slug": "demo-company",
  "identifier": "0770 123 4567",
  "password": "<user-password>",
  "branch_id": "01J00000000000000000000001",
  "device_id": "01J00000000000000000000002"
}
```

Authenticated response (example values are placeholders):

```json
{
  "data": {
    "token_type": "Bearer",
    "access_token": "<short-lived-api-jwt>",
    "refresh_token": "<opaque-rotating-refresh-token>",
    "access_token_expires_at": "2026-07-15T12:15:00Z",
    "refresh_token_expires_at": "2026-08-14T12:00:00Z",
    "session_id": "01J00000000000000000000003"
  }
}
```

MFA response (`202`):

```json
{"data":{"mfa_required":true,"challenge_token":"<opaque-challenge>","challenge_id":"01J00000000000000000000004"}}
```

Complete it with `{"challenge_token":"<opaque-challenge>","response":"<one-time-response>"}`. A successful response uses the token-pair shape above.

### Current user and sessions

`GET /me` returns `id`, `name`, `email`, `phone`, `employee_code`, `preferred_locale`, `tenant_id`, `branch_id`, `device_id`, and current permission names. Treat roles as presentation inputs only; permission-gated server responses remain authoritative.

Session item:

```json
{
  "id": "01J00000000000000000000003",
  "branch_id": "01J00000000000000000000001",
  "device_id": "01J00000000000000000000002",
  "authentication_method": "password",
  "ip_address": "192.0.2.10",
  "user_agent": "TopOrganic/1.0",
  "last_seen_at": "2026-07-15T12:00:00Z",
  "expires_at": "2026-08-14T12:00:00Z",
  "created_at": "2026-07-15T12:00:00Z"
}
```

### Password, logout, refresh, and trust

- Password body: `current_password`, `password`, `password_confirmation`.
- Refresh body: `refresh_token`. Replace both locally stored tokens atomically; never retry an already successful rotation.
- Logout endpoints require an access JWT. A revoked current session makes that JWT unusable immediately.
- Trust creation returns `{device_id, trust_token, expires_at}` once. Store the token in platform secure storage and send it only when logging in on that same device.

### Device registration and administration

Registration body fields: `tenant_slug`; optional branch ULID; `code` (letters/digits/`.`/`_`/`-`); `name`; type `pos|kiosk|mobile|desktop|other`; 64–128 lowercase/uppercase hex `key_fingerprint`; optional PEM/text `public_key`, app version, OS version.

Device responses contain `id`, `branch_id`, `code`, `name`, `type`, `status`, app/OS versions, requested/authorized/revoked/last-seen timestamps. Public keys and fingerprints are never returned by this API.

### Offline grant and receipt

Issue body: `{"branch_id":"<branch-ulid>","device_id":"<device-ulid>"}`. The response includes grant metadata and `grant_token` only on issuance. List responses never return it.

Receipt request:

```json
{
  "client_receipt_id": "01J00000000000000000000005",
  "result": "success",
  "occurred_at": "2026-07-15T12:03:00Z",
  "metadata": {"local_event_count": 4}
}
```

`result` is `success`, `failure`, or `denied`; `occurred_at` cannot be in the future; metadata is at most 50 top-level entries. Reusing the same client receipt ID for a grant returns the originally stored receipt and does not apply changed payload fields.

### Roles and permissions

Create role body uses `name`, `label`, optional `description`, `status=active|inactive`, and up to 200 distinct permission public ULIDs. Names are slugged. Update is partial. Permission sync requires the complete desired `permission_ids` array.

Role item contains public `id`, machine `name`, label, description, status, permission items, and creation time. Permission items contain public `id`, name, label, description, and risk level.

## Common statuses and error handling

| Status | Meaning |
|---:|---|
| `200` | Read/update/action completed. |
| `201` | Device, role, trust, offline grant, role grant, or receipt created. |
| `202` | Password accepted; MFA completion is required before tokens exist. |
| `401` | Credentials/token/challenge invalid or expired. Clear online credentials when refresh cannot recover. |
| `403` | Authenticated but permission/scope/policy denied. Do not retry unchanged. |
| `404` | Resource absent or deliberately hidden by company scope. |
| `409` | Duplicate/immutable state or refresh-token reuse. Force full login after refresh reuse. |
| `422` | Stable validation envelope; show safe field messages. |
| `423` | Timed account lock; use `error.details.retry_at` when present. |
| `429` | Rate limit exceeded; honor `Retry-After`. |
| `503` | Configured MFA method has no available provider adapter. |

`UNAUTHENTICATED` is the normal protected-route failure, including an offline JWT presented as an API access token. Clients must not infer resource existence from cross-company `404` responses.

## Browser session routes

`POST /login` accepts company slug, identifier, and password; it regenerates the Laravel web session and returns `{authenticated:true,user_id}` without API tokens. It rejects MFA-required accounts. `POST /logout` requires the web guard, invalidates the session and CSRF token, and returns `{authenticated:false}`. These routes are not part of the 34 `/api/v1` endpoints.