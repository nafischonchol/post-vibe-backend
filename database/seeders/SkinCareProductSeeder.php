<?php

namespace Database\Seeders;

use App\Enums\ProductStatusEnum;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SkinCareProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $category = Category::first();
        $unit = Unit::first();
        $faker = Factory::create();

        $skinCareAdjectives = ['Hydrating', 'Rejuvenating', 'Anti-Aging', 'Soothing', 'Brightening', 'Purifying', 'Revitalizing', 'Nourishing', 'Balancing', 'Gentle'];
        $skinCareNouns = ['Serum', 'Moisturizer', 'Cleanser', 'Toner', 'Face Mask', 'Eye Cream', 'Sunscreen', 'Exfoliator', 'Face Oil', 'Night Cream'];

        for ($i = 1; $i <= 50; $i++) {
            $name = $faker->randomElement($skinCareAdjectives).' '.$faker->randomElement($skinCareNouns).' '.$faker->regexify('[A-Z0-9]{3}');
            $slug = Str::slug($name).'-'.uniqid();

            $product = Product::create([
                'name' => $name,
                'slug' => $slug,
                'description' => $faker->paragraph(3),
                'category_id' => $category ? $category->id : null,
                'unit_id' => $unit ? $unit->id : null,
                'weight' => $faker->randomFloat(2, 0.1, 1.5),
                'status' => ProductStatusEnum::ACTIVE->value,
                // No image as requested
            ]);

            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => strtoupper($faker->bothify('SKU-####-????')),
                'price' => $faker->randomFloat(2, 10, 200),
                'stock' => $faker->numberBetween(10, 100),
            ]);
        }

        $this->command->info('50 skin care products seeded successfully.');
    }
}
