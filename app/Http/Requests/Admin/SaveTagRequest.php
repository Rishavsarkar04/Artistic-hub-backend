<?php

namespace App\Http\Requests\Admin;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTagRequest extends FormRequest
{
    /**
     * TagService also rejects names that differ only in case or punctuation ("Best-seller" vs
     * "Best seller"), since they would produce the same slug.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tag = $this->route('tag');

        return [
            /** Unique; the slug is generated from it. */
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tags', 'name')->ignore($tag instanceof Tag ? $tag->id : null),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => "There's already a tag called :input."];
    }
}
