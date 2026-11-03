<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoAnalysisResult extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'video_id',
        'provider',
        'model',
        'analysis_version',
        'summary',
        'transcript',
        'visual_context',
        'structured_result',
        'status',
        'token_usage',
        'estimated_cost',
    ];

    protected function casts(): array
    {
        return [
            'visual_context' => 'array',
            'structured_result' => 'array',
            'token_usage' => 'integer',
            'estimated_cost' => 'decimal:4',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
