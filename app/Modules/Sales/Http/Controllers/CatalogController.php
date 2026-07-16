<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BranchCatalogItem;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Requests\CatalogRequest;
use App\Modules\Sales\Http\Requests\IndexRequest;
use App\Modules\Sales\Services\CatalogService;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function index(IndexRequest $request, CatalogService $catalog): JsonResponse
    {
        $context = $request->salesContext();
        $page = BranchCatalogItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('status', 'active')->where('is_available', true)
            ->latest()->paginate($request->perPage());
        $items = collect($page->items())->map(function ($item) use ($catalog, $context, $request) {
            try {
                return $this->snapshot($catalog->resolve($context, $item->product_variant_id, $request->validated('channel', 'pos')));
            } catch (SalesException $exception) {
                if ($exception->errorCode !== SalesException::CATALOG_UNAVAILABLE) {
                    throw $exception;
                }

                return null;
            }
        })->filter()->values();

        return response()->json(['data' => $items, 'meta' => ['current_page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function scan(CatalogRequest $request, CatalogService $catalog): JsonResponse
    {
        $item = $catalog->findByBarcode($request->salesContext(), $request->validated('barcode'), $request->validated('channel', 'pos'));

        return response()->json(['data' => $this->snapshot($item)]);
    }

    private function snapshot(object $item): array
    {
        return ['product_id' => $item->productId, 'variant_id' => $item->variantId, 'name' => $item->productName,
            'variant_name' => $item->variantName, 'sku' => $item->sku, 'barcode' => $item->barcode,
            'unit_price_amount' => $item->unitPriceAmount, 'currency' => $item->currency,
            'tax' => ['code' => $item->taxClassCode, 'rate_bps' => $item->taxRateBps, 'inclusive' => $item->taxInclusive],
            'catalog_revision' => $item->catalogRevision, 'price_revision' => $item->priceRevision];
    }
}
