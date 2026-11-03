<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublishingAuditLog extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'publishing_batch_id',
        'publishing_schedule_id',
        'event',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }
}
