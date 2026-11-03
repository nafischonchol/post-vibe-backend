<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Combo extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'bangla_name',
        'slug',
        'short_description',
        'description',
        'bangla_description',
        'combo_price',
        'image',
        'blur_image',
        'video_url',
        'is_active',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'combo_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'slug_url',
        'regular_price',
        'save_amount',
        'save_percentage',
        'stock',
    ];

    public function slugUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => !empty($this->slug) ? "{$this->slug}-{$this->id}" : (string) $this->id,
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComboItem::class, 'combo_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_id');
    }

    public function getRegularPriceAttribute(): float
    {
        if (! $this->relationLoaded('items')) {
            $this->load(['items.variant', 'items.product']);
        }

        $total = 0.0;
        foreach ($this->items as $item) {
            $variantPrice = $item->variant?->discount_price ?? $item->variant?->price ?? 0;
            $total += ((float) $variantPrice) * (int) $item->quantity;
        }

        return round($total, 2);
    }

    public function getSaveAmountAttribute(): float
    {
        $regular = $this->regular_price;
        $comboPrice = (float) $this->combo_price;

        return max(0.0, round($regular - $comboPrice, 2));
    }

    public function getSavePercentageAttribute(): int
    {
        $regular = $this->regular_price;
        if ($regular <= 0) {
            return 0;
        }

        $savings = $this->save_amount;

        return (int) round(($savings / $regular) * 100);
    }

    public function getStockAttribute(): int
    {
        if (! $this->relationLoaded('items')) {
            $this->load(['items.variant']);
        }

        if ($this->items->isEmpty()) {
            return 0;
        }

        $minStock = null;
        foreach ($this->items as $item) {
            $variant = $item->variant;
            if (! $variant || ! $variant->is_active) {
                return 0;
            }
            $itemStock = (int) $variant->stock;
            $reqQty = max(1, (int) $item->quantity);
            $possibleCombos = (int) floor($itemStock / $reqQty);

            if ($minStock === null || $possibleCombos < $minStock) {
                $minStock = $possibleCombos;
            }
        }

        return max(0, $minStock ?? 0);
    }

    protected static function booted(): void
    {
        static::saved(function (Combo $combo) {
            Cache::forget("combo_details_{$combo->id}");
            Cache::forget('active_combos_list');
        });

        static::deleted(function (Combo $combo) {
            Cache::forget("combo_details_{$combo->id}");
            Cache::forget('active_combos_list');
        });
    }
}
