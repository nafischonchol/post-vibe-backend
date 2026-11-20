<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorageService
{
    /**
     * Determine active disk: R2 if configured, S3 if configured, otherwise public local storage.
     */
    public function getDisk(): string
    {
        if (config('filesystems.disks.r2.key')) {
            return 'r2';
        }

        return config('filesystems.disks.s3.key') ? 's3' : 'public';
    }

    /**
     * Determine disk where a specific path exists.
     */
    public function getDiskForPath(?string $path = null): string
    {
        if ($path) {
            if (Storage::disk('public')->exists($path)) {
                return 'public';
            }
            if (config('filesystems.disks.r2.key') && Storage::disk('r2')->exists($path)) {
                return 'r2';
            }
            if (config('filesystems.disks.s3.key') && Storage::disk('s3')->exists($path)) {
                return 's3';
            }
        }

        return $this->getDisk();
    }

    /**
     * Store raw or streamed video binary into temporary media path.
     */
    public function storeVideo(int $tenantId, string $filename, mixed $contents): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'mp4';
        $safeName = Str::uuid() . '.' . $extension;
        $path = "tenants/{$tenantId}/videos/{$safeName}";

        Storage::disk($this->getDisk())->put($path, $contents);

        return $path;
    }

    /**
     * Get accessible URL (signed URL for S3/R2, public asset URL for local).
     */
    public function getUrl(string $path, int $expirationMinutes = 60): string
    {
        $disk = $this->getDiskForPath($path);

        if ($disk === 'r2') {
            if (config('filesystems.disks.r2.url')) {
                return rtrim(config('filesystems.disks.r2.url'), '/') . '/' . ltrim($path, '/');
            }
            return Storage::disk('r2')->temporaryUrl($path, now()->addMinutes($expirationMinutes));
        }

        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes($expirationMinutes));
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Get binary content of stored media for uploading to external platform APIs.
     */
    public function getVideoBinary(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $disk = $this->getDiskForPath($path);
        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->get($path);
        }

        return null;
    }

    /**
     * Delete temporary media file once publishing lifecycle is terminal.
     */
    public function deleteTemporaryMedia(string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        $disk = $this->getDiskForPath($path);
        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }

        return false;
    }
}
