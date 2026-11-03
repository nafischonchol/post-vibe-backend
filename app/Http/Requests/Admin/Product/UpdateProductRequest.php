<?php

namespace App\Http\Requests\Admin\Product;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends BaseFormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        if ($this->has('specifications') && is_string($this->specifications)) {
            $this->merge([
                'specifications' => json_decode($this->specifications, true),
            ]);
        }
        if ($this->has('variant_options') && is_string($this->variant_options)) {
            $this->merge([
                'variant_options' => json_decode($this->variant_options, true),
            ]);
        }
        if ($this->has('variants')) {
            $variantsInput = is_string($this->variants) ? json_decode($this->variants, true) : $this->variants;
            if (is_array($variantsInput)) {
                $variantsInput = array_map(function ($v) {
                    $isInactive = (isset($v['status']) && $v['status'] === 'inactive') || (isset($v['is_active']) && $v['is_active'] === false);
                    if ($isInactive) {
                        if (empty($v['sku'])) {
                            $v['sku'] = 'INACTIVE-SKU-'.uniqid();
                        }
                        if (! isset($v['price']) || $v['price'] === '' || $v['price'] === null) {
                            $v['price'] = 0;
                        }
                        if (! isset($v['purchase_price']) || $v['purchase_price'] === '' || $v['purchase_price'] === null) {
                            $v['purchase_price'] = 0;
                        }
                    }

                    return $v;
                }, $variantsInput);
                $this->merge(['variants' => $variantsInput]);
            }
        }
        if ($this->has('meta_keywords') && is_string($this->meta_keywords)) {
            $this->merge([
                'meta_keywords' => json_decode($this->meta_keywords, true),
            ]);
        }
        if ($this->has('removed_gallery_images') && is_string($this->removed_gallery_images)) {
            $this->merge([
                'removed_gallery_images' => json_decode($this->removed_gallery_images, true),
            ]);
        }
        if ($this->has('removed_color_images') && is_string($this->removed_color_images)) {
            $this->merge([
                'removed_color_images' => json_decode($this->removed_color_images, true),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bangla_name' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'bangla_description' => ['nullable', 'string'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('is_active', true)],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'draft'])],

            // Images (thumbnail is optional for update)
            'thumbnail' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:2048'],
            'gallery_images' => ['nullable', 'array'],
            'gallery_images.*' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'removed_gallery_images' => ['nullable', 'array'],
            'removed_gallery_images.*' => ['integer', 'exists:product_images,id'],

            // Videos
            'youtube_urls' => ['nullable', 'array'],
            'youtube_urls.*' => ['nullable', 'string', 'max:255'],
            'authenticity_video_url' => ['nullable', 'string', 'max:500'],

            // SEO Meta data
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'meta_keywords' => ['nullable', 'array'],
            'meta_keywords.*' => ['string', 'max:255'],
            'meta_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],

            // Specifications
            'specifications' => ['nullable', 'array'],
            'specifications.*.id' => ['required_with:specifications', 'integer', Rule::exists('attributes', 'id')->where('is_active', true)],
            'specifications.*.value' => ['required_with:specifications'],

            // Variants
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'variants.*.sku' => ['required', 'string', 'max:255'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.discount_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.purchase_price' => ['required', 'numeric', 'min:0'],
            'variants.*.barcode' => ['nullable', 'string', 'max:255'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.options' => ['nullable', 'array'],
            'variants.*.options.*.attribute_id' => ['required_with:variants.*.options', 'integer', 'exists:attributes,id'],
            'variants.*.options.*.value' => ['required_with:variants.*.options', 'string', 'max:255'],

            'color_images' => ['nullable', 'array'],
            'color_images.*' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'removed_color_images' => ['nullable', 'array'],
            'removed_color_images.*' => ['string'],
        ];
    }
}
