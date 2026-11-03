<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'status',
        'timezone',
        'onboarding_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function quotaOverride(): HasOne
    {
        return $this->hasOne(TenantQuotaOverride::class);
    }

    public function publishingSettings(): HasOne
    {
        return $this->hasOne(TenantPublishingSetting::class);
    }

    public function platformAccounts(): HasMany
    {
        return $this->hasMany(TenantPlatformAccount::class);
    }

    public function videoSources(): HasMany
    {
        return $this->hasMany(VideoSource::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function publishingBatches(): HasMany
    {
        return $this->hasMany(PublishingBatch::class);
    }
}
