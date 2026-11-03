<?php

namespace App\Http\Requests\Admin\Order;

use App\Http\Requests\BaseFormRequest;

class UpdateOrderRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'customer_city' => ['nullable', 'string', 'max:255'],
        ];
    }
}
