<?php

namespace App\Http\Requests\Customer\Cart;

use App\Enums\ProductStatusEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class AddToCartRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'combo_id' => [
                'nullable',
                Rule::exists('combos', 'id')->where('is_active', true),
            ],
            'product_id' => [
                'required_without:combo_id',
                'nullable',
                Rule::exists('products', 'id')->where('status', ProductStatusEnum::ACTIVE->value),
            ],
            'product_variant_id' => [
                'nullable',
                Rule::exists('product_variants', 'id')->where('is_active', true),
            ],
            'quantity' => 'required|integer|min:1',
            'visitor_id' => 'nullable|string',
        ];
    }
}
