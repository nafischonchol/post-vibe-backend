<?php

namespace App\Http\Requests\Admin\ShippingSetting;

use App\Http\Requests\BaseFormRequest;

class UpdateShippingSettingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'inside_dhaka_charge' => 'required|numeric|min:0',
            'outside_dhaka_charge' => 'required|numeric|min:0',
            'is_free_shipping_active' => 'nullable|boolean',
            'free_shipping_threshold' => 'nullable|numeric|min:0',
            'estimated_delivery_inside_dhaka' => 'nullable|string|max:100',
            'estimated_delivery_outside_dhaka' => 'nullable|string|max:100',
        ];
    }
}
