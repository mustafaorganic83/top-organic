<?php

namespace App\Modules\Menu;

use App\Modules\Menu\Livewire\CategoryManager;
use App\Modules\Menu\Livewire\CostReports;
use App\Modules\Menu\Livewire\DishForm;
use App\Modules\Menu\Livewire\DishManager;
use App\Modules\Menu\Livewire\IngredientManager;
use App\Modules\Menu\Livewire\RecipeBuilder;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Menu & Recipe Management module (architecture doc 01 FR-2). Registers the
 * module's versioned API routes: menu categories/products/variants/modifiers
 * and meal sizes (building on the Sales catalog), rich media (images/videos),
 * the recipe builder with versioning, costing (recipe cost / food cost / yield
 * / waste), ingredients & semi-finished stock, nutrition & allergens, and the
 * automatic inventory-consumption ledger. Route definitions carry their own
 * full paths and middleware, mirroring the Kitchen and Tables providers. The
 * module also serves the session-authenticated dish-management back office,
 * whose Livewire components live in the module and so are aliased explicitly
 * rather than discovered under the default App\Livewire namespace.
 */
class MenuServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadRoutesFrom(__DIR__.'/web-routes.php');

        Livewire::component('menu.dish-manager', DishManager::class);
        Livewire::component('menu.dish-form', DishForm::class);
        Livewire::component('menu.recipe-builder', RecipeBuilder::class);
        Livewire::component('menu.category-manager', CategoryManager::class);
        Livewire::component('menu.ingredient-manager', IngredientManager::class);
        Livewire::component('menu.cost-reports', CostReports::class);
    }
}
