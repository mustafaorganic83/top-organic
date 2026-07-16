<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Branch;
use App\Models\BranchCatalogItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxClass;
use App\Modules\Sales\Data\CatalogSnapshot;
use App\Modules\Sales\Data\ModifierSnapshot;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class CatalogService
{
    public function resolve(
        SalesContext $context,
        string $variantId,
        string $channel,
        ?DateTimeInterface $at = null,
    ): CatalogSnapshot {
        $at ??= now();
        $this->assertBranch($context);
        $catalog = BranchCatalogItem::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)
            ->where('product_variant_id', $variantId)->where('status', 'active')
            ->where('is_available', true)->first();
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)->whereKey($variantId)->where('status', 'active')->first();
        $product = $variant === null ? null : Product::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)->whereKey($variant->product_id)
            ->where('status', 'active')->where('is_sellable', true)->first();
        if ($catalog === null || $variant === null || $product === null) {
            throw new SalesException(SalesException::CATALOG_UNAVAILABLE, 409, 'The catalog variant is not active and available in this branch.');
        }

        $price = DB::table('branch_price_lists as publication')
            ->join('price_lists as list', 'list.id', '=', 'publication.price_list_id')
            ->join('price_list_items as item', function ($join) use ($variantId): void {
                $join->on('item.price_list_id', '=', 'list.id')->where('item.product_variant_id', $variantId);
            })
            ->where('publication.tenant_id', $context->tenantId)->where('publication.branch_id', $context->branchId)
            ->where('list.tenant_id', $context->tenantId)->where('item.tenant_id', $context->tenantId)
            ->where('list.status', 'published')->whereIn('list.channel', ['all', $channel])
            ->where('publication.effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('list.effective_from')->orWhere('list.effective_from', '<=', $at))
            ->where(fn ($query) => $query->whereNull('publication.effective_to')->orWhere('publication.effective_to', '>', $at))
            ->where(fn ($query) => $query->whereNull('list.effective_to')->orWhere('list.effective_to', '>', $at))
            ->orderBy('publication.priority')->orderByDesc('list.revision')
            ->select(['list.id as list_id', 'list.revision', 'list.currency as list_currency', 'item.amount', 'item.currency', 'item.tax_class_id'])
            ->first();
        if ($price === null || $price->currency !== $price->list_currency) {
            throw new SalesException(SalesException::CATALOG_UNAVAILABLE, 409, 'No effective published price exists for this variant and channel.');
        }

        $taxId = $price->tax_class_id ?? $product->tax_class_id;
        $tax = $taxId === null ? null : TaxClass::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)->whereKey($taxId)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))->first();
        if ($taxId !== null && $tax === null) {
            throw new SalesException(SalesException::CATALOG_UNAVAILABLE, 409, 'The catalog tax class is not currently effective.');
        }

        return new CatalogSnapshot(
            $product->id, $variant->id, $product->name, $variant->name, $product->sku, $variant->barcode,
            (int) $price->amount, $price->currency, $tax?->id, $tax?->code,
            $tax?->rate_bps ?? 0, $tax?->is_inclusive ?? false,
            $price->list_id, (int) $price->revision, $catalog->source_revision,
        );
    }

    public function findByBarcode(
        SalesContext $context,
        string $barcode,
        string $channel,
        ?DateTimeInterface $at = null,
    ): CatalogSnapshot {
        $barcode = trim($barcode);
        if ($barcode === '') {
            throw SalesException::invalid('Barcode is required.');
        }
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)->where('barcode', $barcode)->first();
        if ($variant === null) {
            throw new SalesException(SalesException::NOT_FOUND, 404, 'No catalog variant has this barcode.');
        }

        return $this->resolve($context, $variant->id, $channel, $at);
    }

    public function resolveModifier(SalesContext $context, CatalogSnapshot $item, string $optionId): ModifierSnapshot
    {
        $option = ModifierOption::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($optionId)->where('status', 'active')->first();
        $group = $option === null ? null : ModifierGroup::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)->whereKey($option->modifier_group_id)->where('status', 'active')->first();
        $allowed = $group !== null && DB::table('product_modifier_groups')
            ->where('tenant_id', $context->tenantId)->where('product_id', $item->productId)
            ->where('modifier_group_id', $group->id)
            ->where(fn ($query) => $query->whereNull('product_variant_id')->orWhere('product_variant_id', $item->variantId))
            ->exists();
        if (! $allowed || $option->currency !== $item->currency) {
            throw new SalesException(SalesException::CATALOG_UNAVAILABLE, 409, 'The modifier is not available for this catalog variant and currency.');
        }

        return new ModifierSnapshot($group->id, $option->id, $group->name, $option->name, $option->surcharge_amount, $option->currency);
    }

    private function assertBranch(SalesContext $context): void
    {
        $exists = Branch::withoutGlobalScopes()->whereKey($context->branchId)
            ->where('tenant_id', $context->tenantId)->where('is_active', true)->exists();
        if (! $exists) {
            throw new SalesException(SalesException::SCOPE_VIOLATION, 403, 'The branch is outside the trusted tenant scope or inactive.');
        }
    }
}
