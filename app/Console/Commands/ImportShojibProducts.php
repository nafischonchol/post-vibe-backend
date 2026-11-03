<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImportShojibProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:import-shojib-products {--file=database/shojibwarehouse_21-09-26.sql}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import products and related catalog data from shojibwarehouse SQL dump file into local database';

    /**
     * In-memory map of category slug => new category ID
     */
    protected array $categorySlugToIdMap = [];

    /**
     * Tables to process in order of dependency.
     */
    protected array $tables = [
        'categories',
        'brands',
        'attributes',
        'attribute_values',
        'products',
        'product_images',
        'product_variants',
        'product_variant_attribute_values',
        'product_specifications',
        'product_specification_values',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $relPath = $this->option('file');
        $filePath = base_path($relPath);

        if (! file_exists($filePath)) {
            $this->error("SQL dump file not found at: {$filePath}");

            return Command::FAILURE;
        }

        $this->info("Reading SQL dump file from: {$filePath}...");
        $sqlContent = file_get_contents($filePath);

        $this->info('Disabling Foreign Key Checks...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            // Truncate existing data in reverse order
            $reverseTables = array_reverse($this->tables);
            foreach ($reverseTables as $table) {
                if (Schema::hasTable($table)) {
                    $this->line("Truncating table: {$table}");
                    DB::table($table)->truncate();
                }
            }

            foreach ($this->tables as $tableName) {
                if (! Schema::hasTable($tableName)) {
                    $this->warn("Skipping {$tableName}: Table does not exist in local database.");

                    continue;
                }

                // If categories table, seed our updated 2-tier K-Beauty architecture
                if ($tableName === 'categories') {
                    $this->info('Seeding new 2-tier K-Beauty category hierarchy...');
                    $this->seedNewCategories();
                    continue;
                }

                $dumpCols = $this->getDumpColumns($sqlContent, $tableName);
                if (empty($dumpCols)) {
                    $this->warn("No CREATE TABLE definition found in dump for: {$tableName}");

                    continue;
                }

                $localCols = Schema::getColumnListing($tableName);
                $rows = $this->extractInsertRows($sqlContent, $tableName);

                $this->info("Importing {$tableName}: found ".count($rows).' rows in dump.');

                if (empty($rows)) {
                    continue;
                }

                $insertBatch = [];
                foreach ($rows as $rowValues) {
                    $mappedData = [];
                    foreach ($dumpCols as $index => $colName) {
                        if (in_array($colName, $localCols, true) && array_key_exists($index, $rowValues)) {
                            $mappedData[$colName] = $rowValues[$index];
                        }
                    }

                    // Dynamically map product to correct new subcategory
                    if ($tableName === 'products' && isset($mappedData['name'])) {
                        $resolvedSlug = $this->resolveProductCategorySlug(
                            isset($mappedData['id']) ? (int) $mappedData['id'] : null,
                            (string) $mappedData['name'],
                            isset($mappedData['category_id']) ? (int) $mappedData['category_id'] : null
                        );

                        if (isset($this->categorySlugToIdMap[$resolvedSlug])) {
                            $mappedData['category_id'] = $this->categorySlugToIdMap[$resolvedSlug];
                        }
                    }

                    // Handle specific missing required columns for local DB
                    if ($tableName === 'attributes' && in_array('slug', $localCols, true) && empty($mappedData['slug'])) {
                        $mappedData['slug'] = Str::slug($mappedData['name'] ?? ('attr-'.($mappedData['id'] ?? uniqid())));
                    }

                    if ($tableName === 'attribute_values' && in_array('is_active', $localCols, true) && ! isset($mappedData['is_active'])) {
                        $mappedData['is_active'] = 1;
                    }

                    $insertBatch[] = $mappedData;

                    if (count($insertBatch) >= 100) {
                        DB::table($tableName)->insert($insertBatch);
                        $insertBatch = [];
                    }
                }

                if (! empty($insertBatch)) {
                    DB::table($tableName)->insert($insertBatch);
                }

                $insertedCount = DB::table($tableName)->count();
                $this->info("Successfully populated {$tableName} (Total rows in DB: {$insertedCount})");
            }

            $this->info('Re-enabling Foreign Key Checks...');
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            // Clear application category & filter caches
            Cache::forget('popular_categories');
            Cache::forget('header_menu_categories');
            Cache::forget('customer_filterable_data');
            $this->info('Cleared popular, header_menu, and filterable_data caches.');

            $this->info('Product catalog import completed successfully!');
            $this->printCategoryDistribution();

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->error('Import failed with error: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }

    /**
     * Seed new 2-tier K-Beauty category hierarchy.
     */
    protected function seedNewCategories(): void
    {
        DB::table('categories')->truncate();
        $this->categorySlugToIdMap = [];

        $definitions = $this->getNewCategoriesDefinition();
        $now = now();

        foreach ($definitions as $rootData) {
            $rootId = DB::table('categories')->insertGetId([
                'name' => $rootData['name'],
                'slug' => $rootData['slug'],
                'icon' => $rootData['icon'] ?? null,
                'parent_id' => null,
                'is_active' => 1,
                'is_header_menu' => ! empty($rootData['is_header_menu']) ? 1 : 0,
                'meta_title' => $rootData['name'].' - Mohima',
                'meta_description' => 'Explore authentic Korean '.$rootData['name'].' at Mohima.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->categorySlugToIdMap[$rootData['slug']] = $rootId;

            if (! empty($rootData['children'])) {
                foreach ($rootData['children'] as $childData) {
                    $childId = DB::table('categories')->insertGetId([
                        'name' => $childData['name'],
                        'slug' => $childData['slug'],
                        'icon' => $childData['icon'] ?? null,
                        'parent_id' => $rootId,
                        'is_active' => 1,
                        'is_header_menu' => 0,
                        'meta_title' => $childData['name'].' - Mohima',
                        'meta_description' => 'Shop authentic Korean '.$childData['name'].' online at Mohima.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $this->categorySlugToIdMap[$childData['slug']] = $childId;
                }
            }
        }

        $count = DB::table('categories')->count();
        $this->info("Successfully seeded {$count} new categories (Root: ".count($definitions).', Subcategories: '.($count - count($definitions)).').');
    }

    /**
     * Define the 11 Root Categories and 32 Subcategories for Mohima.
     */
    protected function getNewCategoriesDefinition(): array
    {
        return [
            [
                'name' => 'Cleansers',
                'slug' => 'cleansers',
                'icon' => 'droplet',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Cleansing Oil & Balm', 'slug' => 'cleansing-oil-balm'],
                    ['name' => 'Cleansing Foam & Gel', 'slug' => 'cleansing-foam-gel'],
                    ['name' => 'Micellar & Cleansing Water', 'slug' => 'micellar-cleansing-water'],
                ],
            ],
            [
                'name' => 'Toners & Mists',
                'slug' => 'toners-mists',
                'icon' => 'waves',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Hydrating & Calming Toner', 'slug' => 'hydrating-calming-toner'],
                    ['name' => 'Exfoliating Toner', 'slug' => 'exfoliating-toner'],
                    ['name' => 'Toner Pads', 'slug' => 'toner-pads'],
                    ['name' => 'Face Mists', 'slug' => 'face-mists'],
                ],
            ],
            [
                'name' => 'Serums & Ampoules',
                'slug' => 'serums-ampoules',
                'icon' => 'sparkles',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Face Serum', 'slug' => 'face-serum'],
                    ['name' => 'Ampoule & Booster', 'slug' => 'ampoule-booster'],
                    ['name' => 'First Essence', 'slug' => 'first-essence'],
                ],
            ],
            [
                'name' => 'Moisturizers & Creams',
                'slug' => 'moisturizers-creams',
                'icon' => 'flower2',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Gel & Light Cream', 'slug' => 'gel-light-cream'],
                    ['name' => 'Barrier & Moisture Cream', 'slug' => 'barrier-moisture-cream'],
                    ['name' => 'Emulsion & Lotion', 'slug' => 'emulsion-lotion'],
                ],
            ],
            [
                'name' => 'Sun Care',
                'slug' => 'sun-care',
                'icon' => 'sun',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Sun Cream & Lotion', 'slug' => 'sun-cream-lotion'],
                    ['name' => 'Sun Stick', 'slug' => 'sun-stick'],
                    ['name' => 'Sun Serum & Fluid', 'slug' => 'sun-serum-fluid'],
                ],
            ],
            [
                'name' => 'Masks & Peels',
                'slug' => 'masks-peels',
                'icon' => 'layers',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Sheet Mask', 'slug' => 'sheet-mask'],
                    ['name' => 'Clay & Mud Mask', 'slug' => 'clay-mud-mask'],
                    ['name' => 'Sleeping & Wash-off Mask', 'slug' => 'sleeping-wash-off-mask'],
                    ['name' => 'Peeling Gel & Scrub', 'slug' => 'peeling-gel-scrub'],
                ],
            ],
            [
                'name' => 'Eye & Lip Care',
                'slug' => 'eye-lip-care',
                'icon' => 'box',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Eye Cream & Serum', 'slug' => 'eye-cream-serum'],
                    ['name' => 'Eye Patch', 'slug' => 'eye-patch'],
                    ['name' => 'Lip Care & Mask', 'slug' => 'lip-care-mask'],
                ],
            ],
            [
                'name' => 'Hair Care',
                'slug' => 'hair-care',
                'icon' => 'sparkles',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Scalp & Shampoo Care', 'slug' => 'scalp-shampoo-care'],
                    ['name' => 'Conditioner & Treatment', 'slug' => 'conditioner-treatment'],
                    ['name' => 'Scalp Tonic & Hair Serum', 'slug' => 'scalp-tonic-hair-serum'],
                ],
            ],
            [
                'name' => 'Body Care',
                'slug' => 'body-care',
                'icon' => 'droplet',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Body Lotion & Cream', 'slug' => 'body-lotion-cream'],
                    ['name' => 'Body Wash & Cleanser', 'slug' => 'body-wash-cleanser'],
                    ['name' => 'Body Oil, Scrub & Hand Care', 'slug' => 'body-oil-scrub-hand-care'],
                ],
            ],
            [
                'name' => 'Makeup & Complexion',
                'slug' => 'makeup-complexion',
                'icon' => 'box',
                'is_header_menu' => false,
                'children' => [
                    ['name' => 'BB Cream & Cushion', 'slug' => 'bb-cream-cushion'],
                    ['name' => 'Primers & Setting Powder', 'slug' => 'primers-setting-powder'],
                ],
            ],
            [
                'name' => 'Sets & Kits',
                'slug' => 'sets-kits',
                'icon' => 'box',
                'is_header_menu' => true,
                'children' => [
                    ['name' => 'Skincare Discovery Sets', 'slug' => 'skincare-discovery-sets'],
                    ['name' => 'Acne & Spot Patches', 'slug' => 'acne-spot-patches'],
                ],
            ],
        ];
    }

    /**
     * Classify product into accurate target category slug.
     */
    protected function resolveProductCategorySlug(?int $id, string $name, ?int $oldCatId): string
    {
        $lower = strtolower($name);

        // 1. Specific product overrides
        if ($id === 383 || str_contains($lower, 'no-sebum mineral powder') || str_contains($lower, 'setting powder')) {
            return 'primers-setting-powder';
        }
        if (str_contains($lower, 'patch') && (str_contains($lower, 'pimple') || str_contains($lower, 'spot patch') || str_contains($lower, 'acne'))) {
            return 'acne-spot-patches';
        }
        if (str_contains($lower, 'pad') && (str_contains($lower, 'toner pad') || str_contains($lower, 'clear pad') || str_contains($lower, 'glow pad') || str_contains($lower, 'boosting pad') || str_contains($lower, 'brightening pad') || str_contains($lower, 'soothing pad') || str_contains($lower, 'hyaluron redness'))) {
            return 'toner-pads';
        }

        // 2. Body & Hair Care (Old ID: 17)
        if ($oldCatId === 17) {
            if (str_contains($lower, 'shampoo') || str_contains($lower, 'hair loss')) {
                return 'scalp-shampoo-care';
            }
            if (str_contains($lower, 'conditioner') || str_contains($lower, 'treatment') || str_contains($lower, 'hair mask')) {
                return 'conditioner-treatment';
            }
            if (str_contains($lower, 'hair serum') || str_contains($lower, 'root enhancer') || str_contains($lower, 'tonic') || str_contains($lower, 'purifying tonic')) {
                return 'scalp-tonic-hair-serum';
            }
            if (str_contains($lower, 'body wash') || str_contains($lower, 'feminine wash')) {
                return 'body-wash-cleanser';
            }
            if (str_contains($lower, 'body lotion') || str_contains($lower, 'smoothie lotion')) {
                return 'body-lotion-cream';
            }
            if (str_contains($lower, 'body oil') || str_contains($lower, 'hand') || str_contains($lower, 'foot')) {
                return 'body-oil-scrub-hand-care';
            }

            return 'body-lotion-cream';
        }

        // 3. Makeup (Old ID: 10, 11)
        if ($oldCatId === 10 || $oldCatId === 11) {
            return 'bb-cream-cushion';
        }

        // 4. Eye & Lip Care (Old ID: 7)
        if ($oldCatId === 7) {
            if (str_contains($lower, 'lip')) {
                return 'lip-care-mask';
            }
            if (str_contains($lower, 'patch')) {
                return 'eye-patch';
            }

            return 'eye-cream-serum';
        }

        // 5. Sun Care (Old ID: 6)
        if ($oldCatId === 6) {
            if (str_contains($lower, 'stick')) {
                return 'sun-stick';
            }
            if (str_contains($lower, 'serum') || str_contains($lower, 'fluid') || str_contains($lower, 'aqua')) {
                return 'sun-serum-fluid';
            }

            return 'sun-cream-lotion';
        }

        // 6. Cleansers (Old ID: 2)
        if ($oldCatId === 2) {
            if (str_contains($lower, 'oil') || str_contains($lower, 'balm')) {
                return 'cleansing-oil-balm';
            }
            if (str_contains($lower, 'micellar') || str_contains($lower, 'water')) {
                return 'micellar-cleansing-water';
            }

            return 'cleansing-foam-gel';
        }

        // 7. Toners & Mists (Old ID: 3)
        if ($oldCatId === 3) {
            if (str_contains($lower, 'mist')) {
                return 'face-mists';
            }
            if (str_contains($lower, 'pad')) {
                return 'toner-pads';
            }
            if (str_contains($lower, 'aha') || str_contains($lower, 'bha') || str_contains($lower, 'pha') || str_contains($lower, 'peel') || str_contains($lower, 'exfoliat')) {
                return 'exfoliating-toner';
            }

            return 'hydrating-calming-toner';
        }

        // 8. Serums & Ampoules (Old ID: 4)
        if ($oldCatId === 4) {
            if (str_contains($lower, 'essence')) {
                return 'first-essence';
            }
            if (str_contains($lower, 'ampoule') || str_contains($lower, 'booster') || str_contains($lower, 'shot')) {
                return 'ampoule-booster';
            }

            return 'face-serum';
        }

        // 9. Moisturizers & Creams (Old ID: 5)
        if ($oldCatId === 5) {
            if ($id === 366 || str_contains($lower, 'sleeping pack') || str_contains($lower, 'sleeping mask')) {
                return 'sleeping-wash-off-mask';
            }
            if (str_contains($lower, 'gel') || str_contains($lower, 'soothing gel') || str_contains($lower, 'water cream') || str_contains($lower, 'aqua')) {
                return 'gel-light-cream';
            }
            if (str_contains($lower, 'emulsion') || str_contains($lower, 'lotion')) {
                return 'emulsion-lotion';
            }

            return 'barrier-moisture-cream';
        }

        // 10. Masks (Old ID: 8)
        if ($oldCatId === 8) {
            if (str_contains($lower, 'sheet') || str_contains($lower, 'wrapping')) {
                return 'sheet-mask';
            }
            if (str_contains($lower, 'clay') || str_contains($lower, 'mud') || str_contains($lower, 'volcanic')) {
                return 'clay-mud-mask';
            }
            if (str_contains($lower, 'peel')) {
                return 'peeling-gel-scrub';
            }

            return 'sleeping-wash-off-mask';
        }

        // 11. Sets & Kits (Old ID: 15)
        if ($oldCatId === 15) {
            return 'skincare-discovery-sets';
        }

        return 'skincare-discovery-sets';
    }

    /**
     * Print product distribution across categories.
     */
    protected function printCategoryDistribution(): void
    {
        $stats = DB::table('products')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select('categories.name', DB::raw('count(*) as count'))
            ->groupBy('categories.name')
            ->orderByDesc('count')
            ->get();

        $rows = $stats->map(fn ($s) => [$s->name, $s->count])->toArray();
        $this->table(['Category Name', 'Product Count'], $rows);
    }

    /**
     * Extract columns from CREATE TABLE statement in SQL dump.
     */
    private function getDumpColumns(string $sqlText, string $tableName): array
    {
        $pattern = '/CREATE TABLE [`"]?'.preg_quote($tableName, '/').'[`"]?\s*\((.*?)\)\s*ENGINE/s';
        if (preg_match($pattern, $sqlText, $matches)) {
            $cols = [];
            $lines = explode("\n", $matches[1]);
            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, '`')) {
                    $parts = explode('`', $line);
                    if (isset($parts[1])) {
                        $cols[] = $parts[1];
                    }
                }
            }

            return $cols;
        }

        return [];
    }

    /**
     * Extract INSERT row tuples for a given table from SQL dump.
     */
    private function extractInsertRows(string $sqlText, string $tableName): array
    {
        $rows = [];
        $pattern = '/INSERT INTO [`"]?'.preg_quote($tableName, '/').'[`"]?\s*(?:\([^)]+\)\s*)?VALUES\s*/i';

        if (! preg_match_all($pattern, $sqlText, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as $match) {
            $offset = $match[1] + strlen($match[0]);
            // Extract content until terminating semicolon
            $semiPos = strpos($sqlText, ";\n", $offset);
            if ($semiPos === false) {
                $semiPos = strpos($sqlText, ';', $offset);
            }
            if ($semiPos === false) {
                continue;
            }

            $valuesSegment = substr($sqlText, $offset, $semiPos - $offset);
            $parsedTuples = $this->parseSqlTuples($valuesSegment);
            foreach ($parsedTuples as $tuple) {
                $rows[] = $tuple;
            }
        }

        return $rows;
    }

    /**
     * Parse SQL tuple string: (1, 'val1', NULL), (2, 'val2', 123)
     */
    private function parseSqlTuples(string $valuesContent): array
    {
        $rows = [];
        $currentRow = [];
        $currentVal = '';
        $inString = false;
        $quoteChar = null;
        $isEscaped = false;
        $inRow = false;
        $isQuotedString = false;

        $len = strlen($valuesContent);
        for ($i = 0; $i < $len; $i++) {
            $ch = $valuesContent[$i];

            if (! $inRow) {
                if ($ch === '(') {
                    $inRow = true;
                    $currentRow = [];
                    $currentVal = '';
                    $isQuotedString = false;
                }

                continue;
            }

            if ($isEscaped) {
                $currentVal .= match ($ch) {
                    'r' => "\r",
                    'n' => "\n",
                    't' => "\t",
                    '0' => "\0",
                    'b' => "\x08",
                    'Z' => "\x1A",
                    default => $ch,
                };
                $isEscaped = false;

                continue;
            }

            if ($ch === '\\') {
                $isEscaped = true;

                continue;
            }

            if ($inString) {
                if ($ch === $quoteChar) {
                    $inString = false;
                } else {
                    $currentVal .= $ch;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $quoteChar = $ch;
                $isQuotedString = true;

                continue;
            }

            if ($ch === ',') {
                if ($isQuotedString) {
                    $val = $currentVal;
                } else {
                    $raw = trim($currentVal);
                    if (strtoupper($raw) === 'NULL') {
                        $val = null;
                    } else {
                        $val = $raw;
                    }
                }
                $currentRow[] = $val;
                $currentVal = '';
                $isQuotedString = false;

                continue;
            }

            if ($ch === ')') {
                if ($isQuotedString) {
                    $val = $currentVal;
                } else {
                    $raw = trim($currentVal);
                    if (strtoupper($raw) === 'NULL') {
                        $val = null;
                    } else {
                        $val = $raw;
                    }
                }
                $currentRow[] = $val;
                $rows[] = $currentRow;
                $inRow = false;
                $currentRow = [];
                $currentVal = '';
                $isQuotedString = false;

                continue;
            }

            $currentVal .= $ch;
        }

        return $rows;
    }
}
