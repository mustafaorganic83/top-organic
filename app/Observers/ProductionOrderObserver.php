<?php

namespace App\Observers;

use App\Models\ProductionOrder;

class ProductionOrderObserver
{
    public function saved(ProductionOrder $row): void
    {
        if ($row->status === 'COMPLETED' && $row->prepared_recipe_id) {
            \App\Jobs\EnqueueRecalcForChangedNode::dispatch('PREPARED', (int)$row->prepared_recipe_id)->onQueue('recalc');
        }
    }
}
