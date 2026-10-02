<?php

namespace App\Data;

use App\Enums\ProductSort;
use App\Enums\ProductStatusFilter;

/** Filters for the admin product list. */
final readonly class ProductListFilters
{
    public function __construct(
        public ?string $search = null,
        public ?ProductStatusFilter $status = null,
        public ProductSort $sort = ProductSort::Newest,
        public int $perPage = 20,
    ) {}

    /** @return array{search: ?string, status: ?string, sort: string, per_page: int} */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status?->value,
            'sort' => $this->sort->value,
            'per_page' => $this->perPage,
        ];
    }

    /** @return array{status: list<string>, sort: list<string>} */
    public static function options(): array
    {
        return [
            'status' => array_column(ProductStatusFilter::cases(), 'value'),
            'sort' => array_column(ProductSort::cases(), 'value'),
        ];
    }
}
