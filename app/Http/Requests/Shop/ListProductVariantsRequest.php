<?php

namespace App\Http\Requests\Shop;

use App\Data\ShopVariantFilters;
use App\Enums\ShopVariantSort;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductVariantsRequest extends FormRequest
{
    private const PRICE = 'regex:/^\d{1,8}(\.\d{1,2})?$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Searches variant and product names and descriptions, and tag names. */
            'search' => ['nullable', 'string', 'max:100'],
            /** Selling price in rupees, e.g. "800" or "800.00". */
            'min_price' => ['nullable', self::PRICE],
            /** Selling price in rupees; must not be below min_price. */
            'max_price' => ['nullable', self::PRICE],
            /** Tag slugs; a variant matches if it has any of them. */
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:120', 'distinct'],
            /** newest (default), price_low, price_high. */
            'sort' => ['nullable', Rule::enum(ShopVariantSort::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            /** Defaults to 24. */
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $min = $this->input('min_price');
            $max = $this->input('max_price');

            if ($validator->errors()->hasAny(['min_price', 'max_price']) || ! is_scalar($min) || ! is_scalar($max)) {
                return;
            }

            if (Money::compare((string) $min, (string) $max) > 0) {
                $validator->errors()->add('max_price', 'The maximum price cannot be below the minimum price.');
            }
        }];
    }

    public function toFilters(): ShopVariantFilters
    {
        $search = trim((string) $this->validated('search'));
        $min = $this->validated('min_price');
        $max = $this->validated('max_price');

        return new ShopVariantFilters(
            search: $search === '' ? null : $search,
            minPrice: $min !== null ? Money::normalize((string) $min) : null,
            maxPrice: $max !== null ? Money::normalize((string) $max) : null,
            tagSlugs: array_values($this->validated('tags') ?? []),
            sort: ShopVariantSort::tryFrom((string) $this->validated('sort')) ?? ShopVariantSort::Newest,
            perPage: (int) ($this->validated('per_page') ?? 24),
        );
    }
}
