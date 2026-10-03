<?php

namespace App\Http\Requests\Customer;

use App\Data\CustomerOrderListFilters;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Leave out for all statuses. */
            'status' => ['nullable', Rule::enum(OrderStatus::class)->only(OrderStatus::placed())],
            'page' => ['nullable', 'integer', 'min:1'],
            /** Defaults to 10. */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toFilters(): CustomerOrderListFilters
    {
        return new CustomerOrderListFilters(
            status: OrderStatus::tryFrom((string) $this->validated('status')),
            perPage: (int) ($this->validated('per_page') ?? 10),
        );
    }
}
