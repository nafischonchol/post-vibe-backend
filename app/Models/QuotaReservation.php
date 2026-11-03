<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotaReservation extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'publishing_batch_id',
        'quota_date',
        'status',
        'reserved_at',
        'consumed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'quota_date' => 'date',
            'reserved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PublishingBatch::class, 'publishing_batch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotaReservationItem::class);
    }
}
