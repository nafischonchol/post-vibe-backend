<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpazilaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'district_id' => (int) $this->district_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
        ];
    }
}
