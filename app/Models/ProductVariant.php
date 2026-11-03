<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'price',
        'discount_price',
        'purchase_price',
        'stock',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::deleting(function ($variant) {
            $variant->pivotRelation()->delete();
        });

        static::restoring(function ($variant) {
            $variant->pivotRelation()->restore();
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function pivotRelation()
    {
        return $this->hasMany(ProductVariantAttributeValue::class);
    }

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variant_attribute_values')
            ->withPivot('attribute_id')
            ->withTimestamps();
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_variant_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class, 'product_variant_id');
    }
}
