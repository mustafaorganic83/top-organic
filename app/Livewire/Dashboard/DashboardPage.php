<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\DashboardService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DashboardPage extends Component
{
    public string $interval = 'monthly';
    public ?string $dateFrom = null;
    public ?string $dateTo = null;

    public array $summary = [];
    public array $topIngredients = [];
    public array $topRecipes = [];
    public array $costTrend = [];
    public array $wasteTrend = [];
    public array $purchaseTrend = [];
    public array $productionTrend = [];

    public function mount(DashboardService $svc): void
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays(30);
        $this->dateFrom = $from->toDateString();
        $this->dateTo = $to->toDateString();
        $this->reload($svc);
    }

    public function setInterval(string $interval, DashboardService $svc): void
    {
        $this->interval = $interval;
        $this->reload($svc);
        $this->dispatch('charts:reload');
    }

    public function reload(DashboardService $svc): void
    {
        $f = ['date_from'=>$this->dateFrom,'date_to'=>$this->dateTo];
        $this->summary = $svc->summary($f);
        $this->topIngredients = $svc->topIngredients($f, 5);
        $this->topRecipes = $svc->topRecipes($f, 5);
        $this->costTrend = $svc->trend('cost', $this->interval, $f);
        $this->wasteTrend = $svc->trend('waste', $this->interval, $f);
        $this->purchaseTrend = $svc->trend('purchase', $this->interval, $f);
        $this->productionTrend = $svc->trend('production', $this->interval, $f);
    }

    public function render()
    {
        return view('livewire.dashboard.dashboard-page', ['title'=>'لوحة المعلومات']);
    }
}
