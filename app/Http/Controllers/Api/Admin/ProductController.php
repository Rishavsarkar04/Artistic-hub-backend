<?php

namespace App\Http\Controllers\Api\Admin;

use App\Data\ProductListFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListProductsRequest;
use App\Http\Requests\Admin\SaveProductRequest;
use App\Http\Resources\AdminProductResource;
use App\Models\Product;
use App\Queries\AdminProductQuery;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class ProductController extends Controller
{
    public function __construct(private ProductService $productService) {}

    /**
     * List products.
     *
     * Every product with its variants, photos and tags, paginated. Also returns `filters` (as applied)
     * and `filter_options`.
     */
    public function index(ListProductsRequest $request, AdminProductQuery $adminProductQuery): AnonymousResourceCollection
    {
        $filters = $request->toFilters();

        return AdminProductResource::collection($adminProductQuery->paginate($filters))
            ->additional(['filters' => $filters->toArray(), 'filter_options' => ProductListFilters::options()]);
    }

    /** View a product with its variants, photos and tags. */
    public function show(Product $product): AdminProductResource
    {
        return new AdminProductResource($product->load(['variants.photos', 'variants.tags']));
    }

    /**
     * Create a product with its variants.
     *
     * Upload photos first with POST /admin/uploads/variant-photos and send the returned ids as variants.*.photo_ids.
     */
    public function store(SaveProductRequest $request): JsonResponse
    {
        $product = $this->productService->createProduct($request->toData());

        return (new AdminProductResource($product))->response()->setStatusCode(HttpStatus::HTTP_CREATED);
    }

    /**
     * Save a product with all its variants.
     *
     * Send the whole product: variants with a `reference_id` are updated, without one are created, and variants not
     * sent are deleted. Turning the product off turns every variant off. Photos not sent are deleted with their files.
     */
    public function update(SaveProductRequest $request, Product $product): AdminProductResource
    {
        return new AdminProductResource($this->productService->updateProduct($product, $request->toData()));
    }

    /**
     * Delete a product.
     *
     * Deletes its variants, photos (and their files) and tag links. Past orders keep their own copy.
     */
    public function destroy(Product $product): Response
    {
        $this->productService->deleteProduct($product);

        return response()->noContent();
    }
}
