<?php

namespace App\Http\Requests\Admin\Customer;

use App\Http\Requests\BaseFormRequest;

class StoreCustomerPaymentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:1000',
            'order_id' => 'nullable|exists:orders,id',
        ];
    }
}
