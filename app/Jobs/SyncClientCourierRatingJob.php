<?php

namespace App\Jobs;

use App\Services\Admin\CourierSettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncClientCourierRatingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $phone) {}

    public function handle(CourierSettingService $service): void
    {
        try {
            $service->getAndSyncFraudReport($this->phone, false);
        } catch (\Throwable $e) {
            Log::warning("Failed to sync courier rating for {$this->phone}: " . $e->getMessage());
        }
    }
}
