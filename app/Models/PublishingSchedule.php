<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishingSchedule extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'publishing_batch_id',
        'platform',
        'tenant_platform_account_id',
        'video_platform_metadata_id',
        'scheduled_at',
        'upload_status',
        'attempt_count',
        'next_retry_at',
        'external_post_id',
        'external_post_url',
        'last_error',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'published_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PublishingBatch::class, 'publishing_batch_id');
    }

    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(TenantPlatformAccount::class, 'tenant_platform_account_id');
    }

    public function metadata(): BelongsTo
    {
        return $this->belongsTo(VideoPlatformMetadata::class, 'video_platform_metadata_id');
    }
}
