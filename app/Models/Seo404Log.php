<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Seo404Log extends Model
{
    use HasFactory;

    protected $table = 'seo_404_logs';

    protected $fillable = [
        'path',
        'referrer',
        'hits',
        'last_accessed_at',
    ];

    protected $casts = [
        'hits' => 'integer',
        'last_accessed_at' => 'datetime',
    ];
}
