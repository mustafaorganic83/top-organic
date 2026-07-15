<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\PermissionGroup;
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
        'sales' => [
            'sales.catalog.view',
            'sales.orders.view', 'sales.orders.create', 'sales.orders.update', 'sales.orders.place',
            'sales.orders.state', 'sales.orders.discount', 'sales.orders.charges', 'sales.orders.transfer',
            'sales.customers.view', 'sales.customers.manage', 'sales.customers.history', 'sales.customers.membership',
            'sales.gift_cards.view', 'sales.gift_cards.issue', 'sales.gift_cards.load',
            'sales.gift_cards.redeem', 'sales.gift_cards.reverse',
            'sales.billing.view', 'sales.billing.capture', 'sales.billing.reverse',
            'sales.kds.view', 'sales.kds.dispatch', 'sales.kds.manage',
            'sales.printing.view', 'sales.printing.create', 'sales.printing.edge',
            'sales.sync.push', 'sales.sync.pull', 'sales.sync.conflicts.view', 'sales.sync.conflicts.resolve',
        ],
        'pos' => [
            'pos.shifts.view', 'pos.shifts.manage', 'pos.cash.manage', 'pos.cash.reverse',
            'pos.tables.view', 'pos.tables.manage',
        ],
        'identity' => [
            'identity.permissions.view',
            'identity.roles.view',
            'identity.roles.manage',
            'identity.roles.assign',
            'identity.devices.view',
            'identity.devices.manage',
            'identity.audit.view',
        ],
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
            'sales.catalog.view', 'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
            'sales.orders.place', 'sales.orders.state', 'sales.orders.discount', 'sales.orders.charges',
            'sales.orders.transfer', 'sales.customers.view', 'sales.customers.manage', 'sales.customers.history',
            'sales.customers.membership', 'sales.gift_cards.view', 'sales.gift_cards.issue', 'sales.gift_cards.load',
            'sales.gift_cards.redeem', 'sales.gift_cards.reverse', 'sales.billing.view', 'sales.billing.capture',
            'sales.billing.reverse', 'sales.kds.view', 'sales.kds.dispatch', 'sales.kds.manage',
            'sales.printing.view', 'sales.printing.create', 'pos.shifts.view', 'pos.shifts.manage',
            'pos.cash.manage', 'pos.cash.reverse', 'pos.tables.view', 'pos.tables.manage',
            'sales.sync.push', 'sales.sync.pull', 'sales.sync.conflicts.view', 'sales.sync.conflicts.resolve',
        ],
        'waiter' => [
            'menu.view', 'orders.view', 'orders.create', 'orders.update',
            'tables.view',
            'sales.catalog.view', 'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
            'sales.orders.place', 'sales.orders.state', 'sales.customers.view', 'sales.customers.manage',
            'sales.customers.history', 'sales.kds.dispatch', 'sales.printing.create',
            'pos.shifts.view', 'pos.tables.view', 'pos.tables.manage',
            'sales.sync.push', 'sales.sync.pull',
        ],
        'cashier' => [
            'orders.view', 'billing.view', 'billing.charge', 'tables.view',
            'sales.catalog.view', 'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
            'sales.orders.place', 'sales.orders.discount', 'sales.orders.charges', 'sales.orders.transfer',
            'sales.customers.view', 'sales.customers.manage', 'sales.customers.history',
            'sales.gift_cards.view', 'sales.gift_cards.issue', 'sales.gift_cards.load',
            'sales.gift_cards.redeem', 'sales.billing.view', 'sales.billing.capture',
            'sales.printing.view', 'sales.printing.create', 'pos.shifts.view', 'pos.shifts.manage',
            'pos.cash.manage', 'pos.tables.view', 'pos.tables.manage',
            'sales.sync.push', 'sales.sync.pull', 'sales.sync.conflicts.view',
        ],
        'kitchen' => [
            'orders.view', 'orders.update', 'sales.orders.view', 'sales.kds.view',
            'sales.kds.manage', 'sales.printing.view', 'sales.printing.edge',
        ],
        'accountant' => [
            'billing.view', 'billing.refund', 'reports.view', 'sales.orders.view',
            'sales.customers.view', 'sales.customers.history', 'sales.billing.view', 'sales.billing.reverse',
        ],
        'storekeeper' => ['menu.view'],
        'auditor' => [
            'reports.view', 'orders.view', 'billing.view', 'sales.orders.view',
            'sales.customers.view', 'sales.customers.history', 'sales.gift_cards.view',
            'sales.billing.view', 'sales.kds.view', 'sales.printing.view',
            'pos.shifts.view', 'pos.tables.view', 'sales.sync.conflicts.view',
        ],
    ];

    public function run(): void
    {
        $roles = [];
        foreach (self::ROLES as $name => $label) {
            $roles[$name] = Role::updateOrCreate(
                ['tenant_id' => null, 'name' => $name],
                ['label' => $label, 'is_system' => true, 'status' => 'active'],
            );
        }

        $groups = [];
        foreach (array_keys(self::PERMISSIONS) as $index => $code) {
            $groups[$code] = PermissionGroup::updateOrCreate(
                ['code' => $code],
                ['name' => (string) str($code)->headline(), 'display_order' => $index],
            );
        }

        $permissions = [];
        foreach (self::PERMISSIONS as $groupCode => $groupPermissions) {
            foreach ($groupPermissions as $name) {
                $permissions[$name] = Permission::updateOrCreate(
                    ['name' => $name],
                    ['permission_group_id' => $groups[$groupCode]->id],
                );
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
