<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionalOutbox extends Model
{
    use HasFactory;

    protected $table = 'transactional_outbox';

    protected $fillable = [
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'status',
        'available_at',
        'published_at',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
