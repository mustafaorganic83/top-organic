<?php

declare(strict_types=1);

namespace App\Modules\Menu\Livewire;

use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\SemiFinishedProduct;
use App\Models\StockItem;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Livewire\Concerns\ResolvesMenuContext;
use App\Modules\Menu\Services\RecipeCostingService;
use App\Modules\Menu\Services\RecipeService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * The BOM editor for one product variant. Component lines are held in draft
 * state and re-costed on every change through the same RecipeCostingService
 * the API uses, so the figures shown while editing match the snapshot frozen
 * at publish time exactly.
 */
class RecipeBuilder extends Component
{
    use ResolvesMenuContext;

    /** The producible this recipe belongs to. */
    public string $variantId = '';

    public ?string $recipeId = null;

    public ?string $versionId = null;

    public string $versionState = 'draft';

    public float $yieldQuantity = 1;

    public string $yieldUnit = 'portion';

    /** Production waste as a percentage; converted to bps on save. */
    public float $wastePercent = 0;

    public ?string $instructions = null;

    /**
     * Draft BOM lines, each: component_type, component_id, quantity, unit,
     * waste_percent.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    /** @var Collection<int, StockItem>|null */
    private ?Collection $stockItemCache = null;

    /** @var Collection<int, SemiFinishedProduct>|null */
    private ?Collection $semiFinishedCache = null;

