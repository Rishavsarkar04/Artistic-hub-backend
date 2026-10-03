<?php

namespace App\Http\Controllers\Api\Shop;

use App\Data\ShopVariantFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\ListProductVariantsRequest;
use App\Http\Resources\ShopVariantCardResource;
use App\Queries\ShopVariantQuery;
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
}
