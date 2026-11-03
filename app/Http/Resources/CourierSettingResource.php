<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourierSettingResource extends JsonResource
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
            'courier_name' => $this->courier_name ? $this->courier_name->value : null,
            'is_enabled' => (bool) $this->is_enabled,
            'is_default' => (bool) $this->is_default,
            'credentials' => $this->credentials,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
