<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'inside_dhaka_charge',
        'outside_dhaka_charge',
        'is_free_shipping_active',
        'free_shipping_threshold',
        'estimated_delivery_inside_dhaka',
        'estimated_delivery_outside_dhaka',
    ];

    protected $casts = [
        'inside_dhaka_charge' => 'decimal:2',
        'outside_dhaka_charge' => 'decimal:2',
        'is_free_shipping_active' => 'boolean',
        'free_shipping_threshold' => 'decimal:2',
    ];
}
