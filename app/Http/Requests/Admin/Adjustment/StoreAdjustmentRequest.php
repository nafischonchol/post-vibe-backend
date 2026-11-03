<?php

namespace App\Http\Requests\Admin\Adjustment;

use App\Http\Requests\BaseFormRequest;

class StoreAdjustmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'product_variant_id' => 'required|exists:product_variants,id',
            'type' => 'required|in:addition,deduction,damage',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'nullable|required_if:type,addition|numeric|min:0',
            'purchase_price' => 'nullable|required_if:type,addition|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ];
    }
}
