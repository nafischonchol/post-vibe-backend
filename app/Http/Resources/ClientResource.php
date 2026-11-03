<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'type' => $this->type ? $this->type->value : null,
            'balance' => (float) $this->balance,
            'address' => $this->address,
            'client_rating' => $this->client_rating,
            'is_active' => (bool) $this->is_active,
            'status' => $this->status ?? 'approved',
            'username' => $this->username,
            'details' => $this->whenLoaded('details'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'transactions' => $this->whenLoaded('transactions', fn () => $this->transactions->map(function ($t) {
                return [
                    'id' => $t->id,
                    'amount' => (float) $t->amount,
                    'type' => $t->type?->value ?? $t->type,
                    'category' => $t->category?->value ?? $t->category,
                    'account' => $t->account?->name ?? null,
                    'description' => $t->description,
                    'date' => $t->date?->toISOString() ?? $t->created_at?->toISOString(),
                    'created_by' => $t->createdBy?->name ?? null,
                ];
            })),
        ];
    }
}
