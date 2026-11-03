<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPlatformQuota extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'platform',
        'daily_quota',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'daily_quota' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
