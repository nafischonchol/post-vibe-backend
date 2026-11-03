<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantQuotaOverride extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'global_daily_quota',
        'custom_settings',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'global_daily_quota' => 'integer',
            'custom_settings' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
