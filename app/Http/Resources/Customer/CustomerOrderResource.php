<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'date' => $this->created_at->format('M d, Y'),
            'created_at' => $this->created_at->toISOString(),
            'status' => is_object($this->status) ? $this->status->value : (string) $this->status,
            'items_count' => (int) ($this->items_count ?? 0),
            'total_amount' => (float) $this->total_amount,
            'coupon_code' => $this->coupon_code,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'delivery_charge' => $this->delivery_charge !== null ? (float) $this->delivery_charge : null,
            'grand_total' => (float) $this->grand_total,
        ];
    }
}
