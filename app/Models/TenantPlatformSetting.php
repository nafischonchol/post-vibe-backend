<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantPlatformSetting extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'platform',
        'enabled',
        'daily_quota_override',
        'scheduling_settings',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_quota_override' => 'integer',
            'scheduling_settings' => 'array',
        ];
    }
}
