# Flutter Authentication Flow

This is the implementation contract for POS/KDS/mobile. The API remains authoritative for identity, scope, permissions, and revocation. Flutter roles/permissions control navigation and affordances only; they never replace server authorization.

## State machine

```text
boot
 ├─ no company/device config ─> companySetup ─> deviceRegistration ─> devicePending
 ├─ valid online credentials ─> refreshing ─> authenticatedOnline
 ├─ usable verified offline grant + local PIN ─> offlinePin ─> authenticatedOffline
 └─ otherwise ─> signedOut

signedOut ─> credentials ─┬─ 200 ─> persist tokens ─> loadMe ─> authenticatedOnline
                         ├─ 202 ─> mfaChallenge ─┬─ 200 ─> persist/loadMe
                         │                      └─ error/exhausted ─> credentials
                         ├─ device denied ─> devicePending/deviceRegistration
                         └─ error ─> credentials

authenticatedOnline ─┬─ access expiry/401 ─> refreshing ─┬─ success ─> retry once
                     │                                  └─ failure ─> signedOut
                     ├─ network loss + valid grant ─> offlinePin
                     └─ logout/revocation ─> signedOut

authenticatedOffline ─┬─ grant/PIN/permission failure ─> offlineLocked
                      └─ reconnect ─> refresh/relogin ─> upload receipts/events ─> online
```

Model these as sealed states, not booleans. Every state transition must atomically update secure storage and in-memory auth state. Never briefly expose an authenticated route while refresh, MFA, or offline verification is incomplete.

## DTOs

```dart
typedef PublicId = String;

sealed class LoginOutcome {}
final class OnlineTokens extends LoginOutcome {
  final String accessToken, refreshToken, sessionId;
  final DateTime accessExpiresAt, refreshExpiresAt;
}
final class MfaRequired extends LoginOutcome {
  final String challengeToken, challengeId;
}

final class CurrentUserDto {
  final PublicId id, tenantId;
  final PublicId? branchId, deviceId;
  final String name, email, preferredLocale;
  final String? phone, employeeCode;
  final Set<String> permissions;
}

final class SessionDto {
  final PublicId id;
  final PublicId? branchId, deviceId;
  final String authenticationMethod;
  final String? ipAddress, userAgent;
  final DateTime? lastSeenAt, createdAt;
  final DateTime expiresAt;
}

final class DeviceDto {
  final PublicId id;
  final PublicId? branchId;
  final String code, name, type, status;
  final DateTime? requestedAt, authorizedAt, revokedAt, lastSeenAt;
}

final class OfflineGrantDto {
  final PublicId id, branchId, deviceId;
  final String? grantToken; // present only on issuance
  final DateTime issuedAt, expiresAt;
  final DateTime? lastUsedAt, revokedAt;
}

final class ApiFailure implements Exception {
  final int status;
  final String code, message;
  final Map<String, List<String>> fields;
  final Map<String, Object?> details;
}
```

Generate JSON DTOs with strict required/nullability handling. Reject malformed dates, IDs, token envelopes, or permission arrays rather than guessing defaults.

## Repository boundaries

```dart
abstract interface class AuthRepository {
  Future<LoginOutcome> login(LoginCommand command);
  Future<OnlineTokens> completeMfa(String challenge, String response);
  Future<OnlineTokens> refresh(String opaqueRefreshToken);
  Future<CurrentUserDto> me();
  Future<void> logout();
  Future<int> logoutAll();
  Future<void> changePassword(ChangePasswordCommand command);
}

abstract interface class DeviceRepository {
  Future<DeviceDto> register(RegisterDeviceCommand command);
  Future<String> trustCurrent(PublicId deviceId);
  Future<void> revokeTrust(PublicId deviceId);
}

abstract interface class SessionRepository {
  Future<List<SessionDto>> list({int page = 1});
  Future<void> revoke(PublicId sessionId);
}

abstract interface class OfflineAuthRepository {
  Future<OfflineGrantDto> issue(PublicId branchId, PublicId deviceId);
  Future<List<OfflineGrantDto>> list();
  Future<void> revoke(PublicId grantId);
  Future<void> uploadReceipt(OfflineReceiptCommand receipt);
}
```

