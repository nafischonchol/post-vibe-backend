<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PublishingBatch extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'video_id',
        'idempotency_key',
        'publishing_mode',
        'batch_status',
        'analysis_enabled_snapshot',
        'metadata_configuration_snapshot',
        'intended_publish_at',
        'quota_date',
        'timezone',
        'created_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'analysis_enabled_snapshot' => 'boolean',
            'metadata_configuration_snapshot' => 'array',
            'intended_publish_at' => 'datetime',
            'quota_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(PublishingSchedule::class);
    }

    public function quotaReservation(): HasOne
    {
        return $this->hasOne(QuotaReservation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }
}
