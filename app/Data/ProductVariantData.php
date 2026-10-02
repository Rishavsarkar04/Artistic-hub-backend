<?php

namespace App\Data;

/**
 * One variant inside a product save. With an id it updates that variant (which must belong to the
 * product); without one it creates a new variant. Prices are normalized strings ("499.00").
 */
final readonly class ProductVariantData
{
    /**
     * @param  list<int>  $tagIds
     * @param  list<int>  $photoIds  media ids (uploaded variant photos) in display order; the first is the cover
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $sku,
        public ?string $description,
        public string $originalPrice,
        public string $sellingPrice,
        public int $stock,
        public bool $isActive,
        public array $tagIds,
        public array $photoIds,
    ) {}
}
