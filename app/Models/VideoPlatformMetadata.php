<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoPlatformMetadata extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'video_platform_metadata';

    protected $fillable = [
        'tenant_id',
        'video_id',
        'platform',
        'title',
        'description',
        'caption',
        'tags',
        'hashtags',
        'generation_status',
        'is_manually_edited',
        'model',
        'prompt_version',
        'token_usage',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'hashtags' => 'array',
            'is_manually_edited' => 'boolean',
            'token_usage' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
