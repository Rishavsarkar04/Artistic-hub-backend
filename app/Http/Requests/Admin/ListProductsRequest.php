<?php

namespace App\Http\Requests\Admin;

use App\Data\ProductListFilters;
use App\Enums\ProductSort;
use App\Enums\ProductStatusFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Searches product name and slug, and variant name and SKU. */
            'search' => ['nullable', 'string', 'max:100'],
            /** Leave out for all products. */
            'status' => ['nullable', Rule::enum(ProductStatusFilter::class)],
            /** newest (default), name, price_low (lowest price first), price_high (highest first), stock_low (lowest total stock first). */
            'sort' => ['nullable', Rule::enum(ProductSort::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            /** Defaults to 20. */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): ProductListFilters
    {
        $search = trim((string) $this->validated('search'));

        return new ProductListFilters(
            search: $search === '' ? null : $search,
            status: ProductStatusFilter::tryFrom((string) $this->validated('status')),
            sort: ProductSort::tryFrom((string) $this->validated('sort')) ?? ProductSort::Newest,
            perPage: (int) ($this->validated('per_page') ?? 20),
        );
    }
}
