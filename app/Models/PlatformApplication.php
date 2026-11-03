<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'client_id',
        'encrypted_client_secret',
        'redirect_uri',
        'configuration',
        'status',
    ];

    protected $hidden = [
        'encrypted_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_client_secret' => 'encrypted',
            'configuration' => 'array',
        ];
    }
}
