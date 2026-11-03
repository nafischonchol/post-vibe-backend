<?php

namespace App\Jobs;

use App\Services\Marketing\TikTokEventsApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendTikTokEventJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $eventName,
        public ?string $eventId = null,
        public array $userData = [],
        public array $properties = [],
        public ?string $eventSourceUrl = null,
        public ?int $eventTime = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TikTokEventsApiService $service): void
    {
        $result = $service->sendEvent(
            eventName: $this->eventName,
            eventId: $this->eventId,
            userData: $this->userData,
            properties: $this->properties,
            eventSourceUrl: $this->eventSourceUrl,
            eventTime: $this->eventTime
        );

        if (! empty($result['error'])) {
            throw new \RuntimeException('TikTok Events API Job Failed: '.$result['error']);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("SendTikTokEventJob permanently failed for event '{$this->eventName}' (Event ID: ".($this->eventId ?? 'N/A').'): '.$exception?->getMessage());
    }
}
