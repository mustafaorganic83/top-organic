<?php

namespace Database\Seeders;

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

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])->assignRole('admin');
    }
}
