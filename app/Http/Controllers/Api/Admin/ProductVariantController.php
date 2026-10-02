<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\Catalog\LastVariant;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\ProductVariantService;
use Illuminate\Http\Response;

class ProductVariantController extends Controller
{
    /**
     * Delete one variant.
     *
     * Removes it right away (outside the product form), with its photos and tag links. A product must keep at
     * least one variant (409). An id that is not this product's variant returns 404.
     *
     * @throws LastVariant
     */
    public function destroy(Product $product, string $variant, ProductVariantService $productVariantService): Response
    {
        $productVariantService->deleteVariant($product, $variant);

        return response()->noContent();
    }
}
