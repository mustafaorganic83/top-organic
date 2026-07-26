<?php

declare(strict_types=1);

namespace App\Modules\Menu\Livewire;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Livewire\Concerns\ResolvesMenuContext;
use App\Modules\Menu\Services\MenuService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Create or edit a single dish and its meal-size variants. The recipe itself
 * is built by {@see RecipeBuilder}, which is embedded per variant once the
 * dish exists — a variant must be persisted before it can own a recipe.
 */
class DishForm extends Component
{
    use ResolvesMenuContext;

    public ?string $productId = null;

    public int $lockVersion = 0;

    #[Validate('required|string|max:96')]
    public string $sku = '';

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:5000')]
    public ?string $description = null;

    #[Validate('nullable|ulid')]
    public ?string $categoryId = null;

    public bool $isSellable = true;

    public bool $isMeal = false;

    #[Validate('nullable|integer|min:0|max:4294967295')]
    public ?int $calories = null;

    #[Validate('integer|min:0|max:4294967295')]
    public int $sortOrder = 0;

    public string $status = 'active';

    /** Draft fields for appending a new meal-size variant. */
    public string $variantCode = '';

    public string $variantName = '';

    public string $variantMealSize = '';

    public function mount(?string $product = null): void
    {
        $this->authorizeMenu('menu.view');

        if ($product === null) {
            return;
        }

        $model = $this->findProduct($product);
        $this->productId = $model->id;
        $this->lockVersion = $model->lock_version;
        $this->sku = $model->sku;
        $this->name = $model->name;
        $this->description = $model->description;
        $this->categoryId = $model->category_id;
        $this->isSellable = (bool) $model->is_sellable;
        $this->isMeal = (bool) $model->is_meal;
        $this->calories = $model->calories;
        $this->sortOrder = (int) $model->sort_order;
        $this->status = $model->status;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'variantCode' => ['nullable', 'string', 'max:64'],
            'variantName' => ['nullable', 'string', 'max:255'],
            'variantMealSize' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function save(MenuService $service): void
    {
        $this->authorizeMenu('menu.manage');
        $this->validate();

        $payload = [
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->categoryId ?: null,
            'is_sellable' => $this->isSellable,
            'is_meal' => $this->isMeal,
            'calories' => $this->calories,
            'sort_order' => $this->sortOrder,
            'status' => $this->status,
        ];

        try {
            if ($this->productId === null) {
                $product = $service->createProduct($this->menuContext(), [...$payload, 'sku' => $this->sku]);
                $this->productId = $product->id;
                $this->lockVersion = $product->lock_version;
            } else {
                $product = $service->updateProduct($this->menuContext(), $this->productId, $this->lockVersion, $payload);
                $this->lockVersion = $product->lock_version;
            }
        } catch (MenuException $exception) {
            $this->addError('sku', $exception->getMessage());

            return;
        }

        session()->flash('status', __('menu.dishes.saved'));
        $this->redirectRoute('dishes.edit', ['product' => $this->productId], navigate: true);
    }

    public function addVariant(MenuService $service): void
    {
        $this->authorizeMenu('menu.manage');
        $this->validateOnly('variantCode');

        if ($this->productId === null || trim($this->variantCode) === '') {
            return;
        }

        try {
            $service->createVariant($this->menuContext(), $this->productId, [
                'code' => $this->variantCode,
                'name' => $this->variantName ?: null,
                'meal_size' => $this->variantMealSize ?: null,
            ]);
        } catch (MenuException $exception) {
            $this->addError('variantCode', $exception->getMessage());

            return;
        }

        $this->reset(['variantCode', 'variantName', 'variantMealSize']);
    }

    public function deleteVariant(string $variantId, MenuService $service): void
    {
        $this->authorizeMenu('menu.manage');

        $variant = $this->variants()->firstWhere('id', $variantId);
        if ($this->productId === null || ! $variant instanceof ProductVariant) {
            return;
        }

        try {
            $service->deleteVariant($this->menuContext(), $this->productId, $variant->id, $variant->lock_version);
        } catch (MenuException $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    public function render(): View
    {
        return view('menu.livewire.dish-form', [
            'categories' => $this->categories(),
            'variants' => $this->variants(),
        ])->layout('layouts.app', [
            'title' => $this->productId === null ? __('menu.dishes.create') : __('menu.dishes.edit'),
        ]);
    }

    /** @return Collection<int, ProductVariant> */
    private function variants(): Collection
    {
        if ($this->productId === null) {
            return new Collection;
        }

        return ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->where('product_id', $this->productId)
            ->with('recipe.activeVersion')
            ->orderBy('sort_order')->orderBy('code')->get();
    }

    /** @return Collection<int, Category> */
    private function categories(): Collection
    {
        return Category::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    private function findProduct(string $id): Product
    {
        return Product::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->whereKey($id)->first()
            ?? throw MenuException::notFound('The dish was not found.');
    }
}
