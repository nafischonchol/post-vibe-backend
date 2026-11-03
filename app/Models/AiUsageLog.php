<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'video_id',
        'provider',
        'model',
        'operation',
        'input_tokens',
        'output_tokens',
        'estimated_cost',
        'request_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost' => 'decimal:4',
        ];
    }
}
