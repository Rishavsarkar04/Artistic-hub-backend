<?php

namespace App\Data;

use App\Enums\ShopVariantSort;

/** Filters for the shop's product-variant listing. Prices are normalized strings ("800.00"). */
final readonly class ShopVariantFilters
{
    /**
     * @param  list<string>  $tagSlugs  match any of these tags
     */
    public function __construct(
        public ?string $search = null,
        public ?string $minPrice = null,
        public ?string $maxPrice = null,
        public array $tagSlugs = [],
        public ShopVariantSort $sort = ShopVariantSort::Newest,
        public int $perPage = 24,
    ) {}

    /** @return array{search: ?string, min_price: ?string, max_price: ?string, tags: list<string>, sort: string, per_page: int} */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'tags' => $this->tagSlugs,
            'sort' => $this->sort->value,
            'per_page' => $this->perPage,
        ];
    }

    /** @return array{sort: list<string>} */
    public static function options(): array
    {
        return ['sort' => array_column(ShopVariantSort::cases(), 'value')];
    }
}
