<?php

declare(strict_types=1);

namespace App\Modules\Menu\Livewire;

use App\Models\Category;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Livewire\Concerns\ResolvesMenuContext;
use App\Modules\Menu\Services\MenuService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Category list plus an inline create/edit panel. Categories are a shallow
 * tree, so the whole set is loaded at once and parents are offered from the
 * same collection minus the row being edited.
 */
class CategoryManager extends Component
{
    use ResolvesMenuContext;

    public ?string $editingId = null;

    public int $lockVersion = 0;

    public string $code = '';

    public string $name = '';

    public ?string $parentId = null;

    public ?string $description = null;

    public int $sortOrder = 0;

    public string $status = 'active';

    public ?string $deletingId = null;

    /** @var Collection<int, Category>|null */
    private ?Collection $categoryCache = null;

    public function mount(): void
    {
        $this->authorizeMenu('menu.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'parentId' => ['nullable', 'ulid'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sortOrder' => ['integer', 'min:0', 'max:4294967295'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function edit(string $id): void
    {
        $this->authorizeMenu('menu.manage');

        $category = $this->categories()->firstWhere('id', $id);
        if (! $category instanceof Category) {
            return;
        }

        $this->editingId = $category->id;
        $this->lockVersion = $category->lock_version;
        $this->code = $category->code;
        $this->name = $category->name;
        $this->parentId = $category->parent_id;
        $this->description = $category->description;
        $this->sortOrder = (int) $category->sort_order;
        $this->status = $category->status;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'lockVersion', 'code', 'name', 'parentId', 'description', 'sortOrder', 'status']);
        $this->status = 'active';
        $this->resetErrorBag();
    }

    public function save(MenuService $service): void
    {
        $this->authorizeMenu('menu.manage');
        $this->validate();

        $payload = [
            'parent_id' => $this->parentId ?: null,
            'name' => $this->name,
            'description' => $this->description,
            'sort_order' => $this->sortOrder,
            'status' => $this->status,
        ];

        try {
            if ($this->editingId === null) {
                $service->createCategory($this->menuContext(), [...$payload, 'code' => $this->code]);
            } else {
                $service->updateCategory($this->menuContext(), $this->editingId, $this->lockVersion, $payload);
            }
        } catch (MenuException $exception) {
            $this->addError('code', $exception->getMessage());

            return;
        }

        session()->flash('status', __('menu.categories.saved'));
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
     * Delete the confirmed category. The guard rejects categories that still
     * hold dishes or child categories, and that reason is shown as a flash.
     */
    public function delete(MenuService $service): void
    {
        $this->authorizeMenu('menu.manage');

        $category = $this->categories()->firstWhere('id', $this->deletingId);
        if (! $category instanceof Category) {
            $this->deletingId = null;

            return;
        }

        try {
            $service->deleteCategory($this->menuContext(), $category->id, $category->lock_version);
            session()->flash('status', __('menu.categories.deleted'));
        } catch (MenuException $exception) {
            session()->flash('error', $exception->getMessage());
        }

        $this->deletingId = null;
    }

    public function render(): View
    {
        return view('menu.livewire.category-manager', [
            'categories' => $this->categories(),
        ])->layout('layouts.app', ['title' => __('menu.categories.title')]);
    }

    /**
     * The tenant's categories with their dish counts. Cached per request so
     * the list, the parent picker, and the action handlers share one query.
     *
     * @return Collection<int, Category>
     */
    private function categories(): Collection
    {
        return $this->categoryCache ??= Category::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->withCount('products')
            ->orderBy('sort_order')->orderBy('name')->get();
    }
}
