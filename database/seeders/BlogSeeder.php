<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogComment;
use Illuminate\Database\Seeder;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Admin::first();
        $adminId = $admin ? $admin->id : 1;

        $cat1 = BlogCategory::firstOrCreate(
            ['slug' => 'skincare-and-beauty'],
            [
                'name' => 'Skincare & Beauty',
                'description' => 'Tips and guides for daily skincare and beauty routines.',
                'is_active' => true,
                'order_num' => 1,
            ]
        );

        $cat2 = BlogCategory::firstOrCreate(
            ['slug' => 'fashion-and-styling'],
            [
                'name' => 'Fashion & Styling',
                'description' => 'Modern and traditional fashion trends in Bangladesh.',
                'is_active' => true,
                'order_num' => 2,
            ]
        );

        $cat3 = BlogCategory::firstOrCreate(
            ['slug' => 'lifestyle-and-wellness'],
            [
                'name' => 'Lifestyle & Wellness',
                'description' => 'Healthy lifestyle, seasonal care, and wellness tips.',
                'is_active' => true,
                'order_num' => 3,
            ]
        );

        $post1 = BlogPost::firstOrCreate(
            ['slug' => 'top-5-skincare-essentials-for-everyday-glow'],
            [
                'blog_category_id' => $cat1->id,
                'admin_id' => $adminId,
                'title' => 'Top 5 Skincare Essentials for Everyday Glow',
                'excerpt' => 'Discover the best daily skincare essentials tailored for radiant, healthy, and hydrated skin throughout the seasons.',
                'content' => '<p>Taking care of your skin doesn’t have to be complicated. With the right foundation, you can achieve a natural, radiant glow effortlessly.</p><h3>1. Gentle Hydrating Cleanser</h3><p>Start your day with a sulfate-free cleanser that removes impurities without stripping your skin’s natural barrier.</p><h3>2. Vitamin C Serum</h3><p>An antioxidant-rich Vitamin C serum helps brighten dark spots and protect against environmental pollutants.</p><h3>3. Daily Broad-Spectrum Sunscreen</h3><p>Never skip sunscreen! Even on cloudy days or indoors, UV rays can accelerate skin aging.</p><p>Explore Mohima’s curated collection of authentic skincare products to elevate your routine.</p>',
                'status' => 'published',
                'published_at' => now()->subDays(2),
                'views_count' => 142,
                'is_featured' => true,
                'allow_comments' => true,
                'meta_title' => 'Top 5 Skincare Essentials for Everyday Glow - Mohima',
                'meta_description' => 'A guide to glowing skin with everyday essentials from Mohima.',
            ]
        );

        $post2 = BlogPost::firstOrCreate(
            ['slug' => 'how-to-choose-the-perfect-traditional-outfit'],
            [
                'blog_category_id' => $cat2->id,
                'admin_id' => $adminId,
                'title' => 'How to Choose the Perfect Traditional Outfit for Festivities',
                'excerpt' => 'A stylish guide to picking the finest fabric, colors, and jewelry combinations for upcoming festival celebrations.',
                'content' => '<p>Festive occasions in Bangladesh call for elegance, heritage, and comfort. Whether choosing handloom cotton or pure silk, keeping these styling tips in mind ensures you stand out with grace.</p><h3>Color Palette Choices</h3><p>Pastel tones work wonders for daytime gatherings, while rich jewel tones like emerald green, royal blue, and maroon shine at evening parties.</p>',
                'status' => 'published',
                'published_at' => now()->subDay(),
                'views_count' => 88,
                'is_featured' => true,
                'allow_comments' => true,
                'meta_title' => 'How to Choose Traditional Outfits - Mohima',
                'meta_description' => 'Festive styling tips by Mohima.',
            ]
        );

        BlogComment::firstOrCreate(
            ['blog_post_id' => $post1->id, 'author_name' => 'Tasnim Rahman'],
            [
                'author_email' => 'tasnim@example.com',
                'content' => 'This guide is super helpful! Especially the tip about Vitamin C serum.',
                'status' => 'approved',
            ]
        );
    }
}
