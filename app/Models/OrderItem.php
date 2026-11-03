<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'combo_id',
        'product_snapshot',
        'unit_price',
        'quantity',
        'total',
        'is_gift',
    ];

    protected $casts = [
        'product_snapshot' => 'array',
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'total' => 'decimal:2',
        'is_gift' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function combo()
    {
        return $this->belongsTo(Combo::class);
    }

    public function review()
    {
        return $this->hasOne(ProductReview::class, 'order_item_id');
    }
}
