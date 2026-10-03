<?php

namespace App\Services\Catalog;

use App\Data\ProductData;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Variant photos are Media rows (variant_photo collection) attached to a ProductVariant. The product
 * save sends media ids per variant; this service attaches, reorders, moves and detaches them. It only
 * touches rows: the caller deletes the files of removed media after its transaction commits.
 */
final class VariantPhotoService
{
    public function __construct(private MediaService $mediaService) {}

    /**
     * Each photo must be unattached (just uploaded) or already one of this product's variant photos,
     * so a photo can move between variants of the product but never be taken from another product.
     */
    public function assertPhotosAreUsable(Product $product, ProductData $data): void
    {
        $ownVariantIds = $product->exists ? $product->variants()->pluck('id')->all() : [];

        foreach ($data->variants as $index => $variantData) {
            $taken = Media::query()
                ->whereKey($variantData->photoIds)
                ->whereNotNull('mediable_id')
                ->where(fn ($owner) => $owner
                    ->where('mediable_type', '!=', (new ProductVariant)->getMorphClass())
                    ->orWhereNotIn('mediable_id', $ownVariantIds))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(["variants.{$index}.photo_ids" => 'A photo belongs to another product. Upload it again.']);
            }
        }
    }

    /**
     * Makes each variant's photos exactly its photo ids, in order: attaches new uploads, moves photos
     * between this product's variants, updates the order, and deletes the rows of photos left out.
     *
     * @param  list<ProductVariant>  $variants  saved variants, in the same order as $data->variants
     * @return Collection<int, Media> deleted media (rows gone; files still to delete)
     */
    public function syncPhotos(array $variants, ProductData $data): Collection
    {
        $wanted = [];
        foreach ($variants as $index => $variant) {
            foreach ($data->variants[$index]->photoIds as $order => $mediaId) {
                $wanted[$mediaId] = ['variant' => $variant, 'sort_order' => $order];
            }
        }

        $removed = $this->photosOf(array_map(fn (ProductVariant $variant) => $variant->id, $variants))
            ->reject(fn (Media $media) => isset($wanted[$media->id]));

        $this->mediaService->deleteRows($removed);

        foreach (Media::whereKey(array_keys($wanted))->get() as $media) {
            $media->mediable()->associate($wanted[$media->id]['variant']);
            $media->sort_order = $wanted[$media->id]['sort_order'];
            $media->save();
        }

        return $removed;
    }

    /**
     * Deletes a variant photo, then its file: a photo on a saved variant (its other photos keep their
     * order) or an upload not yet saved on a variant. Any admin can delete any variant photo.
     *
     * @throws ModelNotFoundException when the id is not a variant photo
     */
    public function deletePhoto(string $mediaId): void
    {
        $photo = Media::query()
            ->where('collection', MediaCollection::VariantPhoto)
            ->where('reference_id', $mediaId)
            ->firstOrFail();

        $this->mediaService->delete($photo);
    }

    /**
     * Detaches the photos of variants about to be deleted: owner set to null, like a foreign key with
     * nullOnDelete (a polymorphic column cannot have one). The daily prune then deletes these unattached
     * rows and their files. Call inside the transaction that deletes the variants.
     *
     * @param  list<int>  $variantIds
     */
    public function detachPhotosOf(array $variantIds): void
    {
        if ($variantIds === []) {
            return;
        }

        Media::query()
            ->where('collection', MediaCollection::VariantPhoto)
            ->where('mediable_type', (new ProductVariant)->getMorphClass())
            ->whereIn('mediable_id', $variantIds)
            ->update(['mediable_type' => null, 'mediable_id' => null, 'sort_order' => 0]);
    }

    /**
     * The photo rows of the given variants.
     *
     * @param  list<int>  $variantIds
     * @return Collection<int, Media>
     */
    public function photosOf(array $variantIds): Collection
    {
        return Media::query()
            ->where('collection', MediaCollection::VariantPhoto)
            ->where('mediable_type', (new ProductVariant)->getMorphClass())
            ->whereIn('mediable_id', $variantIds)
            ->get();
    }
}
