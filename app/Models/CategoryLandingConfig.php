<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryLandingConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'timer_enabled',
        'timer_end_time',
        'timer_title',
        'whatsapp_order_enabled',
        'whatsapp_custom_number',
        'whatsapp_custom_message',
        'recent_sales_ticker_enabled',
    ];

    protected $casts = [
        'timer_enabled' => 'boolean',
        'timer_end_time' => 'datetime',
        'whatsapp_order_enabled' => 'boolean',
        'recent_sales_ticker_enabled' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
