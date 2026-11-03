<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecification extends Model
{
    protected $fillable = [
        'product_id',
        'attribute_id',
        'custom_value',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }

    public function predefinedValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_specification_values', 'product_specification_id', 'attribute_value_id')
            ->withTimestamps();
    }

    /**
     * Get the specification value dynamically.
     * If predefined values exist, it returns them as a comma-separated string.
     * Otherwise, returns the custom_value text.
     */
    public function getValueAttribute()
    {
        if ($this->predefinedValues->isNotEmpty()) {
            return $this->predefinedValues->pluck('value')->implode(', ');
        }

        return $this->custom_value;
    }
}
