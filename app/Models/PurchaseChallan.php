<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseChallan extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'challan_no',
        'received_date',
        'total_quantity',
        'note',
        'received_by_id',
    ];

    protected $casts = [
        'received_date' => 'date',
        'total_quantity' => 'integer',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseChallanItem::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by_id');
    }
}
