<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $user;

    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'res-http', 'name' => 'Reservations HTTP']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'res@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('admin');
        TenantSecurityPolicy::create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'POS-RES', 'name' => 'POS Res', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'pos-res')]);
        $this->token = $this->login($this->user, $this->device->id);
    }

    public function test_full_reservation_lifecycle_from_floor_to_seating(): void
    {
        $floor = $this->withToken($this->token)->postJson('/api/v1/tables/floors', [
            'code' => 'GROUND', 'name' => 'Ground Floor', 'layout' => ['grid' => [10, 10]],
        ])->assertCreated()->json('data');

        $table = $this->withToken($this->token)->postJson('/api/v1/tables/tables', [
            'floor_id' => $floor['id'], 'code' => 'T1', 'name' => 'Table 1',
            'area' => 'indoor', 'capacity' => 4,
        ])->assertCreated()->json('data');
        $this->assertSame('available', $table['occupancy_status']);

        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Sara', 'phone' => '07700000000']);

        $reservation = $this->withToken($this->token)->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id, 'party_size' => 3, 'channel' => 'whatsapp',
            'reserved_for' => now()->addHours(2)->toISOString(),
        ])->assertCreated()->json('data');
        $this->assertSame('pending', $reservation['state']);

        $reservation = $this->withToken($this->token)->postJson("/api/v1/reservations/{$reservation['id']}/confirm", [
            'expected_version' => $reservation['lock_version'], 'confirmation_channel' => 'whatsapp',
        ])->assertOk()->json('data');
        $this->assertSame('confirmed', $reservation['state']);

        $reservation = $this->withToken($this->token)->postJson("/api/v1/reservations/{$reservation['id']}/assign-table", [
            'expected_version' => $reservation['lock_version'], 'table_id' => $table['id'],
        ])->assertOk()->json('data');
        $this->assertSame($table['id'], $reservation['dining_table_id']);

        $this->withToken($this->token)->getJson('/api/v1/tables/tables?occupancy_status=reserved')
            ->assertOk()->assertJsonCount(1, 'data');

        $reservation = $this->withToken($this->token)->postJson("/api/v1/reservations/{$reservation['id']}/seat", [
            'expected_version' => $reservation['lock_version'],
        ])->assertOk()->json('data');
        $this->assertSame('seated', $reservation['state']);
        $this->assertNotNull($reservation['table_session_id']);

        $reservation = $this->withToken($this->token)->postJson("/api/v1/reservations/{$reservation['id']}/complete", [
            'expected_version' => $reservation['lock_version'],
        ])->assertOk()->json('data');
        $this->assertSame('completed', $reservation['state']);

        $this->withToken($this->token)->getJson('/api/v1/tables/tables?occupancy_status=available')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($this->token)->getJson("/api/v1/reservations/customers/{$customer->id}/history")
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_capacity_and_state_guards_are_enforced(): void
    {
        $floor = $this->withToken($this->token)->postJson('/api/v1/tables/floors',
            ['code' => 'F1', 'name' => 'F1'])->assertCreated()->json('data');
        $table = $this->withToken($this->token)->postJson('/api/v1/tables/tables',
            ['floor_id' => $floor['id'], 'code' => 'T2', 'capacity' => 2])->assertCreated()->json('data');

        $reservation = $this->withToken($this->token)->postJson('/api/v1/reservations', [
            'guest_name' => 'Big Party', 'party_size' => 6,
        ])->assertCreated()->json('data');

        $this->withToken($this->token)->postJson("/api/v1/reservations/{$reservation['id']}/assign-table", [
            'expected_version' => $reservation['lock_version'], 'table_id' => $table['id'],
        ])->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_CAPACITY_EXCEEDED');

        // Seating without a table is rejected.
        $this->withToken($this->token)->postJson("/api/v1/reservations/{$reservation['id']}/seat", [
            'expected_version' => $reservation['lock_version'],
        ])->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_INVALID_STATE');
    }

    public function test_walk_in_waitlist_flow(): void
    {
        $first = $this->withToken($this->token)->postJson('/api/v1/reservations/waitlist', [
            'guest_name' => 'Ali', 'party_size' => 2,
        ])->assertCreated()->json('data');
        $second = $this->withToken($this->token)->postJson('/api/v1/reservations/waitlist', [
            'guest_name' => 'Noor', 'party_size' => 4,
        ])->assertCreated()->json('data');
        $this->assertSame(1, $first['position']);
        $this->assertSame(2, $second['position']);

        $this->withToken($this->token)->getJson('/api/v1/reservations/waitlist')
            ->assertOk()->assertJsonCount(2, 'data');

        $first = $this->withToken($this->token)->postJson("/api/v1/reservations/waitlist/{$first['id']}/notify", [
            'expected_version' => $first['lock_version'],
        ])->assertOk()->json('data');
        $this->assertSame('notified', $first['state']);

        $this->withToken($this->token)->postJson("/api/v1/reservations/waitlist/{$first['id']}/seat", [
            'expected_version' => $first['lock_version'],
        ])->assertOk()->assertJsonPath('data.state', 'seated');
    }

    public function test_permissions_and_scope_are_enforced(): void
    {
        $ordinary = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'plain@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($ordinary);
        $ordinaryToken = $this->login($ordinary, $this->device->id);

        $this->withToken($ordinaryToken)->getJson('/api/v1/reservations')
            ->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');

        // Trusted scope fields in the payload are rejected.
        $this->withToken($this->token)->postJson('/api/v1/reservations', [
            'guest_name' => 'X', 'party_size' => 2, 'tenant_id' => $this->tenant->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Seating requires an authorized POS device.
        $reservation = $this->withToken($this->token)->postJson('/api/v1/reservations', [
            'guest_name' => 'Y', 'party_size' => 2,
        ])->assertCreated()->json('data');
        $withoutDevice = $this->login($this->user, null);
        $this->withToken($withoutDevice)->postJson("/api/v1/reservations/{$reservation['id']}/seat", [
            'expected_version' => $reservation['lock_version'],
        ])->assertForbidden()->assertJsonPath('error.code', 'POS_DEVICE_REQUIRED');
    }

    private function login(User $user, ?string $device): string
    {
        return $this->postJson('/api/v1/auth/login', ['tenant_slug' => $this->tenant->slug,
            'identifier' => $user->email, 'password' => 'Password123', 'branch_id' => $this->branch->id,
            'device_id' => $device])->assertOk()->json('data.access_token');
    }
}
