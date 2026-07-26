<?php

declare(strict_types=1);

namespace App\Modules\Menu\Livewire;

use App\Models\Category;
use App\Models\Product;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Livewire\Concerns\ResolvesMenuContext;
use App\Modules\Menu\Services\MenuService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The dish list: search, category filter, pagination, and deletion. Editing a
 * dish and building its recipe happen in {@see DishForm}; this component only
 * lists and removes, so the two stay independently testable.
 */
class DishManager extends Component
{
    use ResolvesMenuContext, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $categoryId = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    public int $perPage = 15;

    /** The dish awaiting deletion confirmation. */
    public ?string $deletingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
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
     * Delete the confirmed dish. Referential failures surface as a flash
     * message rather than an exception page — the guard's reasons (open order,
     * invoiced history, existing recipe) are all user-correctable.
     */
    public function delete(MenuService $service): void
    {
        $this->authorizeMenu('menu.manage');
        $id = $this->deletingId;
        if ($id === null) {
            return;
        }

        $product = $this->query()->whereKey($id)->first();
        if ($product === null) {
            $this->deletingId = null;

            return;
        }

        try {
            $service->deleteProduct($this->menuContext(), $product->id, $product->lock_version);
            session()->flash('status', __('menu.dishes.deleted'));
        } catch (MenuException $exception) {
            session()->flash('error', $exception->getMessage());
        }

        $this->deletingId = null;
        $this->resetPage();
    }

    public function render(): View
    {
        return view('menu.livewire.dish-manager', [
            'dishes' => $this->dishes(),
            'categories' => $this->categories(),
        ])->layout('layouts.app', ['title' => __('menu.dishes.title')]);
    }

    /** @return LengthAwarePaginator<int, Product> */
    private function dishes(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return $this->query()
            ->with(['category', 'variants'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")))
            ->when($this->categoryId !== '', fn ($q) => $q->where('category_id', $this->categoryId))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate($this->perPage);
    }

    /** @return Collection<int, Category> */
    private function categories(): Collection
    {
        return Category::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @return Builder<Product> */
    private function query()
    {
        return Product::withoutGlobalScopes()->where('tenant_id', $this->menuContext()->tenantId);
    }
}
