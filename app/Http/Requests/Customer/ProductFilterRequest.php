<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseFormRequest;

class ProductFilterRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'search_text' => ['nullable', 'string', 'max:250'],
            'category_slug' => ['nullable', 'string'],
            'brand_slug' => ['nullable', 'string', 'exists:brands,slug'],
            'concern_id' => ['nullable'],
            'attribute_value_id' => ['nullable'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'is_stock' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', 'in:best_selling,new_arrival,latest,price_low_to_high,price_high_to_low'],
            'per_page' => ['nullable', 'integer', 'max:120', 'min:1'],
            'page' => ['nullable', 'integer', 'max:50', 'min:1'],
        ];
    }
}
