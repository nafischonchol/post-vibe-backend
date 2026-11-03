<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSetup extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_name',
        'contact_person',
        'email',
        'phone',
        'bin_number',
        'street_address',
        'division_id',
        'district_id',
        'upazila_id',
        'facebook',
        'instagram',
        'youtube',
        'tiktok',
        'logo',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class);
    }
}
