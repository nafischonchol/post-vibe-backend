<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantPublishingSetting extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'automatic_publishing_enabled',
        'publishing_interval_minutes',
        'manual_publishing_enabled',
        'timezone',
        'preferred_days',
        'preferred_time_windows',
    ];

    protected function casts(): array
    {
        return [
            'automatic_publishing_enabled' => 'boolean',
            'publishing_interval_minutes' => 'integer',
            'manual_publishing_enabled' => 'boolean',
            'preferred_days' => 'array',
            'preferred_time_windows' => 'array',
        ];
    }
}
