<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the foundation RBAC roles and permissions (architecture doc 01 FR-1,
 * doc 06). Roles map to the module skeletons; granular permissions use dotted
 * names ("orders.create") and are grouped into roles. Business steps extend
 * both lists as modules land. Idempotent via updateOrCreate.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Foundation roles keyed by machine name => localizable label.
     */
    private const ROLES = [
        'admin' => 'Administrator',
        'manager' => 'Branch Manager',
        'waiter' => 'Waiter',
        'cashier' => 'Cashier',
        'kitchen' => 'Kitchen',
        'accountant' => 'Accountant',
        'storekeeper' => 'Storekeeper',
        'auditor' => 'Auditor',
    ];

    /**
     * Foundation permissions per module. Granular, dotted names.
     */
    private const PERMISSIONS = [
        'menu' => ['menu.view', 'menu.manage'],
        'orders' => ['orders.view', 'orders.create', 'orders.update', 'orders.void'],
        'tables' => ['tables.view', 'tables.manage'],
        'billing' => ['billing.view', 'billing.charge', 'billing.refund'],
        'staff' => ['staff.view', 'staff.manage'],
        'reports' => ['reports.view'],
    ];

    /**
     * Which permissions each role is granted. Admin gets everything.
     */
    private const ROLE_PERMISSIONS = [
        'manager' => [
            'menu.view', 'menu.manage', 'orders.view', 'orders.create',
            'orders.update', 'orders.void', 'tables.view', 'tables.manage',
            'billing.view', 'billing.charge', 'billing.refund', 'staff.view',
            'staff.manage', 'reports.view',
        ],
        'waiter' => [
            'menu.view', 'orders.view', 'orders.create', 'orders.update',
            'tables.view',
        ],
        'cashier' => [
            'orders.view', 'billing.view', 'billing.charge', 'tables.view',
        ],
        'kitchen' => ['orders.view', 'orders.update'],
        'accountant' => ['billing.view', 'billing.refund', 'reports.view'],
        'storekeeper' => ['menu.view'],
        'auditor' => ['reports.view', 'orders.view', 'billing.view'],
    ];

    public function run(): void
    {
        $roles = [];
        foreach (self::ROLES as $name => $label) {
            $roles[$name] = Role::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        $permissions = [];
        foreach (self::PERMISSIONS as $group) {
            foreach ($group as $name) {
                $permissions[$name] = Permission::updateOrCreate(['name' => $name]);
            }
        }

        // Admin is granted every permission.
        $roles['admin']->permissions()->sync(
            collect($permissions)->pluck('id')->all()
        );

        foreach (self::ROLE_PERMISSIONS as $roleName => $names) {
            $ids = collect($names)
                ->map(fn (string $n) => $permissions[$n]->id)
                ->all();

            $roles[$roleName]->permissions()->sync($ids);
        }
    }
}
