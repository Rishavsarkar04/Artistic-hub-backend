<?php

namespace App\Http\Requests\Admin;

use App\Data\CustomerListFilters;
use App\Enums\CustomerSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCustomersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Searches name, email and phone. */
            'search' => ['nullable', 'string', 'max:100'],
            /** Defaults to newest. */
            'sort' => ['nullable', Rule::enum(CustomerSort::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            /** Defaults to 20. */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): CustomerListFilters
    {
        $search = trim((string) $this->validated('search'));

        return new CustomerListFilters(
            search: $search === '' ? null : $search,
            sort: CustomerSort::tryFrom((string) $this->validated('sort')) ?? CustomerSort::Newest,
            perPage: (int) ($this->validated('per_page') ?? 20),
        );
    }
}
