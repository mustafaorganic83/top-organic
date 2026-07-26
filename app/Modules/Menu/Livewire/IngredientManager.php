<?php

declare(strict_types=1);

namespace App\Modules\Menu\Livewire;

use App\Models\SemiFinishedProduct;
use App\Models\StockItem;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Livewire\Concerns\ResolvesMenuContext;
use App\Modules\Menu\Services\IngredientService;
use App\Modules\Menu\Support\MoneyFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The ingredient master: raw stock items on one tab, prepared (semi-finished)
 * items on the other. Both live in one component because the recipe builder
 * treats them as interchangeable components and they share the same form flow.
 */
class IngredientManager extends Component
{
    use ResolvesMenuContext;

    #[Url(as: 'tab', except: 'stock')]
    public string $tab = 'stock';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?string $editingId = null;

    public int $lockVersion = 0;

    public string $sku = '';

    public string $name = '';

    public string $kind = 'ingredient';

    public string $stockUnit = '';

    /** Last purchase price as typed; converted to minor units on save. */
    public float $unitCost = 0;

    public string $currency = '';

    public float $wastePercent = 0;

    public ?float $caloriesPerUnit = null;

    public string $yieldUnit = '';

    public float $yieldQuantity = 1;

    public string $status = 'active';

    public ?string $deletingId = null;

    /** @var Collection<int, StockItem>|null */
    private ?Collection $stockCache = null;

    /** @var Collection<int, SemiFinishedProduct>|null */
    private ?Collection $semiCache = null;

