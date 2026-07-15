<?php

namespace Tests\Feature\Identity;

use App\Models\Branch;
use App\Models\Device;
use App\Models\MfaChallenge;
use App\Models\MfaRecoveryCode;
use App\Models\OfflineLoginReceipt;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

class IdentitySecurityFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_code_and_iraqi_phone_login_are_company_scoped(): void
    {
        [$firstTenant, $firstBranch, $firstUser] = $this->identity('first-company', [
            'email' => 'shared@example.com',
            'employee_code' => 'EMP-100',
            'phone' => '+9647701234567',
        ]);
        [$secondTenant, $secondBranch, $secondUser] = $this->identity('second-company', [
            'email' => 'shared@example.com',
            'employee_code' => 'EMP-100',
            'phone' => '+9647701234567',
        ]);

        $this->postJson('/api/v1/auth/login', $this->credentials(
            $firstTenant, $firstBranch, 'emp-100',
        ))->assertOk();
        $this->postJson('/api/v1/auth/login', $this->credentials(
            $firstTenant, $firstBranch, '0770 123 4567',
        ))->assertOk();

        $firstLogin = $this->login($firstTenant, $firstBranch, 'shared@example.com');
        $secondLogin = $this->login($secondTenant, $secondBranch, '+964 770 123 4567');
        $this->withToken($firstLogin['access_token'])->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $firstUser->public_id);
        $this->withToken($secondLogin['access_token'])->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $secondUser->public_id);
    }

    public function test_recovery_mfa_challenges_enforce_attempt_limits_and_single_use(): void
    {
        config()->set('identity.mfa.max_attempts', 2);
        [$tenant, $branch, $user] = $this->identity('mfa-company', [], ['mfa_required' => true]);
        $this->recoveryCode($user, 'recovery-one');
        $secondRecovery = $this->recoveryCode($user, 'recovery-two');

        $challenge = $this->postJson('/api/v1/auth/login', $this->credentials($tenant, $branch, $user->email))
            ->assertAccepted()->json('data');
        $this->postJson('/api/v1/auth/mfa/complete', [
            'challenge_token' => $challenge['challenge_token'], 'response' => 'wrong',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'MFA_RESPONSE_INVALID');
        $this->assertSame(1, MfaChallenge::withoutGlobalScopes()->findOrFail($challenge['challenge_id'])->attempts);

        $this->postJson('/api/v1/auth/mfa/complete', [
            'challenge_token' => $challenge['challenge_token'], 'response' => 'still-wrong',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'MFA_RESPONSE_INVALID');
        $exhausted = MfaChallenge::withoutGlobalScopes()->findOrFail($challenge['challenge_id']);
        $this->assertSame(2, $exhausted->attempts);
        $this->assertNotNull($exhausted->consumed_at);
        $this->postJson('/api/v1/auth/mfa/complete', [
            'challenge_token' => $challenge['challenge_token'], 'response' => 'recovery-one',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'MFA_CHALLENGE_INVALID');

        $fresh = $this->postJson('/api/v1/auth/login', $this->credentials($tenant, $branch, $user->email))
            ->assertAccepted()->json('data');
        $this->postJson('/api/v1/auth/mfa/complete', [
            'challenge_token' => $fresh['challenge_token'], 'response' => 'recovery-two',
        ])->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
        $this->assertNotNull($secondRecovery->fresh()->used_at);
        $this->postJson('/api/v1/auth/mfa/complete', [
            'challenge_token' => $fresh['challenge_token'], 'response' => 'recovery-two',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'MFA_CHALLENGE_INVALID');
    }

    public function test_remembered_device_trust_is_bound_to_the_current_authorized_device(): void
    {
        [$tenant, $branch, $user] = $this->identity('trusted-company', [], [
            'mfa_required' => true, 'allow_remembered_devices' => true,
        ]);
        $firstDevice = $this->device($tenant, $branch, 'POS-1');
        $secondDevice = $this->device($tenant, $branch, 'POS-2');
        $this->recoveryCode($user, 'trust-recovery');

        $challenge = $this->postJson('/api/v1/auth/login', $this->credentials(
            $tenant, $branch, $user->email, $firstDevice,
        ))->assertAccepted()->json('data');
        $access = $this->postJson('/api/v1/auth/mfa/complete', [
            'challenge_token' => $challenge['challenge_token'], 'response' => 'trust-recovery',
        ])->assertOk()->json('data.access_token');

        $this->withToken($access)->postJson("/api/v1/devices/{$secondDevice->id}/trust")
            ->assertNotFound()->assertJsonPath('error.code', 'DEVICE_NOT_FOUND');
        $trust = $this->withToken($access)->postJson("/api/v1/devices/{$firstDevice->id}/trust")
            ->assertCreated()->json('data.trust_token');

        $this->postJson('/api/v1/auth/login', array_merge(
            $this->credentials($tenant, $branch, $user->email, $secondDevice),
            ['remembered_device_token' => $trust],
        ))->assertAccepted()->assertJsonPath('data.mfa_required', true);
        $this->postJson('/api/v1/auth/login', array_merge(
            $this->credentials($tenant, $branch, $user->email, $firstDevice),
            ['remembered_device_token' => $trust],
        ))->assertOk()->assertJsonPath('data.token_type', 'Bearer');
    }

    public function test_offline_jwt_is_rejected_by_api_auth_and_receipts_are_idempotent(): void
    {
        [$tenant, $branch, $user] = $this->identity('offline-company', [], ['allow_offline_login' => true]);
        $device = $this->device($tenant, $branch, 'POS-OFFLINE');
        $access = $this->login($tenant, $branch, $user->email, $device)['access_token'];

        $grant = $this->withToken($access)->postJson('/api/v1/offline-grants', [
            'branch_id' => $branch->id, 'device_id' => $device->id,
        ])->assertCreated()->json('data');
        $claims = app(JWTAuth::class)->setToken($grant['grant_token'])->getPayload();
        $this->assertContains(config('identity.authentication.offline_audience'), (array) $claims->get('aud'));
        $this->assertNull($claims->get('session_id'));
        $this->withToken($grant['grant_token'])->getJson('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $receiptId = strtolower((string) Str::ulid());
        $payload = ['client_receipt_id' => $receiptId, 'result' => 'success', 'occurred_at' => now()->toIso8601String()];
        $first = $this->withToken($access)->postJson("/api/v1/offline-grants/{$grant['id']}/receipts", $payload)
            ->assertCreated()->json('data');
        $second = $this->withToken($access)->postJson("/api/v1/offline-grants/{$grant['id']}/receipts", array_merge(
            $payload, ['result' => 'failure'],
        ))->assertCreated()->json('data');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('success', $second['result']);
        $this->assertSame(1, OfflineLoginReceipt::withoutGlobalScopes()->count());
    }

    public function test_sessions_can_be_listed_and_individually_revoked(): void
    {
        [$tenant, $branch, $user] = $this->identity('sessions-company');
        $first = $this->login($tenant, $branch, $user->email);
        $second = $this->login($tenant, $branch, $user->email);

        $this->withToken($first['access_token'])->getJson('/api/v1/sessions')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => $first['session_id']])
            ->assertJsonFragment(['id' => $second['session_id']]);
        $this->withToken($first['access_token'])->deleteJson("/api/v1/sessions/{$second['session_id']}")
            ->assertOk()->assertJsonPath('data.revoked', true);
        $this->withToken($second['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($first['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_branch_admin_cannot_manage_another_branch_and_tenant_targets_are_hidden(): void
    {
        [$tenant, $branch, $admin] = $this->identity('scope-company');
        $otherBranch = Branch::create(['tenant_id' => $tenant->id, 'code' => 'OTHER', 'name' => 'Other']);
        $target = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'target@example.com']);
        $otherBranch->users()->attach($target);
        $this->seed(RolePermissionSeeder::class);
        $admin->assignRoleForBranch($branch, 'admin');
        $access = $this->login($tenant, $branch, $admin->email)['access_token'];
        $permissionId = Permission::query()->where('name', 'orders.view')->firstOrFail()->public_id;
        $roleId = $this->withToken($access)->postJson('/api/v1/admin/roles', [
            'name' => 'Scoped Staff', 'label' => 'Scoped Staff', 'permission_ids' => [$permissionId],
        ])->assertCreated()->json('data.id');

        $this->withToken($access)->postJson(
            "/api/v1/admin/users/{$target->public_id}/branches/{$otherBranch->id}/roles/{$roleId}",
        )->assertForbidden()->assertJsonPath('error.code', 'BRANCH_SCOPE_VIOLATION');
        $otherDevice = $this->device($tenant, $otherBranch, 'POS-OTHER', 'pending');
        $this->withToken($access)->postJson("/api/v1/admin/devices/{$otherDevice->id}/approve")
            ->assertForbidden()->assertJsonPath('error.code', 'BRANCH_SCOPE_VIOLATION');

        [$foreignTenant, $foreignBranch, $foreignUser] = $this->identity('foreign-company');
        $this->withToken($access)->postJson(
            "/api/v1/admin/users/{$foreignUser->public_id}/branches/{$foreignBranch->id}/roles/{$roleId}",
        )->assertNotFound()->assertJsonPath('error.code', 'ROLE_GRANT_TARGET_NOT_FOUND');
        $foreignDevice = $this->device($foreignTenant, $foreignBranch, 'POS-FOREIGN', 'pending');
        $this->withToken($access)->postJson("/api/v1/admin/devices/{$foreignDevice->id}/approve")
            ->assertNotFound()->assertJsonPath('error.code', 'DEVICE_NOT_FOUND');
    }

    /** @return array{Tenant, Branch, User} */
    private function identity(string $slug, array $user = [], array $policy = []): array
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => str($slug)->headline()]);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $model = User::factory()->create(array_merge([
            'tenant_id' => $tenant->id, 'email' => $slug.'@example.com', 'password' => 'Password123',
        ], $user));
        $branch->users()->attach($model);
        TenantSecurityPolicy::create(array_merge(['tenant_id' => $tenant->id], $policy));

        return [$tenant, $branch, $model];
    }

    private function credentials(Tenant $tenant, Branch $branch, string $identifier, ?Device $device = null): array
    {
        return array_filter([
            'tenant_slug' => $tenant->slug, 'identifier' => $identifier, 'password' => 'Password123',
            'branch_id' => $branch->id, 'device_id' => $device?->id,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function login(Tenant $tenant, Branch $branch, string $identifier, ?Device $device = null): array
    {
        return $this->postJson('/api/v1/auth/login', $this->credentials($tenant, $branch, $identifier, $device))
            ->assertOk()->json('data');
    }

    private function recoveryCode(User $user, string $code): MfaRecoveryCode
    {
        return MfaRecoveryCode::create([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'code_hash' => hash('sha256', $code),
        ]);
    }

    private function device(Tenant $tenant, Branch $branch, string $code, string $status = 'authorized'): Device
    {
        return Device::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'code' => $code, 'name' => $code,
            'type' => 'pos', 'status' => $status, 'key_fingerprint' => hash('sha256', $tenant->id.$code),
            'authorized_at' => $status === 'authorized' ? now() : null,
            'authorization_requested_at' => now(),
        ]);
    }
}
