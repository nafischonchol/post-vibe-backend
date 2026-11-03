<?php

namespace App\Console\Commands;

use App\Jobs\ProcessProductThumbnailJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncProductThumbnailsAndGallery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:sync-thumbnails
                            {--disk= : Storage disk to use (default: filesystems.default)}
                            {--sync : Run image processing synchronously instead of queueing}
                            {--force : Force regenerate thumbnails and blur images even if already present}
                            {--limit= : Limit the number of products to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate small thumbnails (_thumb.webp), blur placeholders, and sync primary gallery images from existing storage images';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $disk = $this->option('disk') ?: config('filesystems.default');
        $sync = (bool) $this->option('sync');
        $force = (bool) $this->option('force');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info("=== Product Thumbnails & Gallery Synchronization ===");
        $this->info("Storage Disk: {$disk}");
        $this->info("Execution Mode: " . ($sync ? 'Synchronous' : 'Queued (Background Workers)'));
        if ($force) {
            $this->warn("Force Mode: Enabled (re-processing all products)");
        }
        if ($limit) {
            $this->line("Limit: Processing up to {$limit} product(s)");
        }

        $storage = Storage::disk($disk);

        $query = Product::whereNotNull('image')
            ->where('image', '!=', '')
            ->with('images')
            ->orderBy('id', 'asc');

        if ($limit && $limit > 0) {
            $query->take($limit);
        }

        $products = $query->get();
        $total = $products->count();

        if ($total === 0) {
            $this->warn("No products found with an image.");
            return Command::SUCCESS;
        }

        $this->info("Found {$total} product(s) to inspect.");
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $syncedGallery = 0;

        foreach ($products as $product) {
            try {
                $hasPrimary = $product->images->contains('is_main', true);

                // 1. Ensure product_images has a primary item (is_main = true)
                if (! $hasPrimary) {
                    $matchingImage = $product->images->first(function ($img) use ($product) {
                        return $img->image_path === $product->image
                            || pathinfo($img->image_path, PATHINFO_FILENAME) === pathinfo($product->image, PATHINFO_FILENAME);
                    });

                    if ($matchingImage) {
                        $matchingImage->update(['is_main' => true]);
                    } else {
                        $product->images()->create([
                            'image_path' => $product->image,
                            'is_main' => true,
                        ]);
                    }

                    $syncedGallery++;
                }

                // 2. Check if thumbnail / blur_image generation is needed
                $isThumbWebp = str_ends_with($product->image, '_thumb.webp');
                $hasBlur = ! empty($product->blur_image);

                if (! $force && $isThumbWebp && $hasBlur) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                // Check file existence directly in our storage
                $imagePath = $product->image;
                if (! $storage->exists($imagePath)) {
                    $failed++;
                    Log::warning("products:sync-thumbnails: Image not found in storage for Product #{$product->id}: {$imagePath}");
                    $progressBar->advance();
                    continue;
                }

                // 3. Process thumbnail, full-size, and blur_image from our storage
                if ($sync) {
                    (new ProcessProductThumbnailJob($product->id, $imagePath, $disk))->handle();
                } else {
                    ProcessProductThumbnailJob::dispatch($product->id, $imagePath, $disk);
                }

                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error("Error syncing product #{$product->id}: {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->table(
            ['Total Inspected', 'Processed / Dispatched', 'Skipped (Already Done)', 'Gallery Backfilled', 'Failed (Not in Storage)'],
            [[$total, $processed, $skipped, $syncedGallery, $failed]]
        );

        $this->info("Sync completed successfully!");

        return Command::SUCCESS;
    }
}
