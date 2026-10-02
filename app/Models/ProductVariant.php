<?php

namespace App\Models;

use App\Enums\MediaCollection;
use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One sellable option of a product (e.g. the 8 oz size), with its own SKU, prices, stock,
 * photos and tags. Prices are decimals with two places, kept as strings ("499.00").
 * `product_id` is not fillable: variants are created through their product.
 */
#[Fillable(['name', 'sku', 'slug', 'description', 'original_price', 'selling_price', 'stock', 'is_active'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasPublicId;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'original_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The variant's photos (media in the variant_photo collection), in display order; the first is the cover.
     *
     * @return MorphMany<Media, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')
            ->where('collection', MediaCollection::VariantPhoto)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_variant_tags')->withTimestamps()->orderBy('name');
    }
}