    public function mount(string $variantId): void
    {
        $this->authorizeMenu('recipe.view');
        $this->variantId = $variantId;
        $this->loadLatestVersion();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'yieldQuantity' => ['required', 'numeric', 'min:0.000001'],
            'yieldUnit' => ['required', 'string', 'max:24'],
            'wastePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.component_type' => ['required', 'in:stock_item,semi_finished_product'],
            'lines.*.component_id' => ['required', 'ulid'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.000001'],
            'lines.*.unit' => ['required', 'string', 'max:24'],
            'lines.*.waste_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = [
            'component_type' => 'stock_item',
            'component_id' => '',
            'quantity' => 1,
            'unit' => '',
            'waste_percent' => 0,
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /**
     * Default a line's unit and waste from the chosen component, mirroring how
     * the ingredient master is set up so the user rarely has to retype them.
     */
    public function updatedLines(mixed $value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);
        if ($field !== 'component_id' && $field !== 'component_type') {
            return;
        }

        $line = $this->lines[(int) $index] ?? null;
        if ($line === null || ($line['component_id'] ?? '') === '') {
            return;
        }

        if ($line['component_type'] === 'stock_item') {
            $item = $this->stockItems()->firstWhere('id', $line['component_id']);
            if ($item instanceof StockItem) {
                $this->lines[(int) $index]['unit'] = $item->stock_unit;
                $this->lines[(int) $index]['waste_percent'] = $item->default_waste_bps / 100;
            }

            return;
        }

        $semi = $this->semiFinished()->firstWhere('id', $line['component_id']);
        if ($semi instanceof SemiFinishedProduct) {
            $this->lines[(int) $index]['unit'] = $semi->yield_unit;
        }
    }

    /** Persist the draft as a new immutable recipe version. */
    public function save(RecipeService $service): void
    {
        $this->authorizeMenu('recipe.manage');
        $this->validate();

        try {
            $recipeId = $this->recipeId ?? $this->createRecipe($service)->id;
            $version = $service->draftVersion($this->menuContext(), $recipeId, [
                'yield_quantity' => $this->yieldQuantity,
                'yield_unit' => $this->yieldUnit,
                'waste_bps' => $this->toBps($this->wastePercent),
                'instructions' => $this->instructions,
                'components' => $this->componentPayload(),
            ]);
            $this->recipeId = $recipeId;
            $this->versionId = $version->id;
            $this->versionState = $version->state;
        } catch (MenuException $exception) {
            $this->addError('lines', $exception->getMessage());

            return;
        }

        session()->flash('status', __('menu.recipe.saved'));
    }

    public function publish(RecipeService $service): void
    {
        $this->authorizeMenu('recipe.publish');
        $this->runLifecycle(
            fn () => $service->publishVersion($this->menuContext(), (string) $this->recipeId, (string) $this->versionId),
            __('menu.recipe.published_ok'),
        );
    }

    public function activate(RecipeService $service): void
    {
        $this->authorizeMenu('recipe.publish');
        $this->runLifecycle(
            fn () => $service->activateVersion($this->menuContext(), (string) $this->recipeId, (string) $this->versionId),
            __('menu.recipe.activated_ok'),
        );
    }

    public function render(RecipeCostingService $costing): View
    {
        return view('menu.livewire.recipe-builder', [
            'costed' => $this->preview($costing),
            'stockItems' => $this->stockItems(),
            'semiItems' => $this->semiFinished(),
        ]);
    }

    /**
     * Live cost preview for the current draft. Invalid or empty lines are
     * skipped so the panel keeps rendering while the user is mid-edit; the
     * priced lines are re-keyed by draft row so the table can look each one up
     * despite the gaps.
     *
     * @return array{lines: array<int, array<string, mixed>>, ingredient_cost: int, recipe_cost: int, currency: ?string}
     */
    private function preview(RecipeCostingService $costing): array
    {
        $components = $this->componentPayload();
        if ($components === []) {
            return ['lines' => [], 'ingredient_cost' => 0, 'recipe_cost' => 0, 'currency' => null];
        }

        $costed = $costing->cost(
            $this->menuContext(),
            $components,
            $this->yieldQuantity > 0 ? $this->yieldQuantity : 1.0,
            $this->toBps($this->wastePercent),
        );

        $keyed = [];
        foreach ($costed['lines'] as $line) {
            $keyed[(int) $line['sort_order']] = $line;
        }
        $costed['lines'] = $keyed;

        return $costed;
    }

    /**
     * The draft lines in the shape the costing service expects, dropping any
     * line the user has not finished filling in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function componentPayload(): array
    {
        $payload = [];
        foreach (array_values($this->lines) as $index => $line) {
            if (($line['component_id'] ?? '') === '' || (float) ($line['quantity'] ?? 0) <= 0) {
                continue;
            }
            $payload[] = [
                'component_type' => $line['component_type'],
                'component_id' => $line['component_id'],
                'quantity' => (float) $line['quantity'],
                'unit' => $line['unit'] ?: 'unit',
                'waste_bps' => $this->toBps((float) ($line['waste_percent'] ?? 0)),
                'sort_order' => $index,
            ];
        }

        return $payload;
    }

    /** Run a version lifecycle step, surfacing domain failures inline. */
    private function runLifecycle(callable $step, string $message): void
    {
        if ($this->recipeId === null || $this->versionId === null) {
            return;
        }

        try {
            $version = $step();
            $this->versionState = $version->state;
        } catch (MenuException $exception) {
            $this->addError('lines', $exception->getMessage());

            return;
        }

        session()->flash('status', $message);
    }

    private function createRecipe(RecipeService $service): Recipe
    {
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->with('product')->whereKey($this->variantId)->first()
            ?? throw MenuException::notFound('The meal size was not found.');

        return $service->create($this->menuContext(), [
            'owner_type' => 'product_variant',
            'owner_id' => $variant->id,
            'name' => trim(($variant->product?->name ?? '').' '.($variant->name ?? $variant->code)),
        ]);
    }

    /**
     * Hydrate the draft from the recipe's active version, or its latest one if
     * none is active yet.
     */
    private function loadLatestVersion(): void
    {
        $recipe = Recipe::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->where('owner_type', 'product_variant')->where('owner_id', $this->variantId)
            ->with(['activeVersion.components', 'versions'])->first();

        if ($recipe === null) {
            $this->addLine();

            return;
        }

        $this->recipeId = $recipe->id;
        $version = $recipe->activeVersion ?? $recipe->versions->sortByDesc('revision')->first();
        if (! $version instanceof RecipeVersion) {
            $this->addLine();

            return;
        }

        $version->loadMissing('components');
        $this->versionId = $version->id;
        $this->versionState = $version->state;
        $this->yieldQuantity = (float) $version->yield_quantity;
        $this->yieldUnit = $version->yield_unit;
        $this->wastePercent = $version->waste_bps / 100;
        $this->instructions = $version->instructions;
        $this->lines = $version->components->sortBy('sort_order')->map(fn ($c) => [
            'component_type' => $c->component_type,
            'component_id' => $c->component_id,
            'quantity' => (float) $c->quantity,
            'unit' => $c->unit,
            'waste_percent' => $c->waste_bps / 100,
        ])->values()->all();

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    /** @return Collection<int, StockItem> */
    private function stockItems(): Collection
    {
        return $this->stockItemCache ??= StockItem::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->where('status', 'active')->orderBy('name')->get();
    }

    /** @return Collection<int, SemiFinishedProduct> */
    private function semiFinished(): Collection
    {
        return $this->semiFinishedCache ??= SemiFinishedProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->where('status', 'active')->orderBy('name')->get();
    }

    /** Percent (as typed) to basis points (as stored). */
    private function toBps(float $percent): int
    {
        return (int) round($percent * 100);
    }
}
