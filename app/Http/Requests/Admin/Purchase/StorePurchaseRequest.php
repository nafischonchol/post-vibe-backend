<?php

namespace App\Http\Requests\Admin\Purchase;

use App\Http\Requests\BaseFormRequest;

class StorePurchaseRequest extends BaseFormRequest
{

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'purchase_date' => 'nullable|date',
            'status' => 'nullable|in:pending,partial,received,completed,cancelled',
            'note' => 'nullable|string',
            'paid_amount' => 'nullable|numeric|min:0',
            'account_id' => 'required_if_accepted:paid_amount|nullable|exists:accounts,id',
            'payment_method' => 'nullable|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.received_quantity' => 'nullable|integer|min:0',
            'items.*.cost_price' => 'required|numeric|min:0',
            'items.*.selling_price' => 'nullable|numeric|min:0',
        ];
    }
}
