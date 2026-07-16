<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Services\KitchenService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers the Menu & Recipe module end to end: menu authoring (category /
 * product / meal-size variant / modifier), media, the recipe builder with
 * versioning and costing (recipe cost / food cost / yield / waste), nutrition
 * & allergen roll-up, automatic inventory consumption, and RBAC/scope.
 */
class MenuHttpTest extends TestCase
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
        $this->tenant = Tenant::create(['slug' => 'menu-http', 'name' => 'Menu HTTP']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'chef@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('admin');
        TenantSecurityPolicy::create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'POS-1', 'name' => 'POS', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'menu-device')]);
        $this->token = $this->login($this->user, $this->device->id);
    }

    public function test_menu_authoring_category_product_variant_and_modifier(): void
    {
        $category = $this->create('/api/v1/menu/categories', ['code' => 'BURGERS', 'name' => 'Burgers'])
            ->assertCreated()->json('data');
        $this->assertSame('active', $category['status']);

        $product = $this->create('/api/v1/menu/products', [
            'sku' => 'BRG-1', 'name' => 'Classic Burger', 'category_id' => $category['id'],
            'is_meal' => true, 'calories' => 720,
        ])->assertCreated()->json('data');
        $this->assertTrue($product['is_meal']);

        $variant = $this->create("/api/v1/menu/products/{$product['id']}/variants", [
            'code' => 'LARGE', 'meal_size' => 'Large', 'calories' => 950,
        ])->assertCreated()->json('data');
        $this->assertSame('Large', $variant['meal_size']);

        $group = $this->create('/api/v1/menu/modifier-groups', ['code' => 'SAUCE', 'name' => 'Sauces'])
            ->assertCreated()->json('data');
        $this->create("/api/v1/menu/modifier-groups/{$group['id']}/options", [
            'code' => 'BBQ', 'name' => 'BBQ Sauce', 'surcharge_amount' => 500, 'currency' => 'IQD',
        ])->assertCreated();
        $this->create("/api/v1/menu/products/{$product['id']}/modifiers", ['modifier_group_id' => $group['id']])
            ->assertCreated();

        $shown = $this->getJson("/api/v1/menu/products/{$product['id']}")->assertOk()->json('data');
        $this->assertCount(1, $shown['variants']);
    }

    public function test_media_primary_is_exclusive(): void
    {
        $category = $this->create('/api/v1/menu/categories', ['code' => 'C', 'name' => 'C'])->json('data');
        $first = $this->create('/api/v1/menu/media', ['entity_type' => 'category',
            'entity_id' => $category['id'], 'url' => 'https://cdn/a.jpg', 'is_primary' => true])->json('data');
        $second = $this->create('/api/v1/menu/media', ['entity_type' => 'category',
            'entity_id' => $category['id'], 'kind' => 'video', 'url' => 'https://cdn/b.mp4', 'is_primary' => true])->json('data');

        $list = $this->getJson("/api/v1/menu/media?entity_type=category&entity_id={$category['id']}")
            ->assertOk()->json('data');
        $primaries = array_filter($list, fn ($m) => $m['is_primary']);
        $this->assertCount(1, $primaries);
        $this->assertSame($second['id'], array_values($primaries)[0]['id']);
    }

    public function test_recipe_versioning_costing_food_cost_yield_and_waste(): void
    {
        // Two ingredients: 200 minor units and 50 minor units per unit.
        $patty = $this->create('/api/v1/menu/ingredients', ['sku' => 'PATTY', 'name' => 'Beef Patty',
            'stock_unit' => 'unit', 'unit_cost_amount' => 200, 'currency' => 'IQD'])->json('data');
        $bun = $this->create('/api/v1/menu/ingredients', ['sku' => 'BUN', 'name' => 'Bun',
            'stock_unit' => 'unit', 'unit_cost_amount' => 50, 'currency' => 'IQD'])->json('data');

        // A priced sellable variant to compute food cost against.
        $product = $this->create('/api/v1/menu/products', ['sku' => 'P', 'name' => 'Burger'])->json('data');
        $variant = $this->create("/api/v1/menu/products/{$product['id']}/variants", ['code' => 'STD'])->json('data');
        $this->priceVariant($variant['id'], 1000, 'IQD');

        $recipe = $this->create('/api/v1/recipes', ['owner_type' => 'product_variant',
            'owner_id' => $variant['id'], 'name' => 'Classic Burger'])->assertCreated()->json('data');

        // 1 patty + 1 bun, plus 10% (1000 bps) overall production waste, yield 1.
        $version = $this->create("/api/v1/recipes/{$recipe['id']}/versions", [
            'yield_quantity' => 1, 'waste_bps' => 1000, 'components' => [
                ['component_type' => 'stock_item', 'component_id' => $patty['id'], 'quantity' => 1, 'unit' => 'unit'],
                ['component_type' => 'stock_item', 'component_id' => $bun['id'], 'quantity' => 1, 'unit' => 'unit'],
            ],
        ])->assertCreated()->json('data');
        $this->assertSame(250, $version['ingredient_cost_amount']); // 200 + 50
        $this->assertSame(275, $version['recipe_cost_amount']);     // 250 * 1.10 / 1

        $this->create("/api/v1/recipes/{$recipe['id']}/versions/{$version['id']}/publish", [])->assertOk();
        $this->create("/api/v1/recipes/{$recipe['id']}/versions/{$version['id']}/activate", [])->assertOk();

        $cost = $this->getJson("/api/v1/recipes/{$recipe['id']}/cost")->assertOk()->json('data');
        $this->assertSame(275, $cost['recipe_cost_amount']);
        $this->assertSame(1000, $cost['sale_price_amount']);
        $this->assertSame(2750, $cost['food_cost_bps']); // 275/1000 = 27.5% = 2750 bps
    }

    public function test_allergen_tagging_and_product_nutrition_rollup(): void
    {
        $product = $this->create('/api/v1/menu/products', ['sku' => 'N', 'name' => 'Nutty', 'calories' => 400])->json('data');
        $allergen = $this->create('/api/v1/menu/allergens', ['code' => 'NUTS', 'name' => 'Nuts'])->json('data');
        $this->create('/api/v1/menu/allergens/tag', ['allergen_id' => $allergen['id'],
            'entity_type' => 'product', 'entity_id' => $product['id'], 'is_traces' => true])->assertCreated();

        $nutrition = $this->getJson("/api/v1/menu/products/{$product['id']}/nutrition")->assertOk()->json('data');
        $this->assertSame(400, $nutrition['calories']);
        $this->assertSame('manual', $nutrition['calories_source']);
        $this->assertCount(1, $nutrition['allergens']);
        $this->assertTrue($nutrition['allergens'][0]['is_traces']);
    }

    public function test_explicit_consumption_deducts_stock_and_is_idempotent(): void
    {
        $patty = $this->create('/api/v1/menu/ingredients', ['sku' => 'PT', 'name' => 'Patty',
            'stock_unit' => 'unit', 'unit_cost_amount' => 200, 'currency' => 'IQD'])->json('data');
        $product = $this->create('/api/v1/menu/products', ['sku' => 'PX', 'name' => 'Burger'])->json('data');
        $variant = $this->create("/api/v1/menu/products/{$product['id']}/variants", ['code' => 'STD'])->json('data');
        $recipe = $this->create('/api/v1/recipes', ['owner_type' => 'product_variant',
            'owner_id' => $variant['id'], 'name' => 'R'])->json('data');
        $version = $this->create("/api/v1/recipes/{$recipe['id']}/versions", ['components' => [
            ['component_type' => 'stock_item', 'component_id' => $patty['id'], 'quantity' => 2, 'unit' => 'unit'],
        ]])->json('data');
        $this->create("/api/v1/recipes/{$recipe['id']}/versions/{$version['id']}/publish", []);
        $this->create("/api/v1/recipes/{$recipe['id']}/versions/{$version['id']}/activate", []);

        $op = (string) Str::ulid();
        $this->create('/api/v1/inventory/consume', ['product_variant_id' => $variant['id'],
            'quantity' => 3, 'client_operation_id' => $op])->assertCreated();
        // Replay with the same operation id must not double-deduct.
        $this->create('/api/v1/inventory/consume', ['product_variant_id' => $variant['id'],
            'quantity' => 3, 'client_operation_id' => $op])->assertCreated();

        $levels = $this->getJson('/api/v1/inventory/levels')->assertOk()->json('data');
        $pattyLevel = collect($levels)->firstWhere('stockable_id', $patty['id']);
        // 3 sold * 2 per recipe = 6 deducted, recorded once.
        $this->assertSame('-6.000000', $pattyLevel['quantity_on_hand']);
    }

    public function test_serving_a_kitchen_ticket_auto_consumes_inventory(): void
    {
        $patty = $this->create('/api/v1/menu/ingredients', ['sku' => 'AP', 'name' => 'Patty',
            'stock_unit' => 'unit', 'unit_cost_amount' => 200, 'currency' => 'IQD'])->json('data');
        $product = $this->create('/api/v1/menu/products', ['sku' => 'AB', 'name' => 'Burger'])->json('data');
        $variant = $this->create("/api/v1/menu/products/{$product['id']}/variants", ['code' => 'STD'])->json('data');
        $recipe = $this->create('/api/v1/recipes', ['owner_type' => 'product_variant',
            'owner_id' => $variant['id'], 'name' => 'R'])->json('data');
        $version = $this->create("/api/v1/recipes/{$recipe['id']}/versions", ['components' => [
            ['component_type' => 'stock_item', 'component_id' => $patty['id'], 'quantity' => 1, 'unit' => 'unit'],
        ]])->json('data');
        $this->create("/api/v1/recipes/{$recipe['id']}/versions/{$version['id']}/publish", []);
        $this->create("/api/v1/recipes/{$recipe['id']}/versions/{$version['id']}/activate", []);

        // A kitchen station must exist for dispatch to route the ticket.
        $this->withToken($this->token)->postJson('/api/v1/kitchen/stations',
            ['code' => 'MAIN', 'name' => 'Main', 'sla_seconds' => 300])->assertCreated();

        // A placed order for 2 of the variant, dispatched to the kitchen.
        $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'number' => 'ORD-'.Str::random(8), 'type' => 'dine_in', 'currency' => 'IQD', 'state' => 'placed',
            'business_date' => today(), 'client_operation_id' => (string) Str::ulid()]);
        OrderItem::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'order_id' => $order->id, 'product_variant_id' => $variant['id'], 'line_number' => 1,
            'product_name' => 'Burger', 'quantity' => '2.000000', 'unit_price_amount' => 1000,
            'gross_amount' => 2000, 'net_amount' => 2000, 'currency' => 'IQD', 'state' => 'active']);
        $context = new SalesContext($this->tenant->id, $this->branch->id, $this->user->id, $this->device->id);
        $ticket = app(KitchenService::class)->dispatch($context, $order->id, (string) Str::ulid())->first();

        // Advance the ticket to served; the serve hook consumes the BOM.
        $version = 0;
        foreach (['start', 'ready', 'serve'] as $action) {
            $version = (int) $this->withToken($this->token)
                ->postJson("/api/v1/kitchen/tickets/{$ticket->id}/{$action}",
                    ['expected_version' => $version, 'client_operation_id' => (string) Str::ulid()])
                ->assertOk()->json('data.lock_version');
        }

        $levels = $this->getJson('/api/v1/inventory/levels')->assertOk()->json('data');
        $pattyLevel = collect($levels)->firstWhere('stockable_id', $patty['id']);
        $this->assertSame('-2.000000', $pattyLevel['quantity_on_hand']); // 2 sold * 1 per recipe
    }

    public function test_permissions_and_scope_are_enforced(): void
    {
        $plain = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'plain@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($plain);
        $plainToken = $this->login($plain, $this->device->id);
        $this->withToken($plainToken)->postJson('/api/v1/menu/categories', ['code' => 'X', 'name' => 'X'])
            ->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    private function priceVariant(string $variantId, int $amount, string $currency): void
    {
        $list = PriceList::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id,
            'code' => 'PL-'.Str::random(5), 'name' => 'List', 'currency' => $currency, 'status' => 'active']);
        PriceListItem::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id,
            'price_list_id' => $list->id, 'product_variant_id' => $variantId, 'amount' => $amount, 'currency' => $currency]);
    }

    private function create(string $uri, array $body): TestResponse
    {
        return $this->withToken($this->token)->postJson($uri, $body);
    }

    private function login(User $user, ?string $device): string
    {
        return $this->postJson('/api/v1/auth/login', ['tenant_slug' => $this->tenant->slug,
            'identifier' => $user->email, 'password' => 'Password123', 'branch_id' => $this->branch->id,
            'device_id' => $device])->assertOk()->json('data.access_token');
    }
}
