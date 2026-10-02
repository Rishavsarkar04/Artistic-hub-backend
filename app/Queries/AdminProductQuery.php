<?php

namespace App\Queries;

use App\Data\ProductListFilters;
use App\Enums\ProductSort;
use App\Enums\ProductStatusFilter;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Read-only query behind the admin product list. Every product, with its variants, photos and tags. */
final class AdminProductQuery
{
    /** @return LengthAwarePaginator<int, Product> */
    public function paginate(ProductListFilters $filters): LengthAwarePaginator
    {
        $query = Product::query()->with(['variants.photos', 'variants.tags']);

        if ($filters->search !== null) {
            $term = '%'.addcslashes($filters->search, '%_\\').'%';
            $query->where(fn (Builder $where) => $where
                ->where('name', 'like', $term)
                ->orWhere('slug', 'like', $term)
                ->orWhereHas('variants', fn (Builder $variants) => $variants->where('sku', 'like', $term)->orWhere('name', 'like', $term)));
        }

        if ($filters->status !== null) {
            $query->where('is_active', $filters->status === ProductStatusFilter::Active);
        }

        match ($filters->sort) {
            ProductSort::Newest => $query->latest('created_at')->latest('id'),
            ProductSort::Name => $query->orderBy('name')->orderBy('id'),
            ProductSort::PriceLow => $query->orderBy($this->variantAggregate('min(selling_price)'))->orderBy('id'),
            ProductSort::PriceHigh => $query->orderByDesc($this->variantAggregate('max(selling_price)'))->orderBy('id'),
            ProductSort::StockLow => $query->orderBy($this->variantAggregate('coalesce(sum(stock), 0)'))->orderBy('id'),
        };

        return $query->paginate($filters->perPage);
    }

    /**
     * A subquery over the product's variants. Written directly (not through the variants relation, which
     * orders by id) because MySQL's ONLY_FULL_GROUP_BY rejects ORDER BY inside an aggregate subquery.
     */
    private function variantAggregate(string $aggregate): Builder
    {
        return ProductVariant::query()->selectRaw($aggregate)->whereColumn('product_variants.product_id', 'products.id');
    }
}
