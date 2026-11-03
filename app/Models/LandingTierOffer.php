<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingTierOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity',
        'title',
        'subtitle',
        'discount_amount',
        'discount_percentage',
        'is_free_shipping',
        'gift_text',
        'gift_product_id',
        'gift_variant_id',
        'badge_text',
        'is_popular',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'is_free_shipping' => 'boolean',
        'is_popular' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function giftProduct()
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }

    public function giftVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'gift_variant_id');
    }
}
