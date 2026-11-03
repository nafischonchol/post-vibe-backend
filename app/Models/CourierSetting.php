<?php

namespace App\Models;

use App\Enums\CourierEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourierSetting extends Model
{
    use HasFactory;

    protected $table = 'courier_settings';

    protected $fillable = [
        'courier_name',
        'is_enabled',
        'is_default',
        'credentials',
    ];

    protected $casts = [
        'courier_name' => CourierEnum::class,
        'is_enabled' => 'boolean',
        'is_default' => 'boolean',
        'credentials' => 'encrypted:array',
    ];
}
