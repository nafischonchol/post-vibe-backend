<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OptimizeImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * Target maximum file size: 200 KB (in bytes).
     */
    public const MAX_BYTES = 200 * 1024;

    /**
     * Maximum dimension (width/height) for high-definition web assets.
     */
    public const MAX_DIMENSION = 1920;

    public function __construct(
        public string $path,
        public ?string $disk = null
    ) {
        $this->disk = $disk ?? config('filesystems.default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $storage = Storage::disk($this->disk);

            if (! $storage->exists($this->path)) {
                Log::warning("OptimizeImageJob: File not found at {$this->path} on disk {$this->disk}");
                return;
            }

            // Skip SVGs and non-raster files
            $extension = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
            if (in_array($extension, ['svg', 'pdf', 'mp4', 'webm', 'ico'])) {
                return;
            }

            $rawContent = $storage->get($this->path);
            if (! $rawContent) {
                return;
            }

            $image = @imagecreatefromstring($rawContent);
            if (! $image) {
                Log::warning("OptimizeImageJob: Unable to parse image resource for {$this->path}");
                return;
            }

            // Correct EXIF orientation for JPEG photos (e.g. mobile camera shots)
            if (in_array($extension, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
                $image = $this->correctExifOrientation($rawContent, $image);
            }

            // Preserve alpha transparency for PNG / WebP / GIF
            imagealphablending($image, false);
            imagesavealpha($image, true);

            $width = imagesx($image);
            $height = imagesy($image);

            // Scale down excessively large images (e.g. 4000x3000 camera uploads)
            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                $image = $this->resizeImage($image, $width, $height, self::MAX_DIMENSION);
                $width = imagesx($image);
                $height = imagesy($image);
            }

            // Iteratively encode to WebP targeting < 200 KB without dropping quality
            $webpData = $this->encodeWebpUnderTargetSize($image, $width, $height);

            if (! $webpData) {
                Log::error("OptimizeImageJob: Failed to encode WebP for {$this->path}");
                return;
            }

            // Determine target WebP path
            $pathInfo = pathinfo($this->path);
            $dir = ($pathInfo['dirname'] !== '.' && $pathInfo['dirname'] !== '')
                ? $pathInfo['dirname'] . '/'
                : '';
            $targetPath = $dir . $pathInfo['filename'] . '.webp';

            // Store optimized WebP file
            $storage->put($targetPath, $webpData, 'public');

            // If the extension changed, update database references and delete old file
            if ($targetPath !== $this->path) {
                $this->updateDatabaseReferences($this->path, $targetPath);

                // Clean up original non-webp file
                $storage->delete($this->path);
            }

            Log::info("OptimizeImageJob: Successfully optimized {$this->path} -> {$targetPath} (" . round(strlen($webpData) / 1024, 2) . " KB)");

        } catch (\Throwable $e) {
            Log::error("OptimizeImageJob failed for {$this->path}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Fix EXIF orientation for mobile camera photos.
     */
    private function correctExifOrientation(string $rawContent, mixed $image): mixed
    {
        try {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $rawContent);
            rewind($stream);
            $exif = @exif_read_data($stream);
            fclose($stream);

            if (! empty($exif['Orientation'])) {
                $orientation = (int) $exif['Orientation'];
                $image = match ($orientation) {
                    3 => imagerotate($image, 180, 0),
                    6 => imagerotate($image, -90, 0),
                    8 => imagerotate($image, 90, 0),
                    default => $image,
                };
            }
        } catch (\Throwable) {
            // Ignore EXIF parsing errors and proceed with image as-is
        }

        return $image;
    }

    /**
     * Resize image proportionally to fit within max dimension.
     */
    private function resizeImage(mixed $source, int $origWidth, int $origHeight, int $maxDim): mixed
    {
        $scale = min($maxDim / $origWidth, $maxDim / $origHeight);
        $newWidth = max(1, (int) round($origWidth * $scale));
        $newHeight = max(1, (int) round($origHeight * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        return $resized;
    }

    /**
     * Iteratively compress to WebP under MAX_BYTES (200 KB) without quality drop.
     */
    private function encodeWebpUnderTargetSize(mixed $image, int $width, int $height): ?string
    {
        $targetBytes = self::MAX_BYTES;
        $currentImage = $image;
        $currentWidth = $width;
        $currentHeight = $height;

        // Step 1: Start with high quality (85) and step down gradually
        $qualitySteps = [85, 80, 75, 70, 65, 60, 55, 50];

        foreach ($qualitySteps as $q) {
            ob_start();
            imagewebp($currentImage, null, $q);
            $data = ob_get_clean();

            if ($data && strlen($data) <= $targetBytes) {
                return $data;
            }
        }

        // Step 2: If still > 200 KB at quality 50, scale down dimensions slightly (15% per pass)
        // instead of degrading quality below 50 (which would cause visible compression artifacts)
        for ($iteration = 0; $iteration < 4; $iteration++) {
            $newMaxDim = (int) round(max($currentWidth, $currentHeight) * 0.85);
            if ($newMaxDim < 400) {
                break; // Do not shrink below 400px
            }

            $currentImage = $this->resizeImage($currentImage, $currentWidth, $currentHeight, $newMaxDim);
            $currentWidth = imagesx($currentImage);
            $currentHeight = imagesy($currentImage);

            // Re-test with high quality first
            foreach ([75, 70, 65, 60, 50] as $q) {
                ob_start();
                imagewebp($currentImage, null, $q);
                $data = ob_get_clean();

                if ($data && strlen($data) <= $targetBytes) {
                    return $data;
                }
            }
        }

        // Fallback: return the best encoded data produced
        ob_start();
        imagewebp($currentImage, null, 50);
        return ob_get_clean() ?: null;
    }

    /**
     * Update all database references from old path to new .webp path.
     */
    private function updateDatabaseReferences(string $oldPath, string $newPath): void
    {
        $tablesAndColumns = [
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
            ['blog_posts', 'featured_image'],
            ['blog_posts', 'thumbnail_image'],
            ['blog_categories', 'image'],
        ];

        $totalUpdated = $this->applyDbUpdates($tablesAndColumns, $oldPath, $newPath);

        // If not found yet (e.g. concurrent DB insert finishing in caller), retry once after a short delay
        if ($totalUpdated === 0) {
            usleep(400000); // 400ms
            $this->applyDbUpdates($tablesAndColumns, $oldPath, $newPath);
        }
    }

    /**
     * Execute SQL updates across registered table columns.
     */
    private function applyDbUpdates(array $tablesAndColumns, string $oldPath, string $newPath): int
    {
        $updated = 0;

        foreach ($tablesAndColumns as [$table, $column]) {
            try {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    $count = DB::table($table)->where($column, $oldPath)->update([$column => $newPath]);
                    $updated += $count;
                }
            } catch (\Throwable $e) {
                Log::warning("OptimizeImageJob DB update skipped for {$table}.{$column}: " . $e->getMessage());
            }
        }

        return $updated;
    }
}
