<?php

namespace App\Http\Controllers\Api\Shop;

use App\Data\ShopVariantFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\ListProductVariantsRequest;
use App\Http\Resources\ShopVariantCardResource;
use App\Http\Resources\ShopVariantDetailResource;
use App\Queries\ShopVariantQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductVariantController extends Controller
{
    /**
     * List product variants for the shop.
     *
     * One card per buyable variant (active, of an active product, in stock), paginated. Besides `data`, `links`
     * and `meta`, the response has `filters` (as applied), `filter_options`, `price_range` (lowest and highest
     * selling price of everything buyable, for the price slider) and `tag_counts` (how many buyable variants have
     * each tag, ignoring filters).
     *
     * @unauthenticated
     */
    public function index(ListProductVariantsRequest $request, ShopVariantQuery $shopVariantQuery): AnonymousResourceCollection
    {
        $filters = $request->toFilters();

        return ShopVariantCardResource::collection($shopVariantQuery->paginate($filters))->additional([
            'filters' => $filters->toArray(),
            'filter_options' => ShopVariantFilters::options(),
            'price_range' => $shopVariantQuery->priceRange(),
            'tag_counts' => $shopVariantQuery->tagCounts(),
        ]);
    }

    /**
     * View one product variant.
     *
     * Opens while the variant and its product are active, even when out of stock (`in_stock` is then false);
     * anything else is 404. Besides `data`, the response has `other_variants`: the product's other buyable
     * variants as listing cards, for the variant picker.
     *
     * @unauthenticated
     *
     * @throws ModelNotFoundException
     */
    public function show(string $variant, ShopVariantQuery $shopVariantQuery): ShopVariantDetailResource
    {
        $productVariant = $shopVariantQuery->find($variant);

        return (new ShopVariantDetailResource($productVariant))->additional([
            'other_variants' => ShopVariantCardResource::collection($shopVariantQuery->siblings($productVariant)),
        ]);
    }
}
