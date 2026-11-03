<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Video extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'source_id',
        'source_platform',
        'source_post_id',
        'source_post_url',
        'video_uid',
        'video_hash',
        'original_caption',
        'original_hashtags',
        'thumbnail_url',
        'temporary_storage_path',
        'duration',
        'file_size_bytes',
        'source_published_at',
        'processing_status',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'original_hashtags' => 'array',
            'duration' => 'integer',
            'file_size_bytes' => 'integer',
            'source_published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($video) {
            if (empty($video->video_uid)) {
                $video->video_uid = (string) Str::uuid();
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(VideoSource::class, 'source_id');
    }

    public function analysisResult(): HasOne
    {
        return $this->hasOne(VideoAnalysisResult::class)->latestOfMany();
    }

    public function platformMetadata(): HasMany
    {
        return $this->hasMany(VideoPlatformMetadata::class);
    }

    public function publishingBatches(): HasMany
    {
        return $this->hasMany(PublishingBatch::class);
    }
}
