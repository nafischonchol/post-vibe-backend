<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'price_monthly',
        'price_yearly',
        'global_daily_quota',
        'team_member_limit',
        'storage_limit_mb',
        'features',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'global_daily_quota' => 'integer',
            'team_member_limit' => 'integer',
            'storage_limit_mb' => 'integer',
            'features' => 'array',
        ];
    }

    public function platformQuotas(): HasMany
    {
        return $this->hasMany(PlanPlatformQuota::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
