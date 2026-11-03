<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecificationValue extends Model
{
    protected $table = 'product_specification_values';

    protected $fillable = [
        'product_specification_id',
        'attribute_value_id',
    ];

    public function specification()
    {
        return $this->belongsTo(ProductSpecification::class, 'product_specification_id');
    }

    public function attributeValue()
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }
}
