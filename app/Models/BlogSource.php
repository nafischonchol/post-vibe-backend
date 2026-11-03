<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogSource extends Model
{
    use HasFactory;

    protected $table = 'blog_sources';

    protected $fillable = [
        'name',
        'url',
        'default_category_id',
        'is_active',
        'auto_publish',
        'last_curated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_publish' => 'boolean',
        'last_curated_at' => 'datetime',
    ];

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'default_category_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BlogCuratedLog::class, 'blog_source_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
