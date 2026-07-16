<?php

namespace Tests\Feature\Foundation;

use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\OfflineLoginGrant;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AuthenticationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_numeric_identity_models_expose_public_ulids_and_relationships(): void
    {
        $tenant = Tenant::create(['slug' => 'identity', 'name' => 'Identity']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $group = PermissionGroup::create(['code' => 'custom', 'name' => 'Custom']);
        $permission = Permission::create([
            'permission_group_id' => $group->id,
            'name' => 'custom.use',
        ]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'operator']);
        $role->permissions()->attach($permission);
        $policy = TenantSecurityPolicy::create(['tenant_id' => $tenant->id]);

        $this->assertIsInt($user->id);
        $this->assertIsInt($role->id);
        $this->assertIsInt($permission->id);
        $this->assertTrue(Str::isUlid($user->public_id));
        $this->assertTrue(Str::isUlid($role->public_id));
        $this->assertTrue(Str::isUlid($permission->public_id));
        $this->assertTrue(Str::isUlid($policy->id));
        $this->assertTrue($permission->group->is($group));
        $this->assertTrue($tenant->securityPolicy->is($policy));
        $this->assertSame($user->public_id, $user->getJWTIdentifier());
        $this->assertSame($tenant->id, $user->getJWTCustomClaims()['tenant_id']);
    }

    public function test_dynamic_roles_are_resolved_within_the_users_tenant(): void
    {
        $tenantA = Tenant::create(['slug' => 'role-a', 'name' => 'Role A']);
        $tenantB = Tenant::create(['slug' => 'role-b', 'name' => 'Role B']);
        $roleA = Role::create(['tenant_id' => $tenantA->id, 'name' => 'supervisor']);
        Role::create(['tenant_id' => $tenantB->id, 'name' => 'supervisor']);
        $user = User::factory()->create(['tenant_id' => $tenantA->id]);

        $user->assignRole('supervisor');

        $this->assertTrue($user->roles->contains($roleA));
        $this->assertCount(1, $user->roles);
        $this->assertCount(1, Role::availableToTenant($tenantA->id)->where('name', 'supervisor')->get());
    }

    public function test_branch_role_permissions_are_scoped_and_legacy_roles_remain_compatible(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::create(['slug' => 'branches', 'name' => 'Branches']);
        $branchA = Branch::create(['tenant_id' => $tenant->id, 'code' => 'A', 'name' => 'A']);
        $branchB = Branch::create(['tenant_id' => $tenant->id, 'code' => 'B', 'name' => 'B']);
        $permission = Permission::create(['name' => 'closing.perform']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'closer']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $user->assignRoleForBranch($branchA, 'closer');

        $this->assertTrue($user->hasPermissionTo('closing.perform', $branchA));
        $this->assertFalse($user->hasPermissionTo('closing.perform', $branchB));
        $this->assertTrue($user->permissionNames($branchA)->contains('closing.perform'));

        $user->assignRole('waiter');
        $this->assertTrue($user->hasPermissionTo('orders.create', $branchB));
    }

    public function test_security_models_cast_values_and_hide_secret_hashes(): void
    {
        $tenant = Tenant::create(['slug' => 'security', 'name' => 'Security']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'code' => 'SEC', 'name' => 'Security']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'two_factor_enabled' => true,
            'locked_at' => now(),
        ]);
        $device = Device::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'code' => 'POS-1',
            'name' => 'POS 1',
            'type' => 'pos',
            'key_fingerprint' => hash('sha256', 'device-key'),
        ]);
        $session = AuthSession::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'device_id' => $device->id,
            'session_key_hash' => hash('sha256', 'session'),
            'mfa_completed' => true,
            'password_version' => 1,
            'security_version' => 1,
            'authorization_version' => 1,
            'expires_at' => now()->addHour(),
        ]);
        $token = RefreshToken::create([
            'auth_session_id' => $session->id,
            'family_id' => strtolower((string) Str::ulid()),
            'token_hash' => hash('sha256', 'refresh'),
            'expires_at' => now()->addDay(),
        ]);
        $grant = OfflineLoginGrant::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'device_id' => $device->id,
            'grant_token_hash' => hash('sha256', 'offline'),
            'permission_snapshot' => ['orders.create'],
            'password_version' => 1,
            'security_version' => 1,
            'authorization_version' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($user->two_factor_enabled);
        $this->assertNotNull($user->locked_at);
        $this->assertTrue($session->mfa_completed);
        $this->assertSame(['orders.create'], $grant->permission_snapshot);
        $this->assertArrayNotHasKey('session_key_hash', $session->toArray());
        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $this->assertArrayNotHasKey('grant_token_hash', $grant->toArray());
    }

    public function test_audit_logs_are_ulid_keyed_casted_and_immutable(): void
    {
        $tenant = Tenant::create(['slug' => 'audit', 'name' => 'Audit']);
        $log = AuditLog::create([
            'tenant_id' => $tenant->id,
            'sequence' => 1,
            'category' => 'security',
            'action' => 'login.succeeded',
            'before' => ['status' => 'locked'],
            'after' => ['status' => 'active'],
            'occurred_at' => now(),
        ]);

        $this->assertTrue(Str::isUlid($log->id));
        $this->assertSame(['status' => 'active'], $log->after);

        $this->expectException(LogicException::class);
        $log->update(['result' => 'changed']);
    }
}
