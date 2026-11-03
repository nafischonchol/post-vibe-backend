<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'query',
        'normalized_query',
        'results_count',
        'source',
        'visitor_id',
        'client_id',
        'ip_address',
        'user_agent',
        'clicked_product_id',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'results_count' => 'integer',
            'clicked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clickedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'clicked_product_id');
    }

    /**
     * Scope for zero-result searches (Search but not found).
     */
    public function scopeZeroResults(Builder $query): Builder
    {
        return $query->where('results_count', 0);
    }

    /**
     * Scope for searches that found results.
     */
    public function scopeWithResults(Builder $query): Builder
    {
        return $query->where('results_count', '>', 0);
    }

    /**
     * Scope for recent days.
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
