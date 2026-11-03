<?php

namespace App\Traits;

use App\Jobs\OptimizeImageJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait UploadAble
{
    public function uploadFile(UploadedFile $file, string $folder, ?string $disk = null, bool $optimize = true): string|bool
    {
        $disk = $disk ?? config('filesystems.default');

        $path = $file->store($folder, $disk);

        if ($path && $optimize) {
            OptimizeImageJob::dispatch($path, $disk)->afterCommit();
        }

        return $path;
    }

    public function deleteFile(?string $path, ?string $disk = null): bool
    {
        $disk = $disk ?? config('filesystems.default');
        if (! $path) {
            return false;
        }

        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }

        // If file was converted to .webp, check and delete webp version
        $webpPath = preg_replace('/\.[^.]+$/', '.webp', $path);
        if ($webpPath && $webpPath !== $path && Storage::disk($disk)->exists($webpPath)) {
            return Storage::disk($disk)->delete($webpPath);
        }

        return false;
    }
}
