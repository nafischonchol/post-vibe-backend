<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseFormRequest;

class TrackAnalyticsEventRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'event_name' => 'required|string|in:PageView,ViewContent,AddToCart,AddToWishlist,InitiateCheckout,Search,Contact',
            'event_id' => 'nullable|string|max:255',
            'event_source_url' => 'nullable|string|max:1000',
            'user_data' => 'nullable|array',
            'user_data.email' => 'nullable|string|email',
            'user_data.phone' => 'nullable|string',
            'user_data.name' => 'nullable|string',
            'user_data.city' => 'nullable|string',
            'user_data.fbp' => 'nullable|string',
            'user_data.fbc' => 'nullable|string',
            'user_data.ttclid' => 'nullable|string',
            'custom_data' => 'nullable|array',
            'custom_data.value' => 'nullable|numeric|min:0',
            'custom_data.currency' => 'nullable|string|size:3',
            'custom_data.content_name' => 'nullable|string|max:255',
            'custom_data.content_category' => 'nullable|string|max:255',
            'custom_data.content_ids' => 'nullable|array',
            'custom_data.content_type' => 'nullable|string|max:50',
            'custom_data.contents' => 'nullable|array',
            'custom_data.num_items' => 'nullable|integer|min:0',
        ];
    }
}
