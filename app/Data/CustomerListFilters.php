<?php

namespace App\Data;

use App\Enums\CustomerSort;

/** Filters for the admin customer list. */
final readonly class CustomerListFilters
{
    public function __construct(
        public ?string $search = null,
        public CustomerSort $sort = CustomerSort::Newest,
        public int $perPage = 20,
    ) {}

    /**
     * The filters as applied (defaults filled in), echoed back in the list response.
     *
     * @return array{search: ?string, sort: string, per_page: int}
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'sort' => $this->sort->value,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * The values each filter accepts, so the frontend can build its controls.
     *
     * @return array{sort: list<string>}
     */
    public static function options(): array
    {
        return [
            'sort' => array_column(CustomerSort::cases(), 'value'),
        ];
    }
}
