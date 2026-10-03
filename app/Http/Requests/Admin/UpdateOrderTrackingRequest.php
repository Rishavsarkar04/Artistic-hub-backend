<?php

namespace App\Http\Requests\Admin;

use App\Enums\TrackingProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOrderTrackingRequest extends FormRequest
{
    /** Only these can be sent; anything else is rejected, so no other order field can be changed here. */
    private const ALLOWED = ['tracking_provider', 'tracking_number'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** One of the couriers in `tracking_provider_options`, e.g. delhivery. */
            'tracking_provider' => ['required', Rule::enum(TrackingProvider::class)],
            /** Sent as a string so leading zeros are kept, e.g. "0042981277". */
            'tracking_number' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (array_diff(array_keys($this->all()), self::ALLOWED) as $field) {
                    $validator->errors()->add($field, 'Only the tracking provider and tracking number can be changed.');
                }
            },
        ];
    }

    public function provider(): TrackingProvider
    {
        return TrackingProvider::from($this->validated('tracking_provider'));
    }
}
