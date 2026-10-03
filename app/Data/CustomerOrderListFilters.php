<?php

namespace App\Data;

use App\Enums\OrderStatus;

/** Filters for the customer's own order list. */
final readonly class CustomerOrderListFilters
{
    public function __construct(
        public ?OrderStatus $status = null,
        public int $perPage = 10,
    ) {}

    /**
     * The filters as applied (defaults filled in), echoed back in the list response.
     *
     * @return array{status: ?string, per_page: int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status?->value,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * The values each filter accepts, so the frontend can build its controls.
     *
     * @return array{status: list<string>}
     */
    public static function options(): array
    {
        return [
            'status' => array_column(OrderStatus::placed(), 'value'),
        ];
    }
}
