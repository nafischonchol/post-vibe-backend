<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogCuratedLog extends Model
{
    use HasFactory;

    protected $table = 'blog_curated_logs';

    protected $fillable = [
        'blog_source_id',
        'original_url_hash',
        'original_url',
        'original_title',
        'blog_post_id',
        'status',
        'error_message',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BlogSource::class, 'blog_source_id');
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }

    /**
     * Compute and set URL hash automatically.
     */
    public static function makeHash(string $url): string
    {
        $normalized = strtolower(rtrim(trim($url), '/'));
        return hash('sha256', $normalized);
    }

    /**
     * Check if a given URL has already been logged.
     */
    public static function isUrlProcessed(string $url): bool
    {
        $hash = self::makeHash($url);
        return self::where('original_url_hash', $hash)->exists();
    }
}
