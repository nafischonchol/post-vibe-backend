<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPostAnalytic extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'platform_post_analytics';

    protected $fillable = [
        'tenant_id',
        'publishing_schedule_id',
        'platform',
        'external_post_id',
        'views',
        'likes',
        'comments',
        'shares',
        'engagement',
        'collected_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'views' => 'integer',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'engagement' => 'decimal:4',
            'collected_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PublishingSchedule::class, 'publishing_schedule_id');
    }
}
