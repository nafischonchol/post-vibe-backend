<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingPageVideo extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'category_id',
        'video_url',
        'video_id',
        'title',
        'badge_text',
        'featured_product_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'thumbnail_url',
        'embed_url',
    ];

    protected static function booted()
    {
        static::saving(function ($video) {
            if (! empty($video->video_url) && empty($video->video_id)) {
                $video->video_id = static::extractYouTubeId($video->video_url);
            }
        });
    }

    public static function extractYouTubeId(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);

        // Pattern handles:
        // - https://www.youtube.com/watch?v=VIDEO_ID
        // - https://youtube.com/shorts/VIDEO_ID
        // - https://youtu.be/VIDEO_ID
        // - https://www.youtube.com/embed/VIDEO_ID
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        $id = $this->video_id ?: static::extractYouTubeId($this->video_url);
        return $id ? "https://img.youtube.com/vi/{$id}/hqdefault.jpg" : null;
    }

    public function getEmbedUrlAttribute(): ?string
    {
        $id = $this->video_id ?: static::extractYouTubeId($this->video_url);
        return $id ? "https://www.youtube.com/embed/{$id}?autoplay=1&rel=0" : null;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function featuredProduct()
    {
        return $this->belongsTo(Product::class, 'featured_product_id');
    }
}
