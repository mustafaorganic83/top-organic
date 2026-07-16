<?php

namespace Tests\Feature\Identity;

use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Services\AuthSessionService;
use App\Modules\Identity\Services\DeviceAuthorizationService;
use App\Modules\Identity\Services\OfflineLoginService;
use App\Modules\Identity\Services\PasswordPolicyService;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Services\RoleService;
use App\Modules\Identity\Services\SecurityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

class SecurityServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_policy_rejects_weak_and_recent_passwords(): void
    {
        [$tenant, , $user, $policy] = $this->identity();
        $service = app(PasswordPolicyService::class);

        $this->assertIdentityCode(fn () => $service->assertValid('short', $policy), 'PASSWORD_POLICY_VIOLATION');
        $changed = $service->change($user, 'NewPassword456', $policy);
        $this->assertSame(2, $changed->password_version);
        $this->assertDatabaseHas('password_histories', ['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        $this->assertIdentityCode(
            fn () => $service->change($changed, 'Password123', $policy),
            'PASSWORD_REUSED',
        );
    }

    public function test_device_approval_and_revocation_revoke_active_sessions(): void
    {
        [$tenant, $branch, $user, $policy] = $this->identity();
        $devices = app(DeviceAuthorizationService::class);
        $device = $devices->register($tenant, [
            'branch_id' => $branch->id, 'code' => 'POS-1', 'name' => 'POS 1',
            'type' => 'pos', 'key_fingerprint' => hash('sha256', 'key'),
        ]);
        $device = $devices->approve($device, $user);
        $session = app(AuthSessionService::class)->create($user, $branch, $device, $policy, true);

        $devices->revoke($device, $user, 'retired');

        $this->assertSame('revoked', $device->fresh()->status);
        $this->assertNotNull(AuthSession::withoutGlobalScopes()->findOrFail($session->id)->revoked_at);
    }

    public function test_dynamic_branch_roles_resolve_permissions_and_bump_versions(): void
    {
        [$tenant, $branch, $user] = $this->identity();
        $permission = Permission::create(['name' => 'orders.discount']);
        $roles = app(RoleService::class);
        $role = $roles->create($tenant, $user, ['name' => 'Shift Leader'], [$permission->id]);
        $before = $user->authorization_version;

        $roles->grant($tenant, $branch, $user, $role, $user);

        $this->assertTrue(app(PermissionResolver::class)->allows($user->fresh(), 'orders.discount', $branch));
        $this->assertGreaterThan($before, $user->fresh()->authorization_version);
    }

    public function test_offline_grants_are_signed_for_local_verification_and_expire(): void
    {
        [$tenant, $branch, $user, $policy] = $this->identity(['allow_offline_login' => true, 'offline_login_hours' => 1]);
        $device = Device::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'code' => 'POS-OFFLINE',
            'name' => 'Offline POS', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'offline-key'), 'authorized_at' => now(),
        ]);
        $grant = app(OfflineLoginService::class)->issue($user, $branch, $device, $policy);
        $this->assertDatabaseHas('offline_login_grants', ['grant_token_hash' => hash('sha256', $grant->value)]);
        $claims = app(JWTAuth::class)->setToken($grant->value)->getPayload();
        $this->assertContains('top-organic-offline-login', (array) $claims->get('aud'));
        $this->assertSame($grant->id, $claims->get('grant_id'));
        $this->assertSame($branch->id, $claims->get('branch_id'));

        $this->travel(61)->minutes();
        $this->assertIdentityCode(fn () => app(OfflineLoginService::class)->validate($grant->value), 'OFFLINE_GRANT_EXPIRED');
    }

    public function test_audit_entries_are_immutable_sequenced_and_hash_chained(): void
    {
        [$tenant] = $this->identity();
        $audit = app(SecurityAuditService::class);
        $first = $audit->record($tenant->id, null, 'security', 'one');
        $second = $audit->record($tenant->id, null, 'security', 'two');

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame($first->entry_hash, $second->previous_hash);
        $this->assertNotSame($first->entry_hash, $second->entry_hash);

        $this->expectException(LogicException::class);
        AuditLog::withoutGlobalScopes()->findOrFail($first->id)->update(['result' => 'changed']);
    }

    /** @param array<string, mixed> $overrides */
    private function identity(array $overrides = []): array
    {
        $tenant = Tenant::create(['slug' => 'secure', 'name' => 'Secure']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'code' => 'SEC', 'name' => 'Secure']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'password' => 'Password123']);
        $branch->users()->attach($user);
        $policy = TenantSecurityPolicy::create(array_merge([
            'tenant_id' => $tenant->id, 'password_min_length' => 10, 'password_history_count' => 3,
        ], $overrides));

        return [$tenant, $branch, $user, $policy];
    }

    private function assertIdentityCode(callable $callback, string $code): void
    {
        try {
            $callback();
            $this->fail("Expected identity error {$code}.");
        } catch (IdentityException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
