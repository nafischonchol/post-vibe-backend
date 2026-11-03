<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderParcel extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'courier_provider',
        'tracking_code',
        'consignment_id',
        'invoice_id',
        'status',
        'raw_status',
        'delivery_charge',
        'response_payload',
        'booking_error',
    ];

    protected $casts = [
        'delivery_charge' => 'decimal:2',
        'response_payload' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
