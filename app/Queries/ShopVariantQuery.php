<?php

namespace App\Queries;

use App\Data\ShopVariantFilters;
use App\Enums\ShopVariantSort;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries behind the shop's product-variant listing. Only variants customers can buy are
 * listed: the variant and its product are active and the variant is in stock.
 */
final class ShopVariantQuery
{
    /** @return LengthAwarePaginator<int, ProductVariant> */
    public function paginate(ShopVariantFilters $filters): LengthAwarePaginator
    {
        $query = $this->filtered($filters)->with(['product', 'photos', 'tags']);

        if ($filters->tagSlugs !== []) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->whereIn('slug', $filters->tagSlugs));
        }

        match ($filters->sort) {
            ShopVariantSort::Newest => $query->latest('product_variants.created_at')->latest('product_variants.id'),
            ShopVariantSort::PriceLow => $query->orderBy('selling_price')->orderBy('product_variants.id'),
            ShopVariantSort::PriceHigh => $query->orderByDesc('selling_price')->orderBy('product_variants.id'),
        };

        return $query->paginate($filters->perPage);
    }

    /**
     * Lowest and highest selling price of everything buyable (ignoring filters), for the price
     * slider's ends. Null when nothing is buyable.
     *
     * @return array{min: ?string, max: ?string}
     */
    public function priceRange(): array
    {
        $range = ProductVariant::query()->buyable()->selectRaw('min(selling_price) as min_price, max(selling_price) as max_price')->toBase()->first();

        return [
            'min' => $range?->min_price !== null ? Money::normalize((string) $range->min_price) : null,
            'max' => $range?->max_price !== null ? Money::normalize((string) $range->max_price) : null,
        ];
    }

    /**
     * For each tag, how many buyable variants have it (ignoring filters), so the tag list stays the
     * same whatever the shopper searches or picks. Tags with no buyable variant are left out.
     *
     * @return list<array{slug: string, name: string, count: int}>
     */
    public function tagCounts(): array
    {
        $variantIds = ProductVariant::query()->buyable()->select('product_variants.id');

        return Tag::query()
            ->join('product_variant_tags', 'product_variant_tags.tag_id', '=', 'tags.id')
            ->whereIn('product_variant_tags.product_variant_id', $variantIds)
            ->groupBy('tags.id', 'tags.slug', 'tags.name')
            ->orderBy('tags.name')
            ->get(['tags.slug', 'tags.name', DB::raw('count(*) as variant_count')])
            ->map(fn (Tag $tag) => ['slug' => $tag->slug, 'name' => $tag->name, 'count' => (int) $tag->variant_count])
            ->all();
    }

    /** Buyable variants matching the search and price filters. */
    private function filtered(ShopVariantFilters $filters): Builder
    {
        $query = ProductVariant::query()->buyable();

        if ($filters->search !== null) {
            $term = '%'.addcslashes($filters->search, '%_\\').'%';
            $query->where(fn (Builder $where) => $where
                ->where('product_variants.name', 'like', $term)
                ->orWhere('product_variants.description', 'like', $term)
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', $term)->orWhere('description', 'like', $term))
                ->orWhereHas('tags', fn (Builder $tags) => $tags->where('name', 'like', $term)));
        }

        if ($filters->minPrice !== null) {
            $query->where('selling_price', '>=', $filters->minPrice);
        }

        if ($filters->maxPrice !== null) {
            $query->where('selling_price', '<=', $filters->maxPrice);
        }

        return $query;
    }
}
