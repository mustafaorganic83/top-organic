<?php

namespace App\Observers;

use App\Models\PurchasePrice;
use App\Services\Snapshot\SnapshotEngine;
use Illuminate\Support\Carbon;

class PurchasePriceObserver
{
    public function __construct(private SnapshotEngine $snapshots)
    {
    }

    public function saved(PurchasePrice $row): void
    {
        $asOf = $row->effective_from ? Carbon::parse($row->effective_from) : Carbon::now();
        $this->snapshots->snapshotItem((int)$row->item_id, $asOf);
        // Queue recalculation for affected recipes (only impacted ones)
        \App\Jobs\EnqueueRecalcForChangedNode::dispatch('ITEM', (int)$row->item_id)->onQueue('recalc');
    }
}
