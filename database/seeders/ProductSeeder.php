<?php

namespace Database\Seeders;

use App\Enums\ProductStatusEnum;
use App\Models\Admin;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command->info('ProductSeeder is skipped because APP_ENV is not local.');

            return;
        }

        // 1. Ensure units exist
        $this->ensureUnitsExist();

        // 2. Fetch categories, brands, units, admin
        $categories = Category::all();
        $subCategories = Category::whereNotNull('parent_id')->get();
        if ($subCategories->isEmpty()) {
            $subCategories = $categories;
        }

        $brands = Brand::all();
        $units = Unit::all();
        $admin = Admin::first();
        $adminId = $admin ? $admin->id : 1;

        if ($categories->isEmpty()) {
            $this->command->error('No categories found. Please run CosmeticsSeeder first.');

            return;
        }

        // 3. Thumbnails relative paths
        $thumbnails = [
            'products/thumbnails/5UQ55Pcsr317iY45GO3mcOLxrEpVkvd4Bb2oPXU3.jpg',
            'products/thumbnails/7Lrzjl1qOVoSyrFhUAPfxWNRWUYnJo1ZKVT4Lq6P.webp',
            'products/thumbnails/K5UR3toAnN93ZWANfFO3I4PIaecjT5FjB46ftAHN.jpg',
            'products/thumbnails/jMisoYI2fEu1xkNgb4n2V1PbsZwS4YIbzsmzDYpF.jpg',
            'products/thumbnails/nKqZZaQXgM9ZYniRLBmTMK1lsDAo9ngC8q6nuLyd.jpg',
            'products/thumbnails/nZb0NYzKvZGI4F5f7ofjCYhYagi6wVHBwrSHRzmC.webp',
        ];

        // 4. Products definition list (50 products)
        $productTemplates = [
            ['name' => 'Relief Sun : Rice + Probiotics SPF50+ PA++++', 'bangla_name' => 'রিলিফ সান : রাইস + প্রোবায়োটিকস এসপিএফ৫০+'],
            ['name' => 'Advanced Snail 96 Mucin Power Essence', 'bangla_name' => 'অ্যাডভান্সড স্নেল ৯৬ মিউসিন পাওয়ার এসেন্স'],
            ['name' => 'Heartleaf 77% Soothing Toner', 'bangla_name' => 'হার্টলিফ ৭৭% সুদিং টোনার'],
            ['name' => 'Madagascar Centella Ampoule', 'bangla_name' => 'মাদাগাস্কার সেন্টেলা অ্যাম্পুল'],
            ['name' => 'Lip Sleeping Mask EX [Berry]', 'bangla_name' => 'লিপ স্লিপিং মাস্ক ইএক্স [বেরি]'],
            ['name' => 'Super Volcanic Pore Clay Mask 2X', 'bangla_name' => 'সুপার ভলক্যানিক পোর ক্লে মাস্ক ২এক্স'],
            ['name' => 'Glass Skin Refining Serum', 'bangla_name' => 'গ্লাস স্কিন রিফাইনিং সিরাম'],
            ['name' => 'AHA/BHA Clarifying Treatment Toner', 'bangla_name' => 'এএইচএ/বিএইচএ ক্লারিফাইং ট্রিটমেন্ট টোনার'],
            ['name' => 'Dynasty Cream Hydrating Moisturizer', 'bangla_name' => 'ডাইনেস্টি ক্রিম হাইড্রেটিং ময়েশ্চারাইজার'],
            ['name' => 'AHA 30% BHA 2% Peeling Solution', 'bangla_name' => 'এএইচএ ৩০% বিএইচএ ২% পিলিং সলিউশন'],
            ['name' => 'Glow Serum : Propolis + Niacinamide', 'bangla_name' => 'গ্লো সিরাম : প্রোপোলিস + নিয়াসিনামাইড'],
            ['name' => 'Green Tea Seed Hyaluronic Serum', 'bangla_name' => 'গ্রিন টি সিড হায়ালুরোনিক সিরাম'],
            ['name' => 'BHA Blackhead Power Liquid', 'bangla_name' => 'বিএইচএ ব্ল্যাকহেড পাওয়ার লিকুইড'],
            ['name' => 'Zero Pore Pad 2.0 Exfoliating', 'bangla_name' => 'জিরো পোর প্যাড ২.০ এক্সফোলিয়েটিং'],
            ['name' => 'Centella Unscented Sun SPF50+ PA++++', 'bangla_name' => 'সেন্টেলা আনসেন্টেড সান এসপিএফ৫০+'],
            ['name' => 'Water Bank Blue Hyaluronic Cream', 'bangla_name' => 'ওয়াটার ব্যাংক ব্লু হায়ালুরোনিক ক্রিম'],
            ['name' => 'Hyalu-Cica Water-Fit Sun Serum', 'bangla_name' => 'হায়ালু-সিকা ওয়াটার-ফিট সান সিরাম'],
            ['name' => 'Deep Cleansing Oil Rice Water Bright', 'bangla_name' => 'ডিপ ক্লিনসিং অয়েল রাইস ওয়াটার ব্রাইট'],
            ['name' => 'Retinol Intense Reactivating Serum', 'bangla_name' => 'রেটিনল ইনটেনসিভ রিঅ্যাক্টিভেটিং সিরাম'],
            ['name' => 'Calming Moisture Barrier Cream', 'bangla_name' => 'কালমিং ময়েসচার ব্যারিয়ার ক্রিম'],
            ['name' => 'Juicy Lasting Tint Sparkling Finish', 'bangla_name' => 'জুসি লাস্টিং টিন্ট স্পার্কলিং ফিনিশ'],
            ['name' => 'Ink Velvet Lip Tint Cushion Soft', 'bangla_name' => 'ইঙ্ক ভেলভেট লিপ টিন্ট কুশন সফট'],
            ['name' => 'Kill Cover Liquid Founwear Cushion', 'bangla_name' => 'কিল কভার লিকুইড ফাউনওয়্যার কুশন'],
            ['name' => 'Bare Water Cushion SPF38 PA++++', 'bangla_name' => 'বেয়ার ওয়াটার কুশন এসপিএফ৩৮'],
            ['name' => 'Dewyful Water Tint High Shine', 'bangla_name' => 'ডিউইফুল ওয়াটার টিন্ট হাই শাইন'],
            ['name' => 'Better Than Eyes Shadow Palette', 'bangla_name' => 'বেটার দ্যন আইজ শ্যাডো প্যালেট'],
            ['name' => 'Glowy Gelint Gloss Smooth Texture', 'bangla_name' => 'গ্লোই জেলিন্ট গ্লস স্মুথ টেক্সচার'],
            ['name' => 'Fixing Tint Matte Long Wear', 'bangla_name' => 'ফিক্সিং টিন্ট ম্যাট লং ওয়্যার'],
            ['name' => 'Moisturizing Cream Sheet Mask Box', 'bangla_name' => 'ময়েশ্চারাইজিং ক্রিম শিট মাস্ক বক্স'],
            ['name' => 'Heartleaf Pore Control Cleansing Oil', 'bangla_name' => 'হার্টলিফ পোর কন্ট্রোল ক্লিনসিং অয়েল'],
            ['name' => 'Low pH Good Morning Gel Cleanser', 'bangla_name' => 'লো পিএইচ গুড মর্নিং জেল ক্লিনজার'],
            ['name' => 'Centella Cleansing Foam Deep Purifying', 'bangla_name' => 'সেন্টেলা ক্লিনসিং ফোম ডিপ পিউরিফাইং'],
            ['name' => 'Cicapair Tiger Grass Color Correcting Cream', 'bangla_name' => 'সিকাপ্যায়ার টাইগার গ্রাস কালার কারেক্টিং ক্রিম'],
            ['name' => 'Vital Hydra Solution Face Mask', 'bangla_name' => 'ভাইটাল হাইড্রা সলিউশন ফেস মাস্ক'],
            ['name' => 'First Care Activating Serum VI', 'bangla_name' => 'ফাস্ট কেয়ার অ্যাক্টিভেটিং সিরাম'],
            ['name' => 'Concentrated Ginseng Renewing Cream', 'bangla_name' => 'কনসেনট্রেটেড জিনসেং রিনিউয়িং ক্রিম'],
            ['name' => 'Dive-In Low Molecule Hyaluronic Acid Serum', 'bangla_name' => 'ডাইভ-ইন লো মলিকিউল হায়ালুরোনিক এসিড সিরাম'],
            ['name' => 'Birch Juice Moisturizing Sunscreen', 'bangla_name' => 'বার্চ জুস ময়েশ্চারাইজিং সানস্ক্রিন'],
            ['name' => 'Essence Sun Milk SPF50+ PA+++', 'bangla_name' => 'এসেন্স সান মিল্ক এসপিএফ৫০+'],
            ['name' => 'Time Revolution The First Essence 5X', 'bangla_name' => 'টাইম রিভলিউশন দ্য ফাস্ট এসেন্স ৫এক্স'],
            ['name' => '30 Days Miracle Toner AHA BHA PHA', 'bangla_name' => '৩০ ডেজ মিরাকল টোনার'],
            ['name' => '30 Days Miracle Serum Spot Treatment', 'bangla_name' => '৩০ ডেজ মিরাকল সিরাম স্পট ট্রিটমেন্ট'],
            ['name' => 'Galacto Niacin 97 Power Essence', 'bangla_name' => 'গ্যাল্যাকটো নিয়াসিন ৯৭ পাওয়ার এসেন্স'],
            ['name' => 'Supple Preparation Unscented Toner', 'bangla_name' => 'সাপল প্রিপারেশন আনসেন্টেড টোনার'],
            ['name' => 'Freshly Juiced Vitamin Drop Serum', 'bangla_name' => 'ফ্রেশলি জুসড ভিটামিন ড্রপ সিরাম'],
            ['name' => 'Green Tea Seed Cream Moisture Rich', 'bangla_name' => 'গ্রিন টি সিড ক্রিম ময়েশ্চার রিচ'],
            ['name' => 'Bija Trouble Facial Cleansing Gel', 'bangla_name' => 'বিজা ট্রাবল ফেসিয়াল ক্লিনসিং জেল'],
            ['name' => 'Jeju Volcanic Pore Cleansing Foam EX', 'bangla_name' => 'জেজু ভলক্যানিক পোর ক্লিনসিং ফোম'],
            ['name' => 'Radiance Cleansing Balm Melt Away', 'bangla_name' => 'রেডিয়েন্স ক্লিনসিং বাম মেল্ট অ্যাওয়ে'],
            ['name' => 'Matte Sun Stick : Mugwort + Camelia', 'bangla_name' => 'ম্যাট সান স্টিক : মাগওয়ার্ট + ক্যামেলিয়া'],
        ];

        // Retrieve attributes for multi-variant assignment
        $volumeAttribute = Attribute::where('name', 'Volume / Size')->with('attributeValues')->first();
        $shadeAttribute = Attribute::where('name', 'Shade / Color')->with('attributeValues')->first();

        $count = 0;

        foreach ($productTemplates as $index => $tpl) {
            $count++;
            $category = $subCategories->random();
            $brand = $brands->isNotEmpty() ? $brands->random() : null;
            $unit = $units->isNotEmpty() ? $units->random() : null;
            $thumbnail = $thumbnails[$index % count($thumbnails)];

            $productName = $tpl['name'];
            $slug = Str::slug($productName).'-'.uniqid();

            $product = Product::create([
                'name' => $productName,
                'bangla_name' => $tpl['bangla_name'],
                'slug' => $slug,
                'short_description' => 'Discover authentic Korean skincare & beauty formulation with '.$productName.'. Formulated for gentle daily use, deeply hydrating, and suitable for modern skin needs.',
                'description' => '<p>Discover authentic Korean skincare & beauty formulation with <strong>'.$productName.'</strong>. Formulated for gentle daily use, deeply hydrating, and suitable for modern skin needs.</p><p>Key Benefits:</p><ul><li>Deep Hydration & Skin Barrier Protection</li><li>Dermatologically Tested</li><li>100% Authentic K-Beauty Import</li></ul>',
                'category_id' => $category->id,
                'brand_id' => $brand ? $brand->id : null,
                'unit_id' => $unit ? $unit->id : null,
                'youtube_video_urls' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'weight' => rand(10, 50) / 100, // 0.10kg - 0.50kg
                'meta_title' => $productName.' | Buy Online',
                'meta_description' => 'Buy '.$productName.' online at best prices. Guaranteed original authentic products.',
                'meta_keywords' => ['k-beauty', 'skincare', 'authentic', Str::slug($productName)],
                'meta_image' => $thumbnail,
                'image' => $thumbnail,
                'status' => ProductStatusEnum::ACTIVE->value,
                'created_by_id' => $adminId,
                'updated_by_id' => $adminId,
            ]);

            // Determine if multi-variant or single variant
            $isMultiVariant = ($index % 3 === 0); // 1 in 3 products will have multiple variants

            if ($isMultiVariant && $volumeAttribute && $volumeAttribute->attributeValues->isNotEmpty()) {
                // Multi-variant by Volume
                $volValues = $volumeAttribute->attributeValues->take(3);
                $basePrice = rand(600, 3500);

                foreach ($volValues as $vIdx => $volVal) {
                    $variantPrice = $basePrice + ($vIdx * 300);
                    $discountPrice = rand(0, 1) ? round($variantPrice * 0.9) : null;
                    $purchasePrice = round($variantPrice * 0.65);

                    $variant = ProductVariant::create([
                        'product_id' => $product->id,
                        'sku' => 'SKU-'.strtoupper(Str::random(6)).'-'.($vIdx + 1),
                        'barcode' => (string) rand(100000000000, 999999999999),
                        'price' => $variantPrice,
                        'discount_price' => $discountPrice,
                        'purchase_price' => $purchasePrice,
                        'stock' => rand(15, 100),
                        'is_active' => true,
                    ]);

                    ProductVariantAttributeValue::create([
                        'product_variant_id' => $variant->id,
                        'attribute_id' => $volumeAttribute->id,
                        'attribute_value_id' => $volVal->id,
                    ]);
                }
            } elseif ($isMultiVariant && $shadeAttribute && $shadeAttribute->attributeValues->isNotEmpty()) {
                // Multi-variant by Shade
                $shadeValues = $shadeAttribute->attributeValues->take(3);
                $basePrice = rand(800, 2800);

                foreach ($shadeValues as $sIdx => $shadeVal) {
                    $variantPrice = $basePrice;
                    $discountPrice = rand(0, 1) ? round($variantPrice * 0.85) : null;
                    $purchasePrice = round($variantPrice * 0.6);

                    $variant = ProductVariant::create([
                        'product_id' => $product->id,
                        'sku' => 'SKU-SHADE-'.strtoupper(Str::random(5)).'-'.($sIdx + 1),
                        'barcode' => (string) rand(100000000000, 999999999999),
                        'price' => $variantPrice,
                        'discount_price' => $discountPrice,
                        'purchase_price' => $purchasePrice,
                        'stock' => rand(20, 80),
                        'is_active' => true,
                    ]);

                    ProductVariantAttributeValue::create([
                        'product_variant_id' => $variant->id,
                        'attribute_id' => $shadeAttribute->id,
                        'attribute_value_id' => $shadeVal->id,
                    ]);
                }
            } else {
                // Simple single variant
                $price = rand(450, 3200);
                $discountPrice = rand(0, 1) ? round($price * 0.88) : null;
                $purchasePrice = round($price * 0.65);

                ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => 'SKU-PROD-'.strtoupper(Str::random(8)),
                    'barcode' => (string) rand(100000000000, 999999999999),
                    'price' => $price,
                    'discount_price' => $discountPrice,
                    'purchase_price' => $purchasePrice,
                    'stock' => rand(10, 120),
                    'is_active' => true,
                ]);
            }
        }

        $this->command->info("{$count} products seeded successfully with Cloudflare R2 thumbnail images.");
    }

    /**
     * Ensure units table has initial records
     */
    private function ensureUnitsExist(): void
    {
        $unitsData = [
            ['name' => 'Piece', 'short_name' => 'Pcs'],
            ['name' => 'Bottle', 'short_name' => 'Btl'],
            ['name' => 'Tube', 'short_name' => 'Tb'],
            ['name' => 'Box', 'short_name' => 'Box'],
            ['name' => 'Pack', 'short_name' => 'Pk'],
        ];

        foreach ($unitsData as $u) {
            Unit::firstOrCreate(
                ['short_name' => $u['short_name']],
                ['name' => $u['name'], 'is_active' => true]
            );
        }
    }
}
