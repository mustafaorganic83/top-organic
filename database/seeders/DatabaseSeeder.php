<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with the Phase 0 single-tenant
     * foundation: a default tenant, RBAC roles/permissions, and an admin user.
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'top-organic'],
            ['name' => 'Top Organic', 'is_active' => true],
        );

        $this->call(RolePermissionSeeder::class);

        $user = User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Test Admin',
                'password' => \Hash::make('password'),
                'is_active' => true,
            ]
        );
        $user->assignRole('admin');

        $branch = Branch::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'B001'],
            ['name' => 'Main Branch', 'is_active' => true]
        );

        $user->branches()->sync([$branch->id]);

        Device::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'POS-01'],
            [
                'branch_id' => $branch->id,
                'name' => 'Main POS',
                'type' => 'pos',
                'status' => 'authorized',
                'key_fingerprint' => 'demo-pos-fingerprint',
                'authorized_at' => now(),
                'authorized_by' => $user->id,
            ]
        );
    }
}
