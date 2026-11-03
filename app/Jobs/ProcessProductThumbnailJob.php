<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessProductThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * Target maximum bytes for full-size image: 200 KB.
     */
    public const MAX_FULL_BYTES = 200 * 1024;

    /**
     * Target maximum dimension for full-size product image: 1200 px.
     */
    public const MAX_FULL_DIMENSION = 1200;

    /**
     * Target maximum bytes for small card thumbnail: 30 KB.
     */
    public const MAX_THUMB_BYTES = 30 * 1024;

    /**
     * Target maximum dimension for small card thumbnail: 360 px.
     */
    public const MAX_THUMB_DIMENSION = 360;

    /**
     * Target maximum dimension for blur placeholder: 24 px.
     */
    public const MAX_BLUR_DIMENSION = 24;

    public function __construct(
        public int $productId,
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
                Log::warning("ProcessProductThumbnailJob: File not found at {$this->path} on disk {$this->disk}");
                return;
            }

            $product = Product::find($this->productId);
            if (! $product) {
                Log::warning("ProcessProductThumbnailJob: Product ID {$this->productId} not found");
                return;
            }

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
                Log::warning("ProcessProductThumbnailJob: Unable to parse image resource for {$this->path}");
                return;
            }

            // Correct EXIF orientation for mobile camera photos
            if (in_array($extension, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
                $image = $this->correctExifOrientation($rawContent, $image);
            }

            // Alpha transparency
            imagealphablending($image, false);
            imagesavealpha($image, true);

            $origWidth = imagesx($image);
            $origHeight = imagesy($image);

            if ($origWidth <= 0 || $origHeight <= 0) {
                return;
            }

            // Path calculation
            $pathInfo = pathinfo($this->path);
            $dir = ($pathInfo['dirname'] !== '.' && $pathInfo['dirname'] !== '')
                ? $pathInfo['dirname']
                : 'products/thumbnails';
            $filename = $pathInfo['filename'];
            $baseFilename = preg_replace('/_thumb$/', '', $filename);

            $fullPath = $dir . '/' . $baseFilename . '.webp';
            $thumbPath = $dir . '/' . $baseFilename . '_thumb.webp';

            // 1. Generate Full-size WebP (max 1200px, < 200KB)
            $fullImage = $image;
            $fullWidth = $origWidth;
            $fullHeight = $origHeight;
            if ($fullWidth > self::MAX_FULL_DIMENSION || $fullHeight > self::MAX_FULL_DIMENSION) {
                $fullImage = $this->resizeImage($image, $origWidth, $origHeight, self::MAX_FULL_DIMENSION);
                $fullWidth = imagesx($fullImage);
                $fullHeight = imagesy($fullImage);
            }
            $fullWebpData = $this->encodeWebpUnderTargetSize($fullImage, $fullWidth, $fullHeight, self::MAX_FULL_BYTES);

            if ($fullWebpData) {
                $storage->put($fullPath, $fullWebpData, 'public');
            }

            // 2. Generate Small Thumbnail WebP (max 360px, < 30KB)
            $thumbImage = $this->resizeImage($image, $origWidth, $origHeight, self::MAX_THUMB_DIMENSION);
            $thumbWidth = imagesx($thumbImage);
            $thumbHeight = imagesy($thumbImage);
            $thumbWebpData = $this->encodeWebpUnderTargetSize($thumbImage, $thumbWidth, $thumbHeight, self::MAX_THUMB_BYTES);

            if ($thumbWebpData) {
                $storage->put($thumbPath, $thumbWebpData, 'public');
            }

            // 3. Generate Blur Image (micro ~24px, Gaussian blur, Base64 Data URL)
            $blurDataUrl = $this->generateBlurDataUrl($image, $origWidth, $origHeight);

            // 4. Update Database
            // 4a. Update product thumbnail and blur_image
            $product->update([
                'image' => $thumbPath,
                'blur_image' => $blurDataUrl,
            ]);

            // 4b. Update or create primary entry in product_images
            $primaryImage = $product->images()->where('is_main', true)->first();
            if (! $primaryImage) {
                $primaryImage = $product->images()
                    ->where(function ($q) use ($fullPath) {
                        $q->where('image_path', $this->path)
                          ->orWhere('image_path', $fullPath);
                    })
                    ->first();
            }

            if ($primaryImage) {
                $primaryImage->update([
                    'image_path' => $fullPath,
                    'is_main' => true,
                ]);
            } else {
                $product->images()->create([
                    'image_path' => $fullPath,
                    'is_main' => true,
                ]);
            }

            // Ensure other gallery images for this product have is_main = false
            $product->images()
                ->where('image_path', '!=', $fullPath)
                ->update(['is_main' => false]);

            // 5. Clean up original file if different from full and thumb paths
            if ($this->path !== $fullPath && $this->path !== $thumbPath && $storage->exists($this->path)) {
                $storage->delete($this->path);
            }

            Log::info("ProcessProductThumbnailJob: Processed product #{$this->productId} -> thumb: {$thumbPath}, full: {$fullPath}");

        } catch (\Throwable $e) {
            Log::error("ProcessProductThumbnailJob failed for product #{$this->productId} ({$this->path}): " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate a micro-size blurred WebP converted into base64 Data URL.
     */
    private function generateBlurDataUrl(mixed $source, int $origWidth, int $origHeight): ?string
    {
        try {
            $blurRes = $this->resizeImage($source, $origWidth, $origHeight, self::MAX_BLUR_DIMENSION);

            // Multiple Gaussian blur passes to create smooth blur effect
            for ($i = 0; $i < 4; $i++) {
                imagefilter($blurRes, IMG_FILTER_GAUSSIAN_BLUR);
            }

            ob_start();
            imagewebp($blurRes, null, 65);
            $blurBinary = ob_get_clean();

            if (! $blurBinary) {
                return null;
            }

            return 'data:image/webp;base64,' . base64_encode($blurBinary);
        } catch (\Throwable $e) {
            Log::warning("ProcessProductThumbnailJob blur generation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Proportionally resize an image resource to fit inside maxDim.
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
     * Iteratively compress image to WebP under the target byte budget.
     */
    private function encodeWebpUnderTargetSize(mixed $image, int $width, int $height, int $targetBytes): ?string
    {
        $currentImage = $image;
        $currentWidth = $width;
        $currentHeight = $height;

        // Step 1: Step down quality gracefully
        $qualitySteps = [85, 80, 75, 70, 65, 60, 50];
        foreach ($qualitySteps as $q) {
            ob_start();
            imagewebp($currentImage, null, $q);
            $data = ob_get_clean();

            if ($data && strlen($data) <= $targetBytes) {
                return $data;
            }
        }

        // Step 2: Scale down slightly (15% per pass) if still over target budget
        for ($i = 0; $i < 3; $i++) {
            $newMaxDim = (int) round(max($currentWidth, $currentHeight) * 0.85);
            if ($newMaxDim < 150) {
                break;
            }

            $currentImage = $this->resizeImage($currentImage, $currentWidth, $currentHeight, $newMaxDim);
            $currentWidth = imagesx($currentImage);
            $currentHeight = imagesy($currentImage);

            foreach ([75, 65, 50] as $q) {
                ob_start();
                imagewebp($currentImage, null, $q);
                $data = ob_get_clean();

                if ($data && strlen($data) <= $targetBytes) {
                    return $data;
                }
            }
        }

        ob_start();
        imagewebp($currentImage, null, 50);
        return ob_get_clean() ?: null;
    }

    /**
     * Fix EXIF orientation for JPEG photos.
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
            // Ignore EXIF parsing error
        }

        return $image;
    }
}
