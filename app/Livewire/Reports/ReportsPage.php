<?php

namespace App\Livewire\Reports;

use App\Reports\DTOs\ReportRequest;
use App\Reports\ReportManager;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ReportsPage extends Component
{
    public string $report = 'inventory_cost';
    public string $format = 'json';
    public ?string $groupBy = null;
    public ?string $dateFrom = null;
    public ?string $dateTo = null;

    public array $result = ['columns'=>[],'rows'=>[],'totals'=>[],'meta'=>[]];

    public function mount(ReportManager $mgr): void
    {
        $this->run($mgr);
    }

    public function run(ReportManager $mgr): void
    {
        $req = new ReportRequest([
            'date_from'=>$this->dateFrom,
            'date_to'=>$this->dateTo,
        ], $this->groupBy, null, $this->format);
        $res = $mgr->run($this->report, $req);
        $this->result = ['columns'=>$res->columns,'rows'=>$res->rows,'totals'=>$res->totals,'meta'=>$res->meta];
    }

    public function render()
    {
        return view('livewire.reports.reports-page', ['title'=>'\u0627\u0644\u062a\u0642\u0627\u0631\u064a\u0631']);
    }
}
