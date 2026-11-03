<?php

namespace App\Console\Commands;

use App\Jobs\OptimizeImageJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OptimizeExistingImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:optimize {--sync : Run optimization synchronously instead of queueing} {--disk= : Specify storage disk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan existing images (products, banners, categories, brands, store setup) and convert to WebP (<200KB)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $disk = $this->option('disk') ?: config('filesystems.default');
        $sync = (bool) $this->option('sync');

        $this->info("Scanning images on disk: {$disk} (sync: " . ($sync ? 'yes' : 'no') . ")...");

        $targets = [
            ['products', 'image'],
            ['products', 'meta_image'],
            ['product_images', 'image_path'],
            ['banners', 'banner_image'],
            ['banners', 'mobile_banner_image'],
            ['categories', 'meta_image'],
            ['categories', 'icon'],
            ['categories', 'banners'],
            ['brands', 'meta_image'],
            ['brands', 'icon'],
            ['brands', 'banners'],
            ['store_setups', 'logo'],
            ['attribute_values', 'image'],
        ];

        $paths = collect();

        foreach ($targets as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $found = DB::table($table)
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->pluck($column);
                $paths = $paths->merge($found);
            }
        }

        $uniquePaths = $paths->unique()->filter()->values();
        $this->info("Found {$uniquePaths->count()} image references in database.");

        $storage = Storage::disk($disk);
        $queued = 0;

        foreach ($uniquePaths as $path) {
            if (! $storage->exists($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['svg', 'pdf', 'mp4', 'webm', 'ico'])) {
                continue;
            }

            $size = $storage->size($path);
            $isWebp = $ext === 'webp';

            // Process if not webp OR if already webp but > 200KB
            if (! $isWebp || $size > OptimizeImageJob::MAX_BYTES) {
                $this->line("Processing: {$path} (" . round($size / 1024, 2) . " KB, ext: {$ext})");

                if ($sync) {
                    (new OptimizeImageJob($path, $disk))->handle();
                } else {
                    OptimizeImageJob::dispatch($path, $disk);
                }

                $queued++;
            }
        }

        $this->info("Completed. {$queued} images processed or queued for optimization.");

        return Command::SUCCESS;
    }
}
