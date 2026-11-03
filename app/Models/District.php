<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class District extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'id',
        'division_id',
        'name',
        'bn_name',
        'lat',
        'lon',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function upazilas(): HasMany
    {
        return $this->hasMany(Upazila::class);
    }

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget('customer_districts');
        });

        static::deleted(function () {
            Cache::forget('customer_districts');
        });
    }
}
