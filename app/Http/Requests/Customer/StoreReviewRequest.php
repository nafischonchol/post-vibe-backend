<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseFormRequest;

class StoreReviewRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer|exists:orders,id',
            'order_item_id' => 'required|integer|exists:order_items,id',
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'content' => 'required|string|max:3000',
        ];
    }
}
