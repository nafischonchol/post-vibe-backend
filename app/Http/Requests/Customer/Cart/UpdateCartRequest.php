<?php

namespace App\Http\Requests\Customer\Cart;

use App\Http\Requests\BaseFormRequest;

class UpdateCartRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => 'required|integer|min:1',
        ];
    }
}
