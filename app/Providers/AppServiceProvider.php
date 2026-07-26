<?php

namespace App\Providers;

use App\Support\Context\AppContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One resolved tenant/branch context per request/job, shared by the
        // context middleware, services, and the global query scopes.
        $this->app->singleton(AppContext::class);

        // Repository bindings (Repository pattern, SOLID compliant)
        $this->app->bind(\App\Contracts\Repositories\CurrencyRepositoryInterface::class, \App\Repositories\Eloquent\CurrencyRepository::class);
        $this->app->bind(\App\Contracts\Repositories\PurchasePriceRepositoryInterface::class, \App\Repositories\Eloquent\PurchasePriceRepository::class);
        $this->app->bind(\App\Contracts\Repositories\ProductionOrderRepositoryInterface::class, \App\Repositories\Eloquent\ProductionOrderRepository::class);
        $this->app->bind(\App\Contracts\Repositories\WasteRepositoryInterface::class, \App\Repositories\Eloquent\WasteRepository::class);
        $this->app->bind(\App\Contracts\Repositories\CostSnapshotRepositoryInterface::class, \App\Repositories\Eloquent\CostSnapshotRepository::class);
        $this->app->bind(\App\Contracts\Repositories\CostHistoryRepositoryInterface::class, \App\Repositories\Eloquent\CostHistoryRepository::class);

        // Recipe Management
        $this->app->bind(\App\Contracts\Repositories\RecipeRepositoryInterface::class, \App\Repositories\Eloquent\RecipeRepository::class);
        $this->app->bind(\App\Contracts\Repositories\RecipeVersionRepositoryInterface::class, \App\Repositories\Eloquent\RecipeVersionRepository::class);
        $this->app->bind(\App\Contracts\Repositories\RecipeComponentRepositoryInterface::class, \App\Repositories\Eloquent\RecipeComponentRepository::class);

        // Prepared Items
        $this->app->bind(\App\Contracts\Repositories\PreparedItemRepositoryInterface::class, \App\Repositories\Eloquent\PreparedItemRepository::class);

        // Costing Engine + strategies
        $this->app->singleton(\App\Services\Costing\CostingEngine::class, function ($app) {
            $config = new \App\Services\Costing\CostingConfig(
                wasteMode: 'multiplicative',
                defaultDeptWasteBps: 0,
                lineWasteField: 'waste_bps',
                versionWasteField: 'waste_bps',
                includePackaging: true,
                includeModifiers: false,
                moneyDivisor: 100,
                avgLookbackDays: 90,
            );
            $strategies = [
                \App\Services\Costing\CostMethod::LAST_PURCHASE => new \App\Services\Costing\Strategies\LastPurchaseStrategy($app->make(\App\Contracts\Repositories\PurchasePriceRepositoryInterface::class)),
                \App\Services\Costing\CostMethod::STANDARD => new \App\Services\Costing\Strategies\StandardCostStrategy($app->make(\App\Contracts\Repositories\CostHistoryRepositoryInterface::class)),
                \App\Services\Costing\CostMethod::AVERAGE => new \App\Services\Costing\Strategies\AverageStrategy(100, 90),
                \App\Services\Costing\CostMethod::WEIGHTED_AVG => new \App\Services\Costing\Strategies\WeightedAverageStrategy(100, 90),
                \App\Services\Costing\CostMethod::FIFO => new \App\Services\Costing\Strategies\FifoStrategy(100),
                \App\Services\Costing\CostMethod::LIFO => new \App\Services\Costing\Strategies\LifoStrategy(100),
            ];
            return new \App\Services\Costing\CostingEngine(
                strategies: $strategies,
                config: $config,
                purchasePrices: $app->make(\App\Contracts\Repositories\PurchasePriceRepositoryInterface::class),
            );
        });

        // Reporting Module
        $this->app->singleton(\App\Reports\ReportManager::class, function () {
            return new \App\Reports\ReportManager([
                new \App\Reports\RecipeCostReport(),
                new \App\Reports\FoodCostReport(),
                new \App\Reports\DepartmentCostReport(),
                new \App\Reports\IngredientCostReport(),
                new \App\Reports\PreparedItemCostReport(),
                new \App\Reports\WasteReport(),
                new \App\Reports\YieldReport(),
                new \App\Reports\InventoryCostReport(),
                new \App\Reports\HistoricalCostReport(),
                new \App\Reports\PriceHistoryReport(),
                new \App\Reports\PriceIncreaseImpactReport(),
                new \App\Reports\ProfitabilityReport(),
                new \App\Reports\AbcAnalysisReport(),
                new \App\Reports\MenuEngineeringReport(),
                new \App\Reports\VarianceReport(),
                new \App\Reports\TheoreticalVsActualReport(),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers for automatic cost snapshots (historical immutability)
        \App\Models\PurchasePrice::observe(\App\Observers\PurchasePriceObserver::class);
        \App\Models\RecipeVersion::observe(\App\Observers\RecipeVersionObserver::class);
        \App\Models\RecipeComponent::observe(\App\Observers\RecipeComponentObserver::class);
        \App\Models\SemiFinishedProduct::observe(\App\Observers\SemiFinishedProductObserver::class);
        \App\Models\InventoryMovement::observe(\App\Observers\InventoryMovementObserver::class);
        \App\Models\ProductionOrder::observe(\App\Observers\ProductionOrderObserver::class);
    }
}
