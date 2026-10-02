<?php

namespace App\Services\Catalog;

use App\Data\ProductData;
use App\Data\ProductVariantData;
use App\Exceptions\Catalog\LastVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Slug;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A product's variants. syncVariants() runs inside ProductService's save transaction;
 * deleteVariant() is its own operation (removing one variant from the product list).
 */
final class ProductVariantService
{
    public function __construct(private VariantPhotoService $variantPhotoService) {}

    /** Ids sent for update must be this product's variants; SKUs must be free. */
    public function assertVariantsAreValid(Product $product, ProductData $data): void
    {
        $ownIds = $product->exists ? $product->variants()->pluck('id')->all() : [];
        $others = ProductVariant::query()->when($product->exists, fn ($query) => $query->where('product_id', '!=', $product->id));

        foreach ($data->variants as $index => $variant) {
            if ($variant->id !== null && ! in_array($variant->id, $ownIds, true)) {
                throw ValidationException::withMessages(["variants.{$index}.public_id" => 'This variant does not belong to the product.']);
            }
            if ((clone $others)->where('sku', $variant->sku)->exists()) {
                throw ValidationException::withMessages(["variants.{$index}.sku" => "The SKU {$variant->sku} is already used by another product."]);
            }
        }
    }

    /**
     * Deletes the variants left out (their photos are detached), then updates or creates the rest,
     * with their tags. Call inside the product's transaction, after the product is saved.
     *
     * @return list<ProductVariant> saved variants, in the same order as $data->variants
     */
    public function syncVariants(Product $product, ProductData $data): array
    {
        $this->deleteVariantsLeftOut($product, $data);

        $saved = [];
        $usedSlugs = [];
        foreach ($data->variants as $variantData) {
            $variant = $variantData->id ? $product->variants()->findOrFail($variantData->id) : new ProductVariant;

            // Generated from product slug + variant name when the variant is created, then kept.
            $slug = $variant->exists ? $variant->slug : $this->uniqueSlug("{$product->slug} {$variantData->name}", $usedSlugs);
            $usedSlugs[] = $slug;

            $variant->fill([
                'name' => $variantData->name,
                'sku' => $variantData->sku,
                'slug' => $slug,
                'description' => $variantData->description,
                'original_price' => $variantData->originalPrice,
                'selling_price' => $variantData->sellingPrice,
                'stock' => $variantData->stock,
                // A variant cannot be active while its product is inactive.
                'is_active' => $product->is_active && $variantData->isActive,
            ]);
            $variant->product()->associate($product);
            $variant->save();
            $variant->tags()->sync($variantData->tagIds);

            $saved[] = $variant;
        }

        return $saved;
    }

    /**
     * Deletes one variant right away. Its photos are detached (owner set to null) and removed with
     * their files by the daily prune.
     *
     * @throws ModelNotFoundException when the variant is not this product's
     * @throws LastVariant
     */
    public function deleteVariant(Product $product, string $variantId): void
    {
        DB::transaction(function () use ($product, $variantId) {
            Product::whereKey($product->getKey())->lockForUpdate()->first();

            $variant = $product->variants()->where('public_id', $variantId)->firstOrFail();

            if ($product->variants()->count() === 1) {
                throw new LastVariant;
            }

            $this->variantPhotoService->detachPhotosOf([$variant->id]);
            $variant->delete();
        });
    }

    /** Deletes the variants not sent in the save; their photos are detached first. */
    private function deleteVariantsLeftOut(Product $product, ProductData $data): void
    {
        $keptIds = array_values(array_filter(array_map(fn (ProductVariantData $variantData) => $variantData->id, $data->variants)));
        $leftOutIds = $product->variants()->whereNotIn('id', $keptIds)->pluck('id')->all();

        if ($leftOutIds === []) {
            return;
        }

        $this->variantPhotoService->detachPhotosOf($leftOutIds);
        ProductVariant::whereKey($leftOutIds)->delete();
    }

    /** @param  list<string>  $usedInThisSave */
    private function uniqueSlug(string $base, array $usedInThisSave): string
    {
        return Slug::unique($base, fn (string $slug) => in_array($slug, $usedInThisSave, true) || ProductVariant::where('slug', $slug)->exists());
    }
}
