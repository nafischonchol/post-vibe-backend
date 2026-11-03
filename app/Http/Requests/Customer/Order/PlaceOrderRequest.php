<?php

namespace App\Http\Requests\Customer\Order;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $clientId = $this->user()?->id ?? Auth::id();

        $addressIdRules = $clientId
            ? [
                'nullable',
                'integer',
                Rule::exists('client_addresses', 'id')->where(function ($query) use ($clientId) {
                    return $query->where('client_id', $clientId);
                }),
            ]
            : ['prohibited'];

        return [
            'address_id' => $addressIdRules,
            'name' => 'required_without:address_id|nullable|string|max:255',
            'phone' => ['required_without:address_id', 'nullable', 'string', 'digits:11', 'regex:/^01[3-9]\d{8}$/'],
            'otp' => 'nullable|string|digits:6',
            'address' => 'required_without:address_id|nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:1000',
            'cart_ids' => 'required|array|min:1',
            'cart_ids.*' => 'required|integer|exists:carts,id',
            'payment_method' => 'nullable|string|in:cod,online',
            'delivery_charge' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'coupon_code' => 'nullable|string|max:50',
            'landing_offer_id' => 'nullable|integer|exists:landing_tier_offers,id',
            'fbp' => 'nullable|string|max:255',
            'fbc' => 'nullable|string|max:255',
            'event_id' => 'nullable|string|max:255',
            'source_url' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'address_id.prohibited' => 'Guest users cannot use saved address ID. Please provide name, phone and address.',
            'phone.required_without' => 'Please enter a phone number.',
            'phone.digits' => 'Phone number must be exactly 11 digits.',
            'phone.regex' => 'Please enter a valid 11-digit mobile number (e.g., 017XXXXXXXX).',
            'cart_ids.required' => 'At least one item is required to place an order.',
            'cart_ids.min' => 'At least one item is required to place an order.',
            'cart_ids.*.exists' => 'Selected cart item is invalid.',
            'address_id.exists' => 'Selected delivery address is invalid.',
        ];
    }
}
