<?php

namespace App\Services\Catalog;

use App\Data\ProductData;
use App\Models\Media;
use App\Models\Product;
use App\Services\Media\MediaService;
use App\Support\Slug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a whole product (the admin form has one Save) and owns its transaction. The variants are
 * handled by ProductVariantService and their photos by VariantPhotoService; photo files no longer
 * used are deleted from the media disk after the commit.
 */
final class ProductService
{
    public function __construct(
        private ProductVariantService $productVariantService,
        private VariantPhotoService $variantPhotoService,
        private MediaService $mediaService,
    ) {}

    /** @throws ValidationException */
    public function createProduct(ProductData $data): Product
    {
        return $this->save(new Product, $data);
    }

    /** @throws ValidationException */
    public function updateProduct(Product $product, ProductData $data): Product
    {
        return $this->save($product, $data);
    }

    /**
     * Deletes the product; the database deletes its variants and tag links. The variants' photos are
     * detached (owner set to null) and removed with their files by the daily prune.
     */
    public function deleteProduct(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $this->variantPhotoService->detachPhotosOf($product->variants()->pluck('id')->all());
            $product->delete();
        });
    }

    private function save(Product $product, ProductData $data): Product
    {
        try {
            $removedPhotos = DB::transaction(function () use ($product, $data) {
                if ($product->exists) {
                    Product::whereKey($product->getKey())->lockForUpdate()->first();
                }

                $this->productVariantService->assertVariantsAreValid($product, $data);
                $this->variantPhotoService->assertPhotosAreUsable($product, $data);

                $product->fill([
                    'name' => $data->name,
                    // Generated from the name when the product is created, then kept, so shop URLs never change.
                    'slug' => $product->slug ?? $this->uniqueSlug($data->name),
                    'description' => $data->description,
                    'is_active' => $data->isActive,
                ])->save();

                $variants = $this->productVariantService->syncVariants($product, $data);

                // Photos dropped from photo_ids: rows deleted now, files after the commit.
                return $this->variantPhotoService->syncPhotos($variants, $data);
            });
        } catch (UniqueConstraintViolationException) {
            $nameTaken = Product::whereRaw('lower(name) = ?', [mb_strtolower($data->name)])
                ->when($product->exists, fn ($query) => $query->whereKeyNot($product->id))
                ->exists();

            throw ValidationException::withMessages($nameTaken
                ? ['name' => "There's already a product called {$data->name}."]
                : ['variants' => 'Two variants clash on SKU, slug or photo. Check that each is unique and try again.']);
        }

        $this->mediaService->deleteFiles($removedPhotos);

        return $product->load(['variants.photos', 'variants.tags']);
    }

    private function uniqueSlug(string $name): string
    {
        return Slug::unique($name, fn (string $slug) => Product::where('slug', $slug)->exists());
    }
}
