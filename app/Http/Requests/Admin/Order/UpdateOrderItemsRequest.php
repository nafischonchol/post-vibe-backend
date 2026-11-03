<?php

namespace App\Http\Requests\Admin\Order;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderItemsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['nullable', 'integer', Rule::exists('order_items', 'id')],
            'items.*.product_variant_id' => [
                'required_without:items.*.order_item_id',
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id'),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one item is required.',
            'items.min' => 'At least one item is required.',
            'items.*.product_variant_id.required_without' => 'Please select a product variant for new items.',
            'items.*.product_variant_id.exists' => 'The selected product variant does not exist.',
            'items.*.quantity.min' => 'Item quantity must be at least 1.',
        ];
    }
}
