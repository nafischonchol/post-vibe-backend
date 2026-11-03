<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'display_name',
        'encrypted_api_key',
        'default_model',
        'configuration',
        'status',
    ];

    protected $hidden = [
        'encrypted_api_key',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_api_key' => 'encrypted',
            'configuration' => 'array',
        ];
    }
}
