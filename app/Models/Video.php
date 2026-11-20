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

    protected $appends = [
        'is_downloaded',
        'playback_url',
        'file_size_formatted',
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

    public function getIsDownloadedAttribute(): bool
    {
        return ! empty($this->temporary_storage_path) || in_array($this->processing_status, ['downloaded', 'stored', 'ready', 'published']);
    }

    public function getPlaybackUrlAttribute(): ?string
    {
        if (empty($this->temporary_storage_path)) {
            return null;
        }

        return url("/api/v1/tenant/videos/{$this->id}/stream");
    }

    public function getFileSizeFormattedAttribute(): ?string
    {
        if (! $this->file_size_bytes) {
            return null;
        }

        $bytes = $this->file_size_bytes;
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return number_format($bytes / pow(1024, $power), 1) . ' ' . $units[$power];
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
