<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorageService
{
    /**
     * Determine active disk: S3 if configured, otherwise local storage.
     */
    public function getDisk(): string
    {
        return config('filesystems.disks.s3.key') ? 's3' : 'public';
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
     * Get accessible URL (signed URL for S3, public asset URL for local).
     */
    public function getUrl(string $path, int $expirationMinutes = 60): string
    {
        $disk = $this->getDisk();

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

        $disk = $this->getDisk();
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

        $disk = $this->getDisk();
        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }

        return false;
    }
}
