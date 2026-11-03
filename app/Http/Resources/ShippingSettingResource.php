<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inside_dhaka_charge' => (float) $this->inside_dhaka_charge,
            'outside_dhaka_charge' => (float) $this->outside_dhaka_charge,
            'is_free_shipping_active' => (bool) $this->is_free_shipping_active,
            'free_shipping_threshold' => $this->free_shipping_threshold !== null ? (float) $this->free_shipping_threshold : null,
            'estimated_delivery_inside_dhaka' => $this->estimated_delivery_inside_dhaka,
            'estimated_delivery_outside_dhaka' => $this->estimated_delivery_outside_dhaka,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
