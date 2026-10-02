<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadVariantPhotosRequest extends FormRequest
{
    public const MAX_FILES = 8;

    /**
     * multipart/form-data with `photos[]`: 1 to 8 files, each JPG, PNG or WebP (checked by content),
     * up to 5 MB. All or nothing: if any file is invalid, none is stored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
