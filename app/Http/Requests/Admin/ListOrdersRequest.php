<?php

namespace App\Http\Requests\Admin;

use App\Data\OrderListFilters;
use App\Enums\OrderSort;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Searches order number, customer name, email and phone, and the delivery city. */
            'search' => ['nullable', 'string', 'max:100'],
            /** Leave out for all statuses. */
            'status' => ['nullable', Rule::enum(OrderStatus::class)->only(OrderStatus::placed())],
            /** A customer's reference_id (from the customer list): only their orders. */
            'customer_id' => ['nullable', 'string', 'ulid'],
            /** Defaults to newest. */
            'sort' => ['nullable', Rule::enum(OrderSort::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            /** Defaults to 20. */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): OrderListFilters
    {
        $search = trim((string) $this->validated('search'));

        return new OrderListFilters(
            search: $search === '' ? null : $search,
            status: OrderStatus::tryFrom((string) $this->validated('status')),
            customerId: $this->validated('customer_id'),
            sort: OrderSort::tryFrom((string) $this->validated('sort')) ?? OrderSort::Newest,
            perPage: (int) ($this->validated('per_page') ?? 20),
        );
    }
}
