<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RBAC foundation: roles group permissions and are enforced at the domain
 * boundary (architecture doc 06). These assert the trait wiring, not any
 * business feature.
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_role_grants_every_permission(): void
    {
        $user = User::factory()->create()->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasPermissionTo('orders.create'));
        $this->assertTrue($user->hasPermissionTo('billing.refund'));
        $this->assertTrue($user->hasPermissionTo('reports.view'));
    }

    public function test_waiter_role_has_scoped_permissions_only(): void
    {
        $user = User::factory()->create()->assignRole('waiter');

        $this->assertTrue($user->hasPermissionTo('orders.create'));
        $this->assertFalse($user->hasPermissionTo('billing.refund'));
        $this->assertFalse($user->hasPermissionTo('reports.view'));
    }

    public function test_has_role_accepts_multiple_names(): void
    {
        $user = User::factory()->create()->assignRole('cashier');

        $this->assertTrue($user->hasRole(['admin', 'cashier']));
        $this->assertFalse($user->hasRole(['admin', 'kitchen']));
    }

    public function test_permission_names_are_distinct_across_roles(): void
    {
        $user = User::factory()->create()->assignRole('waiter', 'cashier');

        $names = $user->permissionNames();

        $this->assertSame($names->count(), $names->unique()->count());
        $this->assertTrue($names->contains('orders.create'));
        $this->assertTrue($names->contains('billing.charge'));
    }

    public function test_user_without_role_has_no_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasPermissionTo('orders.view'));
        $this->assertTrue($user->permissionNames()->isEmpty());
    }
}
