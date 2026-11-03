<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StoreSetupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_name' => $this->store_name,
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'phone' => $this->phone,
            'bin_number' => $this->bin_number,
            'street_address' => $this->street_address,
            'division_id' => $this->division_id ? (int) $this->division_id : null,
            'division_name' => $this->division?->name,
            'district_id' => $this->district_id ? (int) $this->district_id : null,
            'district_name' => $this->district?->name,
            'upazila_id' => $this->upazila_id ? (int) $this->upazila_id : null,
            'upazila_name' => $this->upazila?->name,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'youtube' => $this->youtube,
            'tiktok' => $this->tiktok,
            'logo' => $this->logo ? Storage::url($this->logo) : null,
        ];
    }
}