Keep API repositories separate from secure credential storage, offline encrypted storage, and state orchestration. This makes refresh, logout, reconnect, and destructive-storage tests deterministic.

## Secure storage model

Store only in OS-backed secure storage (Android Keystore/EncryptedSharedPreferences, iOS Keychain, desktop platform equivalent):

- company slug and selected company/branch public IDs;
- installation-generated device ID and private-key handle (never export a hardware-backed private key);
- current access JWT, opaque refresh token, session ID, and expiries;
- same-device remembered token;
- signed offline grant JWT and its verified metadata;
- local encrypted PIN verifier salt/parameters/ciphertext key handle.

Do not store passwords, raw PINs, MFA responses, recovery codes, JWT private keys, or plaintext token logs. Clear online tokens on terminal refresh failure. Clear trust/offline material when device identity changes, the server revokes it, signature verification fails, or the user explicitly removes offline access.

Use an encrypted application database for queued offline events/receipts. Bind its encryption key to secure storage and the installation. Device backup/restore must not silently clone device trust or offline grants.

## Company, branch, employee, and device login

1. Ask for company slug before credentials; identifiers can exist in multiple companies.
2. Accept email, Iraqi mobile, or employee code in one field. Preserve user input for display, but let the server normalize it.
3. Select a branch only from configured/granted choices. If none is known, omit `branch_id` and accept the server's first granted branch from `/me`; do not invent context headers.
4. Include this installation's authorized `device_id` and only its own remembered token.
5. On `DEVICE_REQUIRED`, start device registration. On pending registration, poll only through an approved product flow or ask an administrator; do not loop login aggressively.
6. On `DEVICE_NOT_AUTHORIZED`, remove remembered trust and block device-bound/offline use. Never fall back to a different device ID.
7. After `200`, atomically persist both tokens, call `/me`, then enter online state. Use returned company/branch/device context and permissions.

Device registration sends code, friendly name, device type, fingerprint, optional public key, and app/OS versions. Generate key material on-device. Display pending/authorized/revoked states distinctly.

## MFA and remembered device

`202` means no access or refresh token exists. Hold the challenge token in memory where possible; if process restoration is required, put it in secure storage with its short expiry and delete it after any terminal outcome.

Recovery codes are supported now. Render the response field generically so configured TOTP/SMS/WebAuthn adapters can use the same completion route later. TOTP, SMS, and WebAuthn are not usable until the server has the corresponding provider adapter configured. Handle `MFA_METHOD_UNAVAILABLE` as an operational error, not a retry loop.

Wrong responses consume attempts. Do not automatically resubmit. On `MFA_CHALLENGE_INVALID`, return to credential login. If the user opts to remember the device, call trust only after successful MFA while authenticated on that exact device, then store the returned opaque token under that device ID.

## Dio refresh mutex and interceptor

Use one refresh future for the entire process. Queue requests behind it and retry each request at most once. The refresh call uses a separate Dio instance/interceptor path so it cannot recursively trigger refresh.

```dart
Future<OnlineTokens>? _refreshing;

Future<OnlineTokens> refreshOnce() {
  final active = _refreshing;
  if (active != null) return active;
  late final Future<OnlineTokens> created;
  created = (() async {
    final token = await secureStore.readRefreshToken();
    if (token == null) throw SignedOut();
    final pair = await refreshDio.refresh(token);
    await secureStore.replaceTokenPairAtomically(pair);
    return pair;
  })().whenComplete(() {
    if (identical(_refreshing, created)) _refreshing = null;
  });
  return _refreshing = created;
}

Future<Response<dynamic>> recover(RequestOptions failed) async {
  if (failed.extra['authRetried'] == true) throw SignedOut();
  final pair = await refreshOnce();
  failed.headers['Authorization'] = 'Bearer ${pair.accessToken}';
  failed.extra['authRetried'] = true;
  return dio.fetch(failed);
}
```

The single Dart isolate assigns the future before another request callback can create one; the identity check prevents an older completion from clearing a newer refresh. On refresh `401`, `409 REFRESH_TOKEN_REUSED`, scope/session failure, or malformed response: atomically clear online tokens, cancel queued authenticated work, and emit signed-out. A timeout/network failure may preserve the opaque refresh token and move to offline/network-error state; it is not proof of invalid credentials.

