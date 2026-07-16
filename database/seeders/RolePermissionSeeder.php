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
        'hr_manager' => 'HR Manager',
    ];

    /**
     * Foundation permissions per module. Granular, dotted names.
     */
    private const PERMISSIONS = [
        'menu' => ['menu.view', 'menu.manage'],
        'orders' => ['orders.view', 'orders.create', 'orders.update', 'orders.void'],
        'tables' => ['tables.view', 'tables.manage', 'tables.design'],
        'reservations' => [
            'reservations.view', 'reservations.create', 'reservations.manage',
            'reservations.assign', 'reservations.seat',
        ],
        'kitchen' => [
            'kitchen.view', 'kitchen.operate', 'kitchen.assign',
            'kitchen.manage', 'kitchen.analytics',
        ],
        'recipe' => ['recipe.view', 'recipe.manage', 'recipe.publish'],
        'inventory' => ['inventory.view', 'inventory.manage', 'inventory.consume'],
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
        'accounting' => [
            'accounting.view',
            'accounting.manage',
            'accounting.journal.view',
            'accounting.journal.create',
            'accounting.journal.post',
            'accounting.ap.view',
            'accounting.ap.manage',
            'accounting.ap.pay',
            'accounting.ar.view',
            'accounting.ar.manage',
            'accounting.bank.view',
            'accounting.bank.manage',
            'accounting.budget.view',
            'accounting.budget.manage',
            'accounting.reports.view',
        ],
        'procurement' => [
            'procurement.view',
            'procurement.manage',
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.rfq.view',
            'procurement.rfq.manage',
            'procurement.po.view',
            'procurement.po.manage',
            'procurement.po.approve',
            'procurement.receiving.manage',
            'procurement.inspection.manage',
            'procurement.contracts.manage',
        ],
        'hr' => [
            'hr.employees.view',
            'hr.employees.manage',
            'hr.attendance.view',
            'hr.attendance.record',
            'hr.attendance.manage',
            'hr.leave.view',
            'hr.leave.request',
            'hr.leave.approve',
            'hr.loans.view',
            'hr.loans.manage',
            'hr.loans.approve',
            'hr.payroll.view',
            'hr.payroll.run',
            'hr.payroll.approve',
            'hr.performance.manage',
            'hr.documents.manage',
            'hr.tasks.view',
            'hr.tasks.manage',
        ],
    ];

    /**
     * Which permissions each role is granted. Admin gets everything.
     */
    private const ROLE_PERMISSIONS = [
        'manager' => [
            'menu.view', 'menu.manage', 'orders.view', 'orders.create',
            'orders.update', 'orders.void', 'tables.view', 'tables.manage', 'tables.design',
            'reservations.view', 'reservations.create', 'reservations.manage',
            'reservations.assign', 'reservations.seat',
            'billing.view', 'billing.charge', 'billing.refund', 'staff.view',
            'staff.manage', 'reports.view',
            'sales.catalog.view', 'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
            'sales.orders.place', 'sales.orders.state', 'sales.orders.discount', 'sales.orders.charges',
            'sales.orders.transfer', 'sales.customers.view', 'sales.customers.manage', 'sales.customers.history',
            'sales.customers.membership', 'sales.gift_cards.view', 'sales.gift_cards.issue', 'sales.gift_cards.load',
            'sales.gift_cards.redeem', 'sales.gift_cards.reverse', 'sales.billing.view', 'sales.billing.capture',
            'sales.billing.reverse', 'sales.kds.view', 'sales.kds.dispatch', 'sales.kds.manage',
            'kitchen.view', 'kitchen.operate', 'kitchen.assign', 'kitchen.manage', 'kitchen.analytics',
            'recipe.view', 'recipe.manage', 'recipe.publish',
            'inventory.view', 'inventory.manage', 'inventory.consume',
            'sales.printing.view', 'sales.printing.create', 'pos.shifts.view', 'pos.shifts.manage',
            'pos.cash.manage', 'pos.cash.reverse', 'pos.tables.view', 'pos.tables.manage',
            'sales.sync.push', 'sales.sync.pull', 'sales.sync.conflicts.view', 'sales.sync.conflicts.resolve',
            'accounting.view', 'accounting.reports.view',
            'accounting.budget.view', 'accounting.ap.view', 'accounting.ar.view',
            'accounting.bank.view',
            'procurement.view', 'procurement.manage',
            'procurement.suppliers.view', 'procurement.suppliers.manage',
            'procurement.rfq.view', 'procurement.rfq.manage',
            'procurement.po.view', 'procurement.po.manage', 'procurement.po.approve',
            'procurement.receiving.manage', 'procurement.inspection.manage',
            'procurement.contracts.manage',
            'hr.employees.view', 'hr.employees.manage',
            'hr.attendance.view', 'hr.attendance.record', 'hr.attendance.manage',
            'hr.leave.view', 'hr.leave.request', 'hr.leave.approve',
            'hr.loans.view', 'hr.loans.manage', 'hr.loans.approve',
            'hr.payroll.view', 'hr.payroll.run', 'hr.payroll.approve',
            'hr.performance.manage', 'hr.documents.manage',
            'hr.tasks.view', 'hr.tasks.manage',
        ],
        'waiter' => [
            'menu.view', 'orders.view', 'orders.create', 'orders.update',
            'tables.view', 'tables.manage', 'kitchen.view', 'kitchen.operate',
            'reservations.view', 'reservations.create', 'reservations.manage',
            'reservations.assign', 'reservations.seat', 'inventory.consume',
            'sales.catalog.view', 'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
            'sales.orders.place', 'sales.orders.state', 'sales.customers.view', 'sales.customers.manage',
            'sales.customers.history', 'sales.kds.dispatch', 'sales.printing.create',
            'pos.shifts.view', 'pos.tables.view', 'pos.tables.manage',
            'sales.sync.push', 'sales.sync.pull',
        ],
        'cashier' => [
            'orders.view', 'billing.view', 'billing.charge', 'tables.view',
            'reservations.view', 'reservations.create',
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
            'kitchen.view', 'kitchen.operate', 'kitchen.assign', 'kitchen.analytics',
            'menu.view', 'recipe.view', 'inventory.view', 'inventory.consume',
        ],
        'accountant' => [
            'billing.view', 'billing.refund', 'reports.view', 'sales.orders.view',
            'sales.customers.view', 'sales.customers.history', 'sales.billing.view', 'sales.billing.reverse',
            'accounting.view', 'accounting.manage',
            'accounting.journal.view', 'accounting.journal.create', 'accounting.journal.post',
            'accounting.ap.view', 'accounting.ap.manage', 'accounting.ap.pay',
            'accounting.ar.view', 'accounting.ar.manage',
            'accounting.bank.view', 'accounting.bank.manage',
            'accounting.budget.view', 'accounting.budget.manage',
            'accounting.reports.view',
        ],
        'storekeeper' => [
            'menu.view', 'recipe.view', 'recipe.manage', 'recipe.publish',
            'inventory.view', 'inventory.manage', 'inventory.consume',
            'procurement.view', 'procurement.manage',
            'procurement.suppliers.view', 'procurement.suppliers.manage',
            'procurement.rfq.view', 'procurement.rfq.manage',
            'procurement.po.view', 'procurement.po.manage',
            'procurement.receiving.manage', 'procurement.inspection.manage',
            'procurement.contracts.manage',
        ],
        'auditor' => [
            'reports.view', 'orders.view', 'billing.view', 'sales.orders.view',
            'sales.customers.view', 'sales.customers.history', 'sales.gift_cards.view',
            'sales.billing.view', 'sales.kds.view', 'sales.printing.view',
            'kitchen.view', 'kitchen.analytics',
            'pos.shifts.view', 'pos.tables.view', 'sales.sync.conflicts.view',
            'tables.view', 'reservations.view',
            'recipe.view', 'inventory.view',
            'accounting.view', 'accounting.reports.view',
            'accounting.journal.view', 'accounting.ap.view', 'accounting.ar.view',
            'accounting.bank.view', 'accounting.budget.view',
            'procurement.view', 'procurement.suppliers.view',
            'procurement.rfq.view', 'procurement.po.view',
            'hr.employees.view', 'hr.attendance.view', 'hr.leave.view',
            'hr.loans.view', 'hr.payroll.view', 'hr.tasks.view',
        ],
        'hr_manager' => [
            'hr.employees.view', 'hr.employees.manage',
            'hr.attendance.view', 'hr.attendance.record', 'hr.attendance.manage',
            'hr.leave.view', 'hr.leave.request', 'hr.leave.approve',
            'hr.loans.view', 'hr.loans.manage', 'hr.loans.approve',
            'hr.payroll.view', 'hr.payroll.run', 'hr.payroll.approve',
            'hr.performance.manage', 'hr.documents.manage',
            'hr.tasks.view', 'hr.tasks.manage',
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
