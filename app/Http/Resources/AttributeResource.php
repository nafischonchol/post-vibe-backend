<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'values' => $this->when(
                $this->relationLoaded('attributeValues') || isset($this->values),
                function () {
                    if ($this->relationLoaded('attributeValues') && $this->attributeValues->count() > 0) {
                        return AttributeValueResource::collection($this->attributeValues);
                    }
                    if (isset($this->values) && is_array($this->values)) {
                        return AttributeValueResource::collection($this->values);
                    }

                    return [];
                }
            ),
            'is_active' => (bool) $this->is_active,
            'is_default_specification' => (bool) $this->is_default_specification,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
