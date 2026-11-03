<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoSource extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'platform',
        'source_identifier',
        'source_url',
        'name',
        'status',
        'scan_frequency',
        'last_scanned_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'scan_frequency' => 'integer',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class, 'source_id');
    }
}
