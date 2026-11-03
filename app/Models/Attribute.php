<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Attribute extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'values',
        'is_active',
        'is_default_specification',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($attribute) {
            if (empty($attribute->slug) || $attribute->isDirty('name')) {
                $attribute->slug = Str::slug($attribute->name);
            }
        });

        static::saved(function ($attribute) {
            if ($attribute->slug) {
                Cache::forget('customer_attribute_values_'.Str::slug($attribute->slug));
            }
        });

        static::deleted(function ($attribute) {
            if ($attribute->slug) {
                Cache::forget('customer_attribute_values_'.Str::slug($attribute->slug));
            }
        });
    }

    public const TYPE = [
        'RICH_TEXT' => 'rich_text',
        'TEXT' => 'text',
        'SELECT' => 'select',
        'MULTI_SELECT' => 'multi_select',
    ];

    protected $casts = [
        'values' => 'array',
        'is_active' => 'boolean',
        'is_default_specification' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function attributeValues()
    {
        return $this->hasMany(AttributeValue::class);
    }
}
