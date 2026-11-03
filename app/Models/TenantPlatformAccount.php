<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantPlatformAccount extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'platform',
        'external_account_id',
        'account_name',
        'account_handle',
        'avatar_url',
        'encrypted_access_token',
        'encrypted_refresh_token',
        'token_expires_at',
        'scopes',
        'status',
        'last_validated_at',
    ];

    protected $hidden = [
        'encrypted_access_token',
        'encrypted_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_access_token' => 'encrypted',
            'encrypted_refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_validated_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function isTokenExpiringSoon(int $minutes = 15): bool
    {
        if ($this->token_expires_at === null) {
            return false;
        }

        return $this->token_expires_at->subMinutes($minutes)->isPast();
    }
}
