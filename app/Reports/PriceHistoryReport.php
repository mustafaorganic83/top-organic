<?php

namespace App\Reports;

use App\Models\PurchasePrice;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class PriceHistoryReport extends BaseReport
{
    public function key(): string { return 'price_history'; }
    public function name(): string { return 'Price History'; }

    protected function columns(): array
    { return [ 'item_id' => 'Item', 'supplier_id' => 'Supplier', 'effective_from' => 'From', 'price' => 'Price', 'currency' => 'Currency' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $q = PurchasePrice::query()->select(['item_id','supplier_id','effective_from','price','currency']);
        if ($req->itemId()) $q->where('item_id', $req->itemId());
        return $q->orderBy('effective_from', 'desc');
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->dateFrom()) $q->where('effective_from', '>=', $req->dateFrom());
        if ($req->dateTo()) $q->where('effective_from', '<=', $req->dateTo());
    }
}
