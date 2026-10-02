<?php

namespace App\Data;

/** A whole product as the admin saves it: the product fields plus every variant it should have. */
final readonly class ProductData
{
    /**
     * @param  list<ProductVariantData>  $variants
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public bool $isActive,
        public array $variants,
    ) {}
}
