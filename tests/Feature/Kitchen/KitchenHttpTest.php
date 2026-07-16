<?php

declare(strict_types=1);

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Services\KitchenService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KitchenHttpTest extends TestCase
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
        $this->tenant = Tenant::create(['slug' => 'kitchen-http', 'name' => 'Kitchen HTTP']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'chef@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('admin');
        TenantSecurityPolicy::create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'KDS-1', 'name' => 'KDS Display', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'kds-device')]);
        $this->token = $this->login($this->user, $this->device->id);
    }

    public function test_station_configuration_and_board_phases(): void
    {
        $station = $this->withToken($this->token)->postJson('/api/v1/kitchen/stations', [
            'code' => 'GRILL', 'name' => 'Grill Line', 'station_type' => 'grill', 'sla_seconds' => 600,
        ])->assertCreated()->json('data');
        $this->assertSame('active', $station['status']);

        $ticket = $this->seedTicket($station['id']);

        $board = $this->withToken($this->token)->getJson('/api/v1/kitchen/board')
            ->assertOk()->json('data');
        $this->assertCount(1, $board['preparation']);
        $this->assertCount(0, $board['cooking']);
        $this->assertSame($ticket['id'], $board['preparation'][0]['id']);
        $this->assertArrayHasKey('timer', $board['preparation'][0]);
        $this->assertSame(600, $board['preparation'][0]['timer']['sla_seconds']);
    }

    public function test_lifecycle_assignment_priority_and_analytics(): void
    {
        $station = $this->withToken($this->token)->postJson('/api/v1/kitchen/stations', [
            'code' => 'MAIN', 'name' => 'Main Kitchen', 'sla_seconds' => 300,
        ])->assertCreated()->json('data');
        $ticket = $this->seedTicket($station['id']);

        $ticket = $this->act($ticket['id'], 'assign', ['chef_id' => $this->user->id,
            'expected_version' => $ticket['lock_version']]);
        $this->assertSame($this->user->id, $ticket['chef_id']);

        $ticket = $this->act($ticket['id'], 'priority', ['is_priority' => true,
            'expected_version' => $ticket['lock_version']]);
        $this->assertTrue($ticket['is_priority']);

        $ticket = $this->act($ticket['id'], 'start', ['expected_version' => $ticket['lock_version']]);
        $this->assertSame('in_progress', $ticket['state']);
        $this->assertNotNull($ticket['started_at']);

        $ticket = $this->act($ticket['id'], 'ready', ['expected_version' => $ticket['lock_version']]);
        $this->assertSame('ready', $ticket['state']);
        $this->assertNotNull($ticket['timer']['prep_seconds']);

        $ticket = $this->act($ticket['id'], 'serve', ['expected_version' => $ticket['lock_version']]);
        $this->assertSame('served', $ticket['state']);

        $this->withToken($this->token)->getJson('/api/v1/kitchen/queues/served')
            ->assertOk()->assertJsonCount(1, 'data');

        $kpis = $this->withToken($this->token)->getJson('/api/v1/kitchen/analytics/kpis')
            ->assertOk()->json('data');
        $this->assertSame(1, $kpis['served_count']);

        $chefs = $this->withToken($this->token)->getJson('/api/v1/kitchen/analytics/chefs')
            ->assertOk()->json('data');
        $this->assertSame($this->user->id, $chefs[0]['chef_id']);
        $this->assertSame(1, $chefs[0]['served_count']);
    }

    public function test_stale_version_and_permissions_are_enforced(): void
    {
        $station = $this->withToken($this->token)->postJson('/api/v1/kitchen/stations',
            ['code' => 'C', 'name' => 'C'])->assertCreated()->json('data');
        $ticket = $this->seedTicket($station['id']);

        $this->withToken($this->token)->postJson("/api/v1/kitchen/tickets/{$ticket['id']}/start", [
            'expected_version' => 99, 'client_operation_id' => (string) Str::ulid(),
        ])->assertStatus(409)->assertJsonPath('error.code', 'KITCHEN_STALE_VERSION');

        $plain = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'plain@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($plain);
        $plainToken = $this->login($plain, $this->device->id);
        $this->withToken($plainToken)->getJson('/api/v1/kitchen/board')
            ->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    /** @return array<string, mixed> */
    private function act(string $id, string $action, array $body): array
    {
        return $this->withToken($this->token)->postJson("/api/v1/kitchen/tickets/{$id}/{$action}", [
            ...$body, 'client_operation_id' => (string) Str::ulid(),
        ])->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function seedTicket(string $stationId): array
    {
        $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'number' => 'ORD-'.Str::random(8), 'type' => 'dine_in', 'currency' => 'IQD', 'state' => 'placed',
            'business_date' => today(), 'client_operation_id' => (string) Str::ulid()]);
        OrderItem::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'order_id' => $order->id, 'line_number' => 1, 'product_name' => 'Burger', 'quantity' => '1.000000',
            'unit_price_amount' => 25000, 'gross_amount' => 25000, 'net_amount' => 25000, 'currency' => 'IQD',
            'state' => 'active']);
        $context = new SalesContext($this->tenant->id, $this->branch->id, $this->user->id, $this->device->id);
        $ticket = app(KitchenService::class)->dispatch($context, $order->id, (string) Str::ulid())->first();

        return $this->withToken($this->token)->getJson("/api/v1/kitchen/tickets/{$ticket->id}")
            ->assertOk()->json('data');
    }

    private function login(User $user, ?string $device): string
    {
        return $this->postJson('/api/v1/auth/login', ['tenant_slug' => $this->tenant->slug,
            'identifier' => $user->email, 'password' => 'Password123', 'branch_id' => $this->branch->id,
            'device_id' => $device])->assertOk()->json('data.access_token');
    }
}
