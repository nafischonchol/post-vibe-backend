<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductLandingConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'timer_enabled',
        'timer_end_time',
        'timer_title',
        'stock_scarcity_enabled',
        'stock_scarcity_threshold',
        'stock_scarcity_text',
        'whatsapp_order_enabled',
        'whatsapp_custom_number',
        'whatsapp_custom_message',
        'recent_sales_ticker_enabled',
        'delivery_timeline_inside',
        'delivery_timeline_outside',
        'how_to_use_steps',
    ];

    protected $casts = [
        'timer_enabled' => 'boolean',
        'timer_end_time' => 'datetime',
        'stock_scarcity_enabled' => 'boolean',
        'stock_scarcity_threshold' => 'integer',
        'whatsapp_order_enabled' => 'boolean',
        'recent_sales_ticker_enabled' => 'boolean',
        'how_to_use_steps' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
