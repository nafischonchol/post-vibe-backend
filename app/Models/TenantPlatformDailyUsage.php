<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantPlatformDailyUsage extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'tenant_platform_daily_usage';

    protected $fillable = [
        'tenant_id',
        'platform',
        'quota_date',
        'quota_limit',
        'reserved_count',
        'published_count',
        'failed_count',
    ];

    protected function casts(): array
    {
        return [
            'quota_date' => 'date',
            'quota_limit' => 'integer',
            'reserved_count' => 'integer',
            'published_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function availableQuota(): int
    {
        return max(0, $this->quota_limit - ($this->reserved_count + $this->published_count));
    }
}
