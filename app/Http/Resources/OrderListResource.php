<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'date' => $this->created_at->toISOString(),
            'customer_name' => $this->client_snapshot['name'] ?? ($this->client?->name ?? 'Walk-in Customer'),
            'customer_phone' => $this->client_snapshot['phone'] ?? ($this->client?->phone ?? null),
            'customer_rating' => $this->client?->client_rating,
            'client_id' => $this->client_id,
            'status' => $this->status,
            'items_count' => $this->items->count(),
            'subtotal' => (float) $this->total_amount,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'delivery_charge' => $this->delivery_charge !== null ? (float) $this->delivery_charge : null,
            'grand_total' => (float) $this->grand_total,
            'paid_amount' => (float) $this->paid_amount,
            'due' => (float) max(0, $this->grand_total - $this->paid_amount),
            'parcel_booking_status' => $this->parcel_booking_status ?? 'none',
            'latest_courier_provider' => $this->latest_courier_provider,
            'parcels' => $this->parcels,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