Attach bearer access tokens only to approved API origins. Never attach auth to object storage, analytics, crash reports, or redirected hosts. Redact `Authorization` and all token-like request/response fields from Dio logs.

## Sessions and role-driven UX

The session screen calls `GET /sessions`, highlights the current `session_id`, and allows revocation. Revoking the current session transitions immediately to signed-out. Logout-all clears local tokens even if the response body cannot be read after successful submission.

Build menus from `/me.permissions` (for example, `identity.devices.view`), not hard-coded role names or JWT claims. Hiding controls is UX only. Always handle server `403` by refreshing `/me` once if online, then show a stable denial; never reinterpret a stale local role as authority.

## Signed offline grant verification

Offline entry requires **all** checks below before asking for/accepting the local PIN:

1. Parse as JWS compact serialization with exactly three segments and no unsupported critical headers.
2. Require header `alg=RS256`; reject `none`, HMAC, algorithm substitution, and unapproved key IDs.
3. Verify the signature with the pinned/managed **RS256 public key** bundled or securely provisioned to the app. Never fetch a replacement key over an untrusted connection and never embed a private key.
4. Require issuer policy and standard `sub`, `jti`, `iat`, `nbf`, `exp`, and `aud` claims. Audience must equal the configured offline audience, never the API audience.
5. Require `grant_id`, `tenant_id`, `branch_id`, `device_id`, `permissions`, `password_version`, `security_version`, and `authorization_version` with expected types.
6. Match company, branch, and device to local installation/configuration. Reject expired/not-yet-valid grants using a small documented clock-skew allowance and a trusted last-known-server-time strategy.
7. Compare the compact token hash and verified claims to the encrypted locally stored grant record. Any mismatch fails closed and deletes the unusable record.

Do not call `/me` with an offline grant. It deliberately fails normal API authentication.

## Local encrypted PIN verifier

The PIN is a local second gate over an already verified offline grant; it is not a server password and grants no extra permissions.

- Enforce product PIN length/attempt policy and block common values.
- Derive a verifier using a reviewed memory-hard KDF such as Argon2id (or platform-approved PBKDF2 when required), a unique random salt, versioned cost parameters, and constant-time comparison.
- Store only the salt, parameters, and verifier inside encrypted storage; protect the database encryption key with OS secure storage/hardware keystore. Never store/recover the PIN.
- Bind the verifier record to grant subject, company, branch, device, and local user profile. Re-enrol on user/device change.
- Persist failed-attempt count and timed lockout transactionally in encrypted storage so app restart cannot reset brute-force protection. Excess failures require online reauthentication according to policy.
- Clear PIN material when the offline grant expires/is revoked, device changes, local integrity checks fail, or the user logs out and chooses to remove offline access.

Biometrics may unlock the secure-storage key but must not weaken PIN/offline policy. Detect rollback/root/jailbreak according to product risk policy; do not silently bypass failed attestation.

## Offline permissions, expiry, receipts, and reconnect

- Permit only operations explicitly present in the verified grant's `permissions` snapshot and an allowlist of operations designed for offline execution. Never expose identity/device/role administration offline.
- Check `exp` and local lock state at every offline unlock and before privileged actions. Existing UI sessions must lock when the grant expires.
- Queue events in the encrypted database with client ULIDs, company/branch/device/user scope, monotonic local sequence, occurrence time, payload hash, and sync state.
- Create one receipt ULID per offline-login outcome/sync unit and reuse it until acknowledged. The server's grant+receipt uniqueness makes retries idempotent; never generate a new ID merely because a request timed out.
- On reconnect, first refresh the API session or perform full login. Re-fetch `/me`; if grant/device/user/version state is no longer accepted, lock offline access before uploading business writes.
- Upload receipts and domain outbox records in deterministic order. Treat a successful replay response as acknowledgment of the original payload. Quarantine conflicting local payloads that reused an ID.
- After sync, request/list a replacement offline grant only when policy allows, verify it fully, then atomically replace the old grant. Do not extend expiry locally.

Test the state machine with process death during token rotation, parallel `401`s, refresh reuse, device revocation, clock change, expired grants, invalid RS256 signatures/audiences/claims, PIN lockout persistence, duplicate receipts, partial reconnect, and permission removal.