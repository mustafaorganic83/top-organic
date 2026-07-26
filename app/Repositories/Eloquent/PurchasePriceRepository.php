<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PurchasePriceRepositoryInterface;
use App\Models\PurchasePrice;
use Illuminate\Support\Carbon;

class PurchasePriceRepository extends BaseEloquentRepository implements PurchasePriceRepositoryInterface
{
    public function __construct(PurchasePrice $model)
    {
        parent::__construct($model);
    }

    public function latestForItem(int|string $itemId): ?PurchasePrice
    {
        /** @var PurchasePrice|null $row */
        $row = $this->query()->where('item_id', $itemId)
            ->orderByDesc('effective_from')->first();
        return $row;
    }

    public function effectiveAt(int|string $itemId, ?int $supplierId, $at, ?int $uomId = null): ?PurchasePrice
    {
        $at = $at instanceof \DateTimeInterface ? $at : Carbon::parse($at);
        $q = $this->query()->where('item_id', $itemId)
            ->where('effective_from', '<=', $at);
        if ($supplierId) $q->where('supplier_id', $supplierId);
        if ($uomId) $q->where('uom_id', $uomId);
        /** @var PurchasePrice|null $row */
        $row = $q->where(function ($w) use ($at) {
                $w->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
            })
            ->orderByDesc('effective_from')->first();
        return $row;
    }
}
