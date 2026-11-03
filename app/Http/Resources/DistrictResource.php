<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistrictResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'division_id' => (int) $this->division_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'lat' => $this->lat,
            'lon' => $this->lon,
        ];
    }
}
