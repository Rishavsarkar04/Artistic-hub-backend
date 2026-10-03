<?php

namespace App\Http\Requests\Admin;

use App\Data\ProductData;
use App\Data\ProductVariantData;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Support\Money;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole product, sent on every save (create and update): product fields plus every variant it
 * should have. Variants with a `reference_id` are updated, without one are created, and variants not sent are
 * deleted. Photos are media ids returned by POST /admin/uploads/variant-photos, in display order.
 * Every id here is a client-facing reference id (ULID); toData() turns them into internal ids.
 */
class SaveProductRequest extends FormRequest
{
    private const PRICE = 'regex:/^\d{1,8}(\.\d{1,2})?$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Unique across products, ignoring case. */
            'name' => ['required', 'string', 'max:255', $this->uniqueName()],
            'description' => ['nullable', 'string', 'max:5000'],
            /** Turning the product off turns every variant off. */
            'is_active' => ['required', 'boolean'],

            'variants' => ['required', 'array', 'min:1', 'max:50'],
            /** The variant's reference_id; leave out for a new variant. */
            'variants.*.reference_id' => ['nullable', 'string', 'ulid', 'distinct', $this->ownVariant()],
            /** Unique within this product, ignoring case (other products may use the same name). */
            'variants.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'variants.*.sku' => ['required', 'string', 'max:64', 'distinct', 'regex:/^[A-Za-z0-9_-]+$/'],
            /** Falls back to the product description when null. */
            'variants.*.description' => ['nullable', 'string', 'max:5000'],
            /** Rupees, up to 2 decimals, e.g. "999.00". */
            'variants.*.original_price' => ['required', self::PRICE, $this->positive()],
            /** Rupees, up to 2 decimals; must not be more than original_price. */
            'variants.*.selling_price' => ['required', self::PRICE, $this->positive()],
            'variants.*.stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'variants.*.is_active' => ['required', 'boolean'],
            'variants.*.tag_ids' => ['nullable', 'array'],
            'variants.*.tag_ids.*' => ['string', 'ulid', 'distinct', Rule::exists('tags', 'reference_id')],
            /** Up to 8 media ids per variant (from POST /admin/uploads/variant-photos), in display order; the first is the cover. */
            'variants.*.photo_ids' => ['nullable', 'array', 'max:8'],
            'variants.*.photo_ids.*' => [
                'string',
                'ulid',
                'distinct',
                Rule::exists('media', 'reference_id')->where('collection', MediaCollection::VariantPhoto->value),
            ],
        ];
    }

    /** selling_price must not be more than original_price (compared exactly, not as floats). */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('variants', []) as $index => $variant) {
                $original = (string) ($variant['original_price'] ?? '');
                $selling = (string) ($variant['selling_price'] ?? '');

                if ($validator->errors()->hasAny(["variants.{$index}.original_price", "variants.{$index}.selling_price"]) || $original === '' || $selling === '') {
                    continue;
                }

                if (Money::compare($selling, $original) > 0) {
                    $validator->errors()->add("variants.{$index}.selling_price", 'The selling price cannot be more than the original price.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'variants.*.name.distinct' => 'Two variants of this product have the same name.',
            'variants.*.photo_ids.*.exists' => 'This photo was not found. Upload it again.',
        ];
    }

    /** Builds the save data, turning the reference ids sent into internal ids (one query per kind). */
    public function toData(): ProductData
    {
        $variants = array_values($this->validated('variants'));
        $variantIds = $this->internalIds(ProductVariant::class, array_filter(array_column($variants, 'reference_id')));
        $tagIds = $this->internalIds(Tag::class, array_merge(...array_map(fn (array $variant) => $variant['tag_ids'] ?? [], $variants)));
        $photoIds = $this->internalIds(Media::class, array_merge(...array_map(fn (array $variant) => $variant['photo_ids'] ?? [], $variants)));

        return new ProductData(
            name: $this->validated('name'),
            description: $this->validated('description'),
            isActive: (bool) $this->validated('is_active'),
            variants: array_map(fn (array $variant) => new ProductVariantData(
                id: isset($variant['reference_id']) ? $variantIds[$variant['reference_id']] : null,
                name: $variant['name'],
                sku: $variant['sku'],
                description: $variant['description'] ?? null,
                originalPrice: Money::normalize((string) $variant['original_price']),
                sellingPrice: Money::normalize((string) $variant['selling_price']),
                stock: (int) $variant['stock'],
                isActive: (bool) $variant['is_active'],
                tagIds: array_map(fn (string $referenceId) => $tagIds[$referenceId], $variant['tag_ids'] ?? []),
                photoIds: array_map(fn (string $referenceId) => $photoIds[$referenceId], array_values($variant['photo_ids'] ?? [])),
            ), $variants),
        );
    }

    /**
     * Maps reference ids to internal ids for one model.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $referenceIds
     * @return array<string, int>
     */
    private function internalIds(string $model, array $referenceIds): array
    {
        return $referenceIds === [] ? [] : $model::whereIn('reference_id', array_values($referenceIds))->pluck('id', 'reference_id')->all();
    }

    /** A variant id sent must be one of this product's variants (and none can be sent when creating). */
    private function ownVariant(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $product = $this->route('product');

            $belongs = is_string($value) && $product instanceof Product
                && $product->variants()->where('reference_id', $value)->exists();

            if (! $belongs) {
                $fail('This variant does not belong to the product.');
            }
        };
    }

    /** No other product may have this name, ignoring case; the product being saved keeps its own. */
    private function uniqueName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $product = $this->route('product');

            $taken = is_string($value) && Product::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])
                ->when($product instanceof Product, fn ($query) => $query->whereKeyNot($product->id))
                ->exists();

            if ($taken) {
                $fail("There's already a product called {$value}.");
            }
        };
    }

    private function positive(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_scalar($value) && preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $value) && Money::compare((string) $value, '0') <= 0) {
                $fail('The price must be more than zero.');
            }
        };
    }
}
