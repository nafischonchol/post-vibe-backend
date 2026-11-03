<?php

namespace App\Http\Requests\Admin\Purchase;

use App\Http\Requests\BaseFormRequest;

class ReceivePurchaseItemsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.purchase_item_id' => 'required|integer|exists:purchase_items,id',
            'items.*.receive_quantity' => 'required|integer|min:1',
            'received_date' => 'nullable|date',
            'challan_no' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
        ];
    }
}
