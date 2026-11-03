<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderLookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'customer_name' => $this->client_snapshot['name'] ?? $this->client?->name ?? 'Walk-in Customer',
            'status' => $this->status?->value ?? (string) $this->status,
            'grand_total' => (float) $this->grand_total,
            'date' => $this->created_at->toISOString(),
        ];
    }
}
