<?php

namespace App\Livewire\Inventory;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class InventoryCostPage extends Component
{
    public array $rows = [];

    public function mount(): void
    {
        $this->rows = DB::table('stock_levels as sl')
            ->select('warehouse_id','stockable_type','stockable_id', DB::raw('quantity_on_hand as qty'), DB::raw('average_cost_amount as avg'), DB::raw('(quantity_on_hand*average_cost_amount) as val'))
            ->limit(200)->get()->map(fn($r)=>[ 'warehouse_id'=>$r->warehouse_id, 'stockable_type'=>$r->stockable_type, 'stockable_id'=>$r->stockable_id, 'qty'=>(float)$r->qty, 'avg'=>(int)$r->avg/100.0, 'val'=>(int)$r->val/100.0 ])->all();
    }

    public function render()
    {
        return view('livewire.inventory.inventory-cost-page', ['title'=>'\u062a\u0643\u0644\u0641\u0629 \u0627\u0644\u0645\u062e\u0632\u0648\u0646']);
    }
}
