<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upazila extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'id',
        'district_id',
        'name',
        'bn_name',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }
}