    public function mount(): void
    {
        $this->authorizeMenu('menu.view');
        $this->currency = (string) config('region.currency.primary', 'IQD');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $shared = [
            'sku' => ['required', 'string', 'max:96'],
            'name' => ['required', 'string', 'max:255'],
            'caloriesPerUnit' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];

        if ($this->tab === 'semi') {
            return [...$shared,
                'yieldUnit' => ['required', 'string', 'max:24'],
                'yieldQuantity' => ['required', 'numeric', 'min:0.000001'],
            ];
        }

        return [...$shared,
            'kind' => ['required', Rule::in(['ingredient', 'packaging'])],
            'stockUnit' => ['required', 'string', 'max:24'],
            'unitCost' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'wastePercent' => ['required', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    public function updatedTab(): void
    {
        $this->cancel();
    }

    public function edit(string $id): void
    {
        $this->authorizeMenu('menu.manage');

        if ($this->tab === 'semi') {
            $item = $this->semiFinished()->firstWhere('id', $id);
            if (! $item instanceof SemiFinishedProduct) {
                return;
            }

            $this->fillCommon($item->id, $item->lock_version, $item->sku, $item->name, $item->status);
            $this->yieldUnit = $item->yield_unit;
            $this->yieldQuantity = (float) $item->yield_quantity;
            $this->caloriesPerUnit = $item->calories_per_unit === null ? null : (float) $item->calories_per_unit;

            return;
        }

        $item = $this->stockItems()->firstWhere('id', $id);
        if (! $item instanceof StockItem) {
            return;
        }

        $this->fillCommon($item->id, $item->lock_version, $item->sku, $item->name, $item->status);
        $this->kind = $item->kind;
        $this->stockUnit = $item->stock_unit;
        $this->currency = $item->currency;
        $this->unitCost = MoneyFormatter::toDecimal($item->unit_cost_amount, $item->currency);
        $this->wastePercent = $item->default_waste_bps / 100;
        $this->caloriesPerUnit = $item->calories_per_unit === null ? null : (float) $item->calories_per_unit;
    }

    public function cancel(): void
    {
        $this->reset([
            'editingId', 'lockVersion', 'sku', 'name', 'kind', 'stockUnit', 'unitCost',
            'wastePercent', 'caloriesPerUnit', 'yieldUnit', 'yieldQuantity', 'status',
        ]);
        $this->currency = (string) config('region.currency.primary', 'IQD');
        $this->resetErrorBag();
    }

    public function save(IngredientService $service): void
    {
        $this->authorizeMenu('menu.manage');
        $this->validate();

        try {
            $this->tab === 'semi' ? $this->saveSemiFinished($service) : $this->saveStockItem($service);
        } catch (MenuException $exception) {
            $this->addError('sku', $exception->getMessage());

            return;
        }

        session()->flash('status', __('menu.ingredients.saved'));
        $this->stockCache = null;
        $this->semiCache = null;
        $this->cancel();
    }

    public function confirmDelete(string $id): void
    {
        $this->authorizeMenu('menu.manage');
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    /**
     * Delete the confirmed ingredient. The guard blocks anything a recipe
     * version still references, so the failure is reported as a flash message.
     */
    public function delete(IngredientService $service): void
    {
        $this->authorizeMenu('menu.manage');
        $id = $this->deletingId;
        $context = $this->menuContext();

        try {
            if ($this->tab === 'semi') {
                $item = $this->semiFinished()->firstWhere('id', $id);
                if ($item instanceof SemiFinishedProduct) {
                    $service->deleteSemiFinished($context, $item->id, $item->lock_version);
                }
            } else {
                $item = $this->stockItems()->firstWhere('id', $id);
                if ($item instanceof StockItem) {
                    $service->deleteStockItem($context, $item->id, $item->lock_version);
                }
            }
            session()->flash('status', __('menu.ingredients.deleted'));
        } catch (MenuException $exception) {
            session()->flash('error', $exception->getMessage());
        }

        $this->stockCache = null;
        $this->semiCache = null;
        $this->deletingId = null;
    }

    public function render(): View
    {
        return view('menu.livewire.ingredient-manager', [
            'rows' => $this->rows(),
        ])->layout('layouts.app', ['title' => __('menu.ingredients.title')]);
    }

    private function saveStockItem(IngredientService $service): void
    {
        $payload = [
            'name' => $this->name,
            'kind' => $this->kind,
            'stock_unit' => $this->stockUnit,
            'unit_cost_amount' => MoneyFormatter::toMinorUnits($this->unitCost, $this->currency),
            'currency' => $this->currency,
            'default_waste_bps' => (int) round($this->wastePercent * 100),
            'calories_per_unit' => $this->caloriesPerUnit,
            'status' => $this->status,
        ];

        $this->editingId === null
            ? $service->createStockItem($this->menuContext(), [...$payload, 'sku' => $this->sku])
            : $service->updateStockItem($this->menuContext(), $this->editingId, $this->lockVersion, $payload);
    }

    private function saveSemiFinished(IngredientService $service): void
    {
        $payload = [
            'name' => $this->name,
            'yield_unit' => $this->yieldUnit,
            'yield_quantity' => $this->yieldQuantity,
            'calories_per_unit' => $this->caloriesPerUnit,
            'status' => $this->status,
        ];

        $this->editingId === null
            ? $service->createSemiFinished($this->menuContext(), [...$payload, 'sku' => $this->sku])
            : $service->updateSemiFinished($this->menuContext(), $this->editingId, $this->lockVersion, $payload);
    }

    /**
     * The rows for the active tab, filtered by the search term.
     *
     * @return Collection<int, StockItem|SemiFinishedProduct>
     */
    private function rows(): Collection
    {
        $rows = $this->tab === 'semi' ? $this->semiFinished() : $this->stockItems();
        $term = trim($this->search);

        if ($term === '') {
            return $rows;
        }

        return $rows->filter(fn ($row) => str_contains(mb_strtolower($row->name), mb_strtolower($term))
            || str_contains(mb_strtolower($row->sku), mb_strtolower($term)))->values();
    }

    /** @return Collection<int, StockItem> */
    private function stockItems(): Collection
    {
        return $this->stockCache ??= StockItem::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->orderBy('name')->get();
    }

    /** @return Collection<int, SemiFinishedProduct> */
    private function semiFinished(): Collection
    {
        return $this->semiCache ??= SemiFinishedProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->orderBy('name')->get();
    }

    private function fillCommon(string $id, int $version, string $sku, string $name, string $status): void
    {
        $this->editingId = $id;
        $this->lockVersion = $version;
        $this->sku = $sku;
        $this->name = $name;
        $this->status = $status;
        $this->resetErrorBag();
    }
}
