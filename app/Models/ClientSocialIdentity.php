<?php

namespace App\Models;

use App\Enums\ChatChannelEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSocialIdentity extends Model
{
    use HasFactory;

    protected $table = 'client_social_identities';

    protected $fillable = [
        'client_id',
        'channel',
        'external_id',
        'profile_data',
    ];

    protected $casts = [
        'channel' => ChatChannelEnum::class,
        'profile_data' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
