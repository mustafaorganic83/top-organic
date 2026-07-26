<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Livewire\CategoryManager;
use App\Modules\Menu\Livewire\CostReports;
use App\Modules\Menu\Livewire\DishForm;
use App\Modules\Menu\Livewire\DishManager;
use App\Modules\Menu\Livewire\IngredientManager;
use App\Modules\Menu\Livewire\RecipeBuilder;
use App\Modules\Menu\Services\DishDeletionGuard;
use App\Support\Context\AppContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the session-authenticated dish-management back office: the Livewire
 * CRUDs, the recipe builder's live costing, the referential deletion guards,
 * and the PDF/Excel cost-report downloads.
 */
class DishManagementUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'dish-ui', 'name' => 'Dish UI']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'manager@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('admin');

        $this->withoutVite();
        $this->actingAs($this->user, 'web');

        // Livewire::test() bypasses route middleware, so the scope the
        // web.context middleware would resolve is established directly.
        app(AppContext::class)->setTenantId($this->tenant->id)->setBranchId($this->branch->id);
    }

    public function test_dish_screen_creates_a_dish_with_a_variant_and_a_costed_recipe(): void
    {
        $category = $this->category();

        Livewire::test(DishForm::class)
            ->set('sku', 'BRG-1')->set('name', 'برجر كلاسيك')
            ->set('categoryId', $category->id)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::withoutGlobalScopes()->where('sku', 'BRG-1')->firstOrFail();
        $this->assertSame($category->id, $product->category_id);

        Livewire::test(DishForm::class, ['product' => $product->id])
            ->set('variantCode', 'REG')->set('variantName', 'عادي')
            ->call('addVariant')
            ->assertHasNoErrors();

        $variant = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->firstOrFail();
        $beef = $this->stockItem('BEEF', 2500, 1000);

        Livewire::test(RecipeBuilder::class, ['variantId' => $variant->id])
            ->set('yieldQuantity', 2)->set('yieldUnit', 'portion')->set('wastePercent', 0)
            ->set('lines.0.component_type', 'stock_item')
            ->set('lines.0.component_id', $beef->id)
            ->set('lines.0.quantity', 4)
            ->set('lines.0.unit', 'kg')
            ->set('lines.0.waste_percent', 10)
            // 2500 * 4 = 10000, +10% line waste = 11000, over a yield of 2 = 5500.
            ->assertViewHas('costed', fn (array $costed): bool => $costed['ingredient_cost'] === 11000
                && $costed['recipe_cost'] === 5500)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('recipe_versions', ['recipe_cost_amount' => 5500, 'state' => 'draft']);
    }

    public function test_a_dish_on_an_open_order_cannot_be_deleted(): void
    {
        $product = $this->product('BRG-2', 'برجر مفتوح');
        $this->openOrderFor($product);

        Livewire::test(DishManager::class)
            ->call('confirmDelete', $product->id)
            ->call('delete')
            ->assertSet('deletingId', null);

        $this->assertNotNull(Product::withoutGlobalScopes()->find($product->id));
        $this->expectException(MenuException::class);
        app(DishDeletionGuard::class)->assertProductDeletable($this->menuContext(), $product->id);
    }

    public function test_a_category_holding_dishes_cannot_be_deleted(): void
    {
        $category = $this->category();
        $this->product('BRG-3', 'برجر مصنف', $category->id);

        Livewire::test(CategoryManager::class)
            ->call('confirmDelete', $category->id)
            ->call('delete')
            ->assertSet('deletingId', null);

        $this->assertNotNull(Category::withoutGlobalScopes()->find($category->id));
        $this->expectException(MenuException::class);
        app(DishDeletionGuard::class)->assertCategoryDeletable($this->menuContext(), $category->id);
    }

    public function test_ingredient_screen_stores_the_typed_price_as_minor_units(): void
    {
        Livewire::test(IngredientManager::class)
            ->set('sku', 'TOM')->set('name', 'طماطم')
            ->set('stockUnit', 'kg')->set('currency', 'IQD')
            ->set('unitCost', 1750)->set('wastePercent', 5)
            ->call('save')
            ->assertHasNoErrors();

        $item = StockItem::withoutGlobalScopes()->where('sku', 'TOM')->firstOrFail();
        $this->assertSame(1750, $item->unit_cost_amount);
        $this->assertSame(500, $item->default_waste_bps);
    }

    public function test_cost_reports_render_and_export(): void
    {
        $this->product('BRG-4', 'برجر تقرير');

        Livewire::test(CostReports::class)->assertOk();

        $this->get(route('menu-reports.pdf', ['kind' => 'dish_cost']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('menu-reports.excel', ['kind' => 'ingredient_cost']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->get(route('menu-reports.pdf', ['kind' => 'bogus']))->assertNotFound();
    }

    /**
     * The back office is Arabic-first. SetLocale honours X-Locale over the
     * browser's Accept-Language, so an Arabic client gets an RTL document with
     * the translated navigation on every screen.
     */
    public function test_back_office_screens_render_right_to_left_in_arabic(): void
    {
        $screens = ['dishes.index', 'dishes.create', 'dish-categories.index',
            'ingredients.index', 'menu-reports.index'];

        foreach ($screens as $screen) {
            $this->withHeader('X-Locale', 'ar')->get(route($screen))
                ->assertOk()
                ->assertSee('dir="rtl"', false)
                ->assertSee('lang="ar"', false)
                ->assertSee(__('menu.nav.dishes'), false);
        }
    }

    private function menuContext(): MenuContext
    {
        return new MenuContext($this->tenant->id, $this->branch->id, (int) $this->user->getKey(), null);
    }

    private function category(): Category
    {
        return Category::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'BURGERS', 'name' => 'برجر',
        ]);
    }

    private function product(string $sku, string $name, ?string $categoryId = null): Product
    {
        return Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $name,
            'category_id' => $categoryId,
        ]);
    }

    private function stockItem(string $sku, int $unitCost, int $wasteBps): StockItem
    {
        return StockItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $sku,
            'stock_unit' => 'kg', 'unit_cost_amount' => $unitCost, 'currency' => 'IQD',
            'default_waste_bps' => $wasteBps,
        ]);
    }

    /** An order line in an open state, which blocks deletion of the dish. */
    private function openOrderFor(Product $product): void
    {
        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'number' => 'ORD-'.Str::random(8), 'type' => 'dine_in', 'currency' => 'IQD',
            'state' => 'placed', 'business_date' => today(), 'client_operation_id' => (string) Str::ulid(),
        ]);

        OrderItem::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'order_id' => $order->id, 'product_id' => $product->id, 'line_number' => 1,
            'product_name' => $product->name, 'quantity' => '1.000000', 'unit_price_amount' => 1000,
            'gross_amount' => 1000, 'net_amount' => 1000, 'currency' => 'IQD', 'state' => 'active',
        ]);
    }
}
