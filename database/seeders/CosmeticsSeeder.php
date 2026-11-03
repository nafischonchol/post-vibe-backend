<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CosmeticsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedCategories();
        $this->seedBrands();
        $this->seedAttributes();
    }

    /**
     * Seed Korean Cosmetics & Skincare Categories
     */
    private function seedCategories(): void
    {
        $categoriesData = [
            [
                'name' => 'Skincare',
                'meta_title' => 'Korean Skincare Products',
                'meta_description' => 'Explore authentic Korean skincare essentials including cleansers, toners, serums, and moisturizers.',
                'children' => [
                    ['name' => 'Cleansers & Makeup Removers'],
                    ['name' => 'Toners & Mists'],
                    ['name' => 'Essences, Serums & Ampoules'],
                    ['name' => 'Moisturizers & Creams'],
                    ['name' => 'Sun Care & Sunscreens'],
                    ['name' => 'Eye Care'],
                    ['name' => 'Sheet Masks & Sleeping Packs'],
                    ['name' => 'Exfoliators & Peels'],
                ],
            ],
            [
                'name' => 'Makeup',
                'meta_title' => 'Korean Makeup & Beauty',
                'meta_description' => 'Discover trending Korean cosmetics, cushion foundations, lip tints, and eye makeup.',
                'children' => [
                    ['name' => 'Cushions & BB Creams'],
                    ['name' => 'Lip Tints & Lipsticks'],
                    ['name' => 'Blushes & Highlighters'],
                    ['name' => 'Eyeliner & Mascara'],
                    ['name' => 'Eye Shadow Palettes'],
                    ['name' => 'Primers & Setting Powders'],
                ],
            ],
            [
                'name' => 'Hair & Body Care',
                'meta_title' => 'Hair & Body Care Products',
                'meta_description' => 'Nourishing scalp care, hair serums, and hydrating body lotions.',
                'children' => [
                    ['name' => 'Scalp & Shampoo Care'],
                    ['name' => 'Hair Serums & Oils'],
                    ['name' => 'Body Washes & Lotions'],
                    ['name' => 'Hand & Foot Care'],
                ],
            ],
        ];

        foreach ($categoriesData as $parentCat) {
            $parent = Category::updateOrCreate(
                ['slug' => Str::slug($parentCat['name'])],
                [
                    'name' => $parentCat['name'],
                    'parent_id' => null,
                    'is_active' => true,
                    'meta_title' => $parentCat['meta_title'] ?? $parentCat['name'],
                    'meta_description' => $parentCat['meta_description'] ?? null,
                ]
            );

            if (isset($parentCat['children'])) {
                foreach ($parentCat['children'] as $childCat) {
                    Category::updateOrCreate(
                        ['slug' => Str::slug($childCat['name'])],
                        [
                            'name' => $childCat['name'],
                            'parent_id' => $parent->id,
                            'is_active' => true,
                            'meta_title' => $childCat['name'],
                        ]
                    );
                }
            }
        }

        $this->command->info('Korean Cosmetics Categories seeded successfully.');
    }

    /**
     * Seed Top Korean Cosmetics & Skincare Brands
     */
    private function seedBrands(): void
    {
        $brands = [
            'COSRX',
            'Beauty of Joseon',
            'Anua',
            'Laneige',
            'Innisfree',
            'Etude House',
            'Missha',
            'Some By Mi',
            'SKIN1004',
            'Sulwhasoo',
            'Torriden',
            'rom&nd',
            'Peripera',
            'Round Lab',
            'Pyunkang Yul',
            'Mediheal',
            'Dr.Jart+',
            'Dear, Klairs',
            'Isntree',
            'AHC',
        ];

        foreach ($brands as $brandName) {
            Brand::updateOrCreate(
                ['slug' => Str::slug($brandName)],
                [
                    'name' => $brandName,
                    'is_active' => true,
                    'meta_title' => $brandName.' Official K-Beauty Products',
                    'meta_description' => 'Buy 100% authentic '.$brandName.' Korean skincare and cosmetics.',
                ]
            );
        }

        $this->command->info('Korean Cosmetics Brands seeded successfully.');
    }

    /**
     * Seed Cosmetic Attributes & Attribute Values
     */
    private function seedAttributes(): void
    {
        $attributesData = [
            [
                'name' => 'Skin Type',
                'type' => 'multi_select',
                'values' => [
                    [
                        'value' => 'All Skin Types',
                        'meta_title' => 'Universal Skincare Products for All Skin Types',
                        'meta_description' => 'Versatile, balanced skincare solutions suitable for every skin type.',
                    ],
                    [
                        'value' => 'Dry',
                        'meta_title' => 'Hydrating Skincare Products for Dry Skin',
                        'meta_description' => 'Discover deeply moisturizing Korean skincare products formulated for dry skin types.',
                    ],
                    [
                        'value' => 'Oily',
                        'meta_title' => 'Oil Control Cosmetics & Skincare for Oily Skin',
                        'meta_description' => 'Lightweight, non-comedogenic skincare products designed to regulate excess oil.',
                    ],
                    [
                        'value' => 'Combination',
                        'meta_title' => 'Balancing Skincare Products for Combination Skin',
                        'meta_description' => 'Formulations that balance oiliness in the T-zone while hydrating dry areas.',
                    ],
                    [
                        'value' => 'Sensitive',
                        'meta_title' => 'Gentle & Soothing Skincare for Sensitive Skin',
                        'meta_description' => 'Calming and irritation-free Korean cosmetics designed for sensitive skin types.',
                    ],
                    [
                        'value' => 'Acne-Prone',
                        'meta_title' => 'Acne-Prone Skin Treatments & Blemish Care',
                        'meta_description' => 'Effective anti-acne skincare products to clear pimples and prevent future breakouts.',
                    ],
                ],
            ],
            [
                'name' => 'Skin Concern',
                'type' => 'multi_select',
                'values' => [
                    [
                        'value' => 'Acne & Blemishes',
                        'meta_title' => 'Targeted Treatments for Acne & Blemishes',
                        'meta_description' => 'Dermatologist-loved acne treatments, pimple patches, and calming serums.',
                    ],
                    [
                        'value' => 'Anti-Aging & Wrinkles',
                        'meta_title' => 'Anti-Aging & Wrinkle Care Skincare Collection',
                        'meta_description' => 'Youth-restoring Korean skincare with peptides, retinol, and collagen boosters.',
                    ],
                    [
                        'value' => 'Dark Spots & Hyperpigmentation',
                        'meta_title' => 'Brightening Products for Dark Spots & Hyperpigmentation',
                        'meta_description' => 'Fade dark spots and hyperpigmentation with Niacinamide and Vitamin C serums.',
                    ],
                    [
                        'value' => 'Dryness & Dehydration',
                        'meta_title' => 'Deep Hydration & Dehydration Relief Skincare',
                        'meta_description' => 'Lock in moisture with Hyaluronic Acid and Ceramide rich creams and toners.',
                    ],
                    [
                        'value' => 'Redness & Irritation',
                        'meta_title' => 'Soothing Skincare for Redness & Irritated Skin',
                        'meta_description' => 'Calm skin redness and repair broken skin barriers with Centella and Heartleaf.',
                    ],
                    [
                        'value' => 'Pore Care & Oil Control',
                        'meta_title' => 'Pore Care & Excess Sebum Control Products',
                        'meta_description' => 'Tighten enlarged pores and reduce facial shine with BHA pore cleansers.',
                    ],
                ],
            ],
            [
                'name' => 'Key Ingredient',
                'type' => 'rich_text',
                'values' => [
                    [
                        'value' => 'Snail Mucin',
                        'meta_title' => 'Snail Mucin Skincare & Repair Products',
                        'meta_description' => 'Shop viral Korean Snail Mucin essences and creams for skin repair and glow.',
                    ],
                    [
                        'value' => 'Centella Asiatica (Cica)',
                        'meta_title' => 'Centella Asiatica (Cica) Soothing Cosmetics',
                        'meta_description' => 'Relieve skin inflammation and redness with authentic Cica Korean products.',
                    ],
                    [
                        'value' => 'Hyaluronic Acid',
                        'meta_title' => 'Hyaluronic Acid Hydrating Skincare Products',
                        'meta_description' => 'Plump skin and restore moisture with high-performance Hyaluronic Acid serums.',
                    ],
                    [
                        'value' => 'Niacinamide',
                        'meta_title' => 'Niacinamide Brightening & Pore Tightening Products',
                        'meta_description' => 'Even skin tone and reduce pore size with Niacinamide skincare formulations.',
                    ],
                    [
                        'value' => 'Heartleaf',
                        'meta_title' => 'Heartleaf Calming Skincare Collection',
                        'meta_description' => 'Soothe sensitive skin and clear clogged pores with Korean Heartleaf products.',
                    ],
                    [
                        'value' => 'Ginseng',
                        'meta_title' => 'Ginseng Anti-Aging & Revitalizing Skincare',
                        'meta_description' => 'Traditional Hanbang Korean skincare enriched with nourishing Ginseng extract.',
                    ],
                    [
                        'value' => 'Retinol / Retinoid',
                        'meta_title' => 'Retinol Anti-Aging Serums & Creams',
                        'meta_description' => 'Smooth skin texture and fight fine lines with gentle Korean Retinol treatments.',
                    ],
                    [
                        'value' => 'Propolis & Honey',
                        'meta_title' => 'Propolis & Honey Nourishing Skincare',
                        'meta_description' => 'Boost natural radiance and skin barrier health with Propolis and Honey.',
                    ],
                    [
                        'value' => 'Tea Tree',
                        'meta_title' => 'Tea Tree Blemish Control & Clarifying Products',
                        'meta_description' => 'Purify skin and fight acne-causing bacteria with Tea Tree oil skincare.',
                    ],
                    [
                        'value' => 'BHA / Salicylic Acid',
                        'meta_title' => 'BHA & Salicylic Acid Exfoliating Products',
                        'meta_description' => 'Unclog pores and remove dead skin cells with gentle BHA liquid exfoliants.',
                    ],
                ],
            ],
            [
                'name' => 'Finish Type',
                'type' => 'multi_select',
                'values' => [
                    [
                        'value' => 'Dewy / Glowy',
                        'meta_title' => 'Dewy & Glowy Finish Makeup & Skincare',
                        'meta_description' => 'Achieve the Korean glass skin look with luminous, dewy finish cosmetics.',
                    ],
                    [
                        'value' => 'Matte',
                        'meta_title' => 'Matte Finish Cosmetics & Long-Lasting Makeup',
                        'meta_description' => 'Oil-free matte makeup and sunscreen for a velvety, shine-free finish.',
                    ],
                    'Semi-Matte',
                    'Natural / Satin',
                ],
            ],
            [
                'name' => 'Formulation',
                'type' => 'multi_select',
                'values' => ['Cream', 'Gel', 'Serum / Liquid', 'Balm', 'Oil', 'Stick', 'Sheet Mask'],
            ],
            [
                'name' => 'SPF Rating',
                'type' => 'multi_select',
                'values' => ['SPF 30', 'SPF 50+ PA++++', 'SPF 50+ PA+++'],
            ],
            [
                'name' => 'Volume / Size',
                'type' => 'multi_select',
                'values' => ['30ml', '50ml', '100ml', '150ml', '200ml', '250ml', '500ml', '1 Sheet', '10 Sheets'],
            ],
            [
                'name' => 'Shade / Color',
                'type' => 'multi_select',
                'values' => [
                    [
                        'value' => '#21 Light Beige',
                        'meta_title' => 'Shade #21 Light Beige Korean Cushion & Foundation',
                        'meta_description' => 'Explore cushion foundations and concealers in shade #21 Light Beige.',
                    ],
                    [
                        'value' => '#23 Natural Beige',
                        'meta_title' => 'Shade #23 Natural Beige Cushion & Cosmetics',
                        'meta_description' => 'Natural Beige shade #23 BB creams and foundations for medium skin tones.',
                    ],
                    '#25 Warm Beige',
                    'Clear / Transparent',
                ],
            ],
        ];

        foreach ($attributesData as $attrData) {
            $valueStringsOnly = array_map(function ($val) {
                return is_array($val) ? $val['value'] : $val;
            }, $attrData['values']);

            $attribute = Attribute::updateOrCreate(
                ['name' => $attrData['name']],
                [
                    'slug' => Str::slug($attrData['name']),
                    'type' => $attrData['type'],
                    'values' => $valueStringsOnly,
                    'is_active' => true,
                ]
            );

            // Sync attribute values to AttributeValue model
            $existingValues = $attribute->attributeValues()->withTrashed()->pluck('value', 'id')->toArray();
            $processedValues = [];

            foreach ($attrData['values'] as $valItem) {
                $valString = is_array($valItem) ? $valItem['value'] : $valItem;
                $metaTitle = is_array($valItem) ? ($valItem['meta_title'] ?? null) : null;
                $metaDescription = is_array($valItem) ? ($valItem['meta_description'] ?? null) : null;

                $existingId = array_search($valString, $existingValues);
                if ($existingId !== false) {
                    $attrVal = AttributeValue::withTrashed()->find($existingId);
                    if ($attrVal) {
                        if ($attrVal->trashed()) {
                            $attrVal->restore();
                        }
                        $attrVal->update([
                            'value' => $valString,
                            'meta_title' => $metaTitle,
                            'meta_description' => $metaDescription,
                            'is_active' => true,
                        ]);
                    }
                    $processedValues[] = $existingId;
                } else {
                    $newVal = $attribute->attributeValues()->create([
                        'value' => $valString,
                        'meta_title' => $metaTitle,
                        'meta_description' => $metaDescription,
                        'is_active' => true,
                    ]);
                    $processedValues[] = $newVal->id;
                }
            }

            // Clean up old removed ones if any
            $attribute->attributeValues()->whereNotIn('id', $processedValues)->delete();
        }

        $this->command->info('Cosmetics Attributes & Values seeded successfully.');
    }
}
