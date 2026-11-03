<?php

namespace App\Http\Requests\Admin\Order;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],

            'account_id' => ['required_unless:paid_amount,0', 'nullable', 'integer', Rule::exists('accounts', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],

            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paid_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
