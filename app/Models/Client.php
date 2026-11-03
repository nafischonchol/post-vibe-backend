<?php

namespace App\Models;

use App\Enums\ClientTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::created(function (Client $client) {
            if (! empty($client->phone)) {
                \App\Jobs\SyncClientCourierRatingJob::dispatch($client->phone);
            }
        });

        static::updated(function (Client $client) {
            if ($client->wasChanged('phone') && ! empty($client->phone)) {
                \App\Jobs\SyncClientCourierRatingJob::dispatch($client->phone);
            }
        });
    }

    protected $fillable = [
        'name',
        'username',
        'phone',
        'email',
        'password',
        'google_id',
        'facebook_id',
        'avatar',
        'gender',
        'phone_verified_at',
        'status',
        'type',
        'balance',
        'address',
        'client_rating',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'type' => ClientTypeEnum::class,
        'balance' => 'double',
        'is_active' => 'boolean',
        'phone_verified_at' => 'datetime',
        'client_rating' => 'array',
    ];

    public function details(): HasOne
    {
        return $this->hasOne(ClientDetail::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'client_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class, 'client_id');
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'client_id');
    }

    public function visitorSessions(): HasMany
    {
        return $this->hasMany(VisitorSession::class, 'client_id');
    }

    public function assignedCoupons()
    {
        return $this->belongsToMany(Coupon::class, 'coupon_clients')->withTimestamps();
    }

    public function couponUsages(): HasMany
    {
        return $this->hasMany(CouponUsage::class, 'client_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'client_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'client_id');
    }

    public function purchasePayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'client_id')->where('type', \App\Enums\PaymentTypeEnum::OUT);
    }

    public function socialIdentities(): HasMany
    {
        return $this->hasMany(ClientSocialIdentity::class, 'client_id');
    }

    public function chatConversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'client_id');
    }
}
