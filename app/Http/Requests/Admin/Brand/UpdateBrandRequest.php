<?php

namespace App\Http\Requests\Admin\Brand;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->faqs)) {
            $this->merge(['faqs' => json_decode($this->faqs, true)]);
        }
        if (is_string($this->faqs_bn)) {
            $this->merge(['faqs_bn' => json_decode($this->faqs_bn, true)]);
        }
    }

    public function rules(): array
    {
        $brandId = $this->route('brand')?->id ?? $this->route('brand');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('brands', 'slug')->ignore($brandId),
            ],
            'icon' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:1024'],
            'authenticity_video_url' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_keyword' => ['nullable', 'array'],
            'meta_keyword.*' => ['string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'meta_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'seo_bottom_content' => ['nullable', 'string'],
            'seo_bottom_content_bn' => ['nullable', 'string'],
            'faqs' => ['nullable', 'array'],
            'faqs.*.question' => ['required_with:faqs', 'string'],
            'faqs.*.answer' => ['required_with:faqs', 'string'],
            'faqs_bn' => ['nullable', 'array'],
            'faqs_bn.*.question' => ['required_with:faqs_bn', 'string'],
            'faqs_bn.*.answer' => ['required_with:faqs_bn', 'string'],
        ];
    }
}
