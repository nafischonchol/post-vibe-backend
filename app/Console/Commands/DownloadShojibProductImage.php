<?php

namespace App\Console\Commands;

use App\Jobs\OptimizeImageJob;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadShojibProductImage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'download:shojib-images
                            {--base-url=https://pub-8fb9df7c2a6544adae22fd17339b5f9a.r2.dev/ : Base URL to download from}
                            {--disk= : Storage disk to upload to (default: filesystems.default)}
                            {--sync : Run optimization synchronously instead of queueing}
                            {--force : Force re-download even if already exists in storage}
                            {--limit= : Limit the number of images to process}
                            {--without-optimization : Skip dispatching OptimizeImageJob}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download Shojib product images from remote R2 and upload to our storage with optimization';

    public function handle(): int
    {
        $baseUrl = rtrim($this->option('base-url') ?: 'https://pub-8fb9df7c2a6544adae22fd17339b5f9a.r2.dev/', '/');
        $disk = $this->option('disk') ?: config('filesystems.default');
        $sync = (bool) $this->option('sync');
        $force = (bool) $this->option('force');
        $skipOptimization = (bool) $this->option('without-optimization');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info("Target Storage Disk: {$disk}");
        $this->info("Remote Base URL: {$baseUrl}");
        $this->info('Optimization: ' . ($skipOptimization ? 'Disabled' : ($sync ? 'Synchronous' : 'Queued')));

        $storage = Storage::disk($disk);

        // Fetch image paths from Product and ProductImage
        $productImages = Product::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->pluck('image');

        $productMetaImages = Product::query()
            ->whereNotNull('meta_image')
            ->where('meta_image', '!=', '')
            ->pluck('meta_image');

        $galleryImages = ProductImage::query()
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->pluck('image_path');

        $uniquePaths = $productImages
            ->merge($productMetaImages)
            ->merge($galleryImages)
            ->map(function ($path) {
                if (empty($path)) {
                    return null;
                }
                // If it's a full URL, strip host and get path
                if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                    $parsed = parse_url($path, PHP_URL_PATH);
                    return $parsed ? ltrim($parsed, '/') : null;
                }
                return ltrim($path, '/');
            })
            ->filter()
            ->unique()
            ->values();

        if ($limit !== null && $limit > 0) {
            $uniquePaths = $uniquePaths->take($limit);
        }

        $total = $uniquePaths->count();
        if ($total === 0) {
            $this->warn('No images found in database to download.');
            return Command::SUCCESS;
        }

        $this->info("Found {$total} unique image(s) to process.");

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $downloaded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($uniquePaths as $path) {
            $webpPath = preg_replace('/\.[^.]+$/', '.webp', $path);

            // Skip if already exists on storage (unless force option is enabled)
            if (! $force) {
                if ($storage->exists($path) || ($webpPath && $storage->exists($webpPath))) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }
            }

            $downloadUrl = "{$baseUrl}/{$path}";

            try {
                $response = Http::timeout(30)->retry(2, 500)->get($downloadUrl);

                if (! $response->successful()) {
                    $failed++;
                    Log::warning("Failed to download image [{$response->status()}]: {$downloadUrl}");
                    $progressBar->advance();
                    continue;
                }

                $content = $response->body();

                // Upload to our storage
                $uploaded = $storage->put($path, $content, 'public');

                if (! $uploaded) {
                    $failed++;
                    Log::error("Failed to store image to disk {$disk}: {$path}");
                    $progressBar->advance();
                    continue;
                }

                $downloaded++;

                // Dispatch or run optimization job
                if (! $skipOptimization) {
                    if ($sync) {
                        (new OptimizeImageJob($path, $disk))->handle();
                    } else {
                        OptimizeImageJob::dispatch($path, $disk);
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("Error downloading/saving image {$downloadUrl}: {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->table(
            ['Total Candidates', 'Downloaded & Uploaded', 'Skipped (Already Exists)', 'Failed'],
            [[$total, $downloaded, $skipped, $failed]]
        );

        $this->info('Image download process completed!');

        return Command::SUCCESS;
    }
}
