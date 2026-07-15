<?php

namespace Tests\Feature\Identity;

use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_authentication_failure_never_redirects_to_web_login(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_api_login_me_refresh_and_logout_use_stable_envelopes(): void
    {
        [$tenant, $branch, $user] = $this->identity();

        $login = $this->postJson('/api/v1/auth/login', $this->credentials($tenant, $branch, $user))
            ->assertOk()->assertJsonPath('data.token_type', 'Bearer');
        $access = $login->json('data.access_token');
        $refresh = $login->json('data.refresh_token');

        $this->withToken($access)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonMissingPath('data.password');

        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
            ->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
        $newAccess = $rotated->json('data.access_token');
        $this->assertNotSame($refresh, $rotated->json('data.refresh_token'));

        $this->withToken($newAccess)->postJson('/api/v1/auth/logout')->assertOk()
            ->assertJsonPath('data.requires_relogin', true);
        $this->withToken($newAccess)->getJson('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_forged_context_headers_are_ignored_and_removed_branch_grants_are_denied(): void
    {
        [$tenant, $branch, $user] = $this->identity();
        $other = Branch::create(['tenant_id' => $tenant->id, 'code' => 'OTHER', 'name' => 'Other']);
        $token = $this->login($tenant, $branch, $user);

        $this->withToken($token)->withHeaders([
            'X-Tenant-Id' => (string) Str::ulid(),
            'X-Branch-Id' => $other->id,
            'X-Device-Id' => (string) Str::ulid(),
        ])->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.device_id', null);

        $branch->users()->detach($user);
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()
            ->assertJsonPath('error.code', 'BRANCH_ACCESS_DENIED');
    }

    public function test_permission_middleware_re_resolves_permissions_and_admin_role_device_flows_work(): void
    {
        [$tenant, $branch, $admin] = $this->identity();
        $this->seed(RolePermissionSeeder::class);
        $admin->assignRole('admin');
        $adminToken = $this->login($tenant, $branch, $admin);

        $ordinary = User::factory()->create([
            'tenant_id' => $tenant->id, 'email' => 'ordinary@example.com', 'password' => 'Password123',
        ]);
        $branch->users()->attach($ordinary);
        $ordinaryToken = $this->login($tenant, $branch, $ordinary);
        $this->withToken($ordinaryToken)->getJson('/api/v1/admin/roles')->assertForbidden()
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');

        $permission = Permission::query()->where('name', 'orders.view')->firstOrFail();
        $created = $this->withToken($adminToken)->postJson('/api/v1/admin/roles', [
            'name' => 'Shift Leader',
            'label' => 'Shift Leader',
            'permission_ids' => [$permission->public_id],
        ])->assertCreated()->assertJsonPath('data.name', 'shift-leader');
        $roleId = $created->json('data.id');
        $this->assertTrue(Str::isUlid($roleId));

        $grant = $this->withToken($adminToken)->postJson(
            "/api/v1/admin/users/{$ordinary->public_id}/branches/{$branch->id}/roles/{$roleId}",
        )->assertCreated();
        $this->withToken($adminToken)->deleteJson('/api/v1/admin/role-grants/'.$grant->json('data.id'), [
            'reason' => 'shift_ended',
        ])->assertOk();

        $fingerprint = hash('sha256', 'admin-device-flow');
        $device = $this->postJson('/api/v1/devices/register', [
            'tenant_slug' => $tenant->slug,
            'branch_id' => $branch->id,
            'code' => 'POS-HTTP-1',
            'name' => 'HTTP POS',
            'type' => 'pos',
            'key_fingerprint' => $fingerprint,
        ])->assertCreated();
        $deviceId = $device->json('data.id');

        $this->withToken($adminToken)->postJson("/api/v1/admin/devices/{$deviceId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'authorized');
        $this->withToken($adminToken)->getJson("/api/v1/admin/devices/{$deviceId}")
            ->assertOk()->assertJsonMissingPath('data.key_fingerprint');
        $this->withToken($adminToken)->postJson("/api/v1/admin/devices/{$deviceId}/revoke", [
            'reason' => 'retired',
        ])->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->withToken($adminToken)->deleteJson("/api/v1/admin/roles/{$roleId}")->assertOk();
    }

    public function test_removed_permission_is_denied_even_when_it_was_in_the_jwt(): void
    {
        [$tenant, $branch, $user] = $this->identity();
        $this->seed(RolePermissionSeeder::class);
        $user->assignRole('admin');
        $token = $this->login($tenant, $branch, $user);

        $user->roles()->detach();

        $this->withToken($token)->getJson('/api/v1/admin/permission-groups')->assertForbidden()
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    public function test_password_change_verifies_current_password_and_revokes_every_session(): void
    {
        [$tenant, $branch, $user] = $this->identity();
        $first = $this->login($tenant, $branch, $user);
        $second = $this->login($tenant, $branch, $user);

        $this->withToken($first)->postJson('/api/v1/auth/password', [
            'current_password' => 'wrong',
            'password' => 'Replacement456',
            'password_confirmation' => 'Replacement456',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'CURRENT_PASSWORD_INVALID');

        $this->withToken($first)->postJson('/api/v1/auth/password', [
            'current_password' => 'Password123',
            'password' => 'Replacement456',
            'password_confirmation' => 'Replacement456',
        ])->assertOk()->assertJsonPath('data.requires_relogin', true);

        $this->assertSame(0, AuthSession::withoutGlobalScopes()->where('user_id', $user->id)
            ->whereNull('revoked_at')->count());
        $this->withToken($first)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_validation_authentication_and_invalid_credentials_have_stable_errors(): void
    {
        [$tenant, $branch, $user] = $this->identity();

        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['tenant_slug', 'identifier', 'password']]]);
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $unknownTenant = $this->postJson('/api/v1/auth/login', array_merge(
            $this->credentials($tenant, $branch, $user), ['tenant_slug' => 'missing'],
        ))->assertUnauthorized();
        $unknownUser = $this->postJson('/api/v1/auth/login', array_merge(
            $this->credentials($tenant, $branch, $user), ['identifier' => 'missing@example.com'],
        ))->assertUnauthorized();
        $this->assertSame($unknownTenant->json('error'), $unknownUser->json('error'));
        $this->assertSame('INVALID_CREDENTIALS', $unknownTenant->json('error.code'));
    }

    public function test_web_login_regenerates_the_session_and_logout_invalidates_authentication(): void
    {
        [$tenant, , $user] = $this->identity();
        $this->withSession(['fixation_marker' => true]);
        $oldSessionId = session()->getId();

        $this->postJson('/login', [
            'tenant_slug' => $tenant->slug,
            'identifier' => $user->email,
            'password' => 'Password123',
        ])->assertOk()->assertJsonMissingPath('data.access_token');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSessionId, session()->getId());

        $this->postJson('/logout')->assertOk()->assertJsonPath('data.authenticated', false);
        $this->assertGuest('web');
    }

    public function test_web_login_rejects_accounts_requiring_mfa(): void
    {
        [$tenant, , $user] = $this->identity(['mfa_required' => true]);

        $this->postJson('/login', [
            'tenant_slug' => $tenant->slug,
            'identifier' => $user->email,
            'password' => 'Password123',
        ])->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->assertGuest('web');
    }

    private function login(Tenant $tenant, Branch $branch, User $user): string
    {
        return $this->postJson('/api/v1/auth/login', $this->credentials($tenant, $branch, $user))
            ->assertOk()->json('data.access_token');
    }

    private function credentials(Tenant $tenant, Branch $branch, User $user): array
    {
        return [
            'tenant_slug' => $tenant->slug,
            'identifier' => $user->email,
            'password' => 'Password123',
            'branch_id' => $branch->id,
        ];
    }

    private function identity(array $policy = []): array
    {
        $tenant = Tenant::create(['slug' => 'http-'.strtolower(Str::random(8)), 'name' => 'HTTP Tenant']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id, 'email' => 'admin@example.com', 'password' => 'Password123',
        ]);
        $branch->users()->attach($user);
        TenantSecurityPolicy::create(array_merge(['tenant_id' => $tenant->id], $policy));

        return [$tenant, $branch, $user];
    }
}
