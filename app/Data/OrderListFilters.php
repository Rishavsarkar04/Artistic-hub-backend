<?php

namespace App\Data;

use App\Enums\OrderSort;
use App\Enums\OrderStatus;

/** Filters for the admin order list. */
final readonly class OrderListFilters
{
    /**
     * @param  string|null  $customerId  the customer's user reference_id
     */
    public function __construct(
        public ?string $search = null,
        public ?OrderStatus $status = null,
        public ?string $customerId = null,
        public OrderSort $sort = OrderSort::Newest,
        public int $perPage = 20,
    ) {}

    /**
     * The filters as applied (defaults filled in), echoed back in the list response.
     *
     * @return array{search: ?string, status: ?string, customer_id: ?string, sort: string, per_page: int}
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status?->value,
            'customer_id' => $this->customerId,
            'sort' => $this->sort->value,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * The values each filter accepts, so the frontend can build its controls.
     *
     * @return array{status: list<string>, sort: list<string>}
     */
    public static function options(): array
    {
        return [
            'status' => array_column(OrderStatus::placed(), 'value'),
            'sort' => array_column(OrderSort::cases(), 'value'),
        ];
    }
}
