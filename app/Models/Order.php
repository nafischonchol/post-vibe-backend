<?php

namespace App\Models;

use App\Enums\OrderStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Order extends Model
{
    protected static function booted(): void
    {
        static::created(function (Order $order) {
            $phone = $order->client_snapshot['phone'] ?? $order->client?->phone ?? null;
            if (! empty($phone)) {
                \App\Jobs\SyncClientCourierRatingJob::dispatch($phone);
            }
        });

        static::saved(function (Order $order) {
            if ($order->client_id) {
                Cache::forget("client_order_stats:{$order->client_id}");
            }
            if ($order->wasChanged('client_id') && $order->getOriginal('client_id')) {
                Cache::forget("client_order_stats:{$order->getOriginal('client_id')}");
            }
        });

        static::deleted(function (Order $order) {
            if ($order->client_id) {
                Cache::forget("client_order_stats:{$order->client_id}");
            }
        });
    }

    protected $fillable = [
        'invoice_no',
        'client_snapshot',
        'client_id',
        'total_amount',
        'discount_amount',
        'tax_amount',
        'delivery_charge',
        'grand_total',
        'paid_amount',
        'change_amount',
        'status',
        'parcel_booking_status',
        'latest_courier_provider',
        'created_by_id',
        'coupon_id',
        'coupon_code',
        'landing_offer_id',
        'offer_snapshot',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'client_snapshot' => 'array',
        'offer_snapshot' => 'array',
        'status' => OrderStatusEnum::class,
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function landingOffer()
    {
        return $this->belongsTo(LandingTierOffer::class, 'landing_offer_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function couponUsage()
    {
        return $this->hasOne(CouponUsage::class, 'order_id');
    }

    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'reference');
    }

    public function allocations()
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    public function createdBy()
    {
        return $this->belongsTo(Admin::class, 'created_by_id');
    }

    public function parcels()
    {
        return $this->hasMany(OrderParcel::class)->latest('id');
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }
}
