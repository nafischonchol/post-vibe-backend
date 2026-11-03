<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotaReservationItem extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'quota_reservation_id',
        'tenant_id',
        'platform',
        'publishing_schedule_id',
        'status',
        'reserved_at',
        'consumed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(QuotaReservation::class, 'quota_reservation_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PublishingSchedule::class, 'publishing_schedule_id');
    }
}
