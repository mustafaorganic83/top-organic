<?php

declare(strict_types=1);

namespace App\Modules\Menu\Livewire;

use App\Models\Category;
use App\Modules\Menu\Livewire\Concerns\ResolvesMenuContext;
use App\Modules\Menu\Reports\ReportTable;
use App\Modules\Menu\Reports\ReportTableFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * On-screen preview of the three costing reports, with links to the PDF and
 * Excel downloads. The preview and the exports share one table factory, so
 * what is shown is exactly what is exported.
 */
class CostReports extends Component
{
    use ResolvesMenuContext;

    #[Url(as: 'report', except: 'dish_cost')]
    public string $kind = 'dish_cost';

    #[Url(as: 'category', except: '')]
    public string $categoryId = '';

    public function mount(): void
    {
        $this->authorizeMenu('menu.view');
    }

    public function render(ReportTableFactory $factory): View
    {
        return view('menu.livewire.cost-reports', [
            'table' => $this->table($factory),
            'categories' => $this->categories(),
        ])->layout('layouts.app', ['title' => __('menu.reports.title')]);
    }

    private function table(ReportTableFactory $factory): ReportTable
    {
        return $factory->make($this->menuContext(), $this->kind, $this->categoryId ?: null);
    }

    /** @return Collection<int, Category> */
    private function categories(): Collection
    {
        return Category::withoutGlobalScopes()
            ->where('tenant_id', $this->menuContext()->tenantId)
            ->orderBy('sort_order')->orderBy('name')->get();
    }
}
