<?php

namespace App\Jobs;

use App\Models\SearchLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LogSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $query,
        public int $resultsCount = 0,
        public string $source = 'autocomplete',
        public ?string $visitorId = null,
        public ?int $clientId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $rawQuery = trim($this->query);
        if ($rawQuery === '' || mb_strlen($rawQuery) < 2) {
            return;
        }

        // Normalize: trim, lowercase, collapse multiple spaces
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $rawQuery));

        // Smart prefix squashing for the same visitor or IP in a live session (90s window)
        $queryBuilder = SearchLog::where('created_at', '>=', now()->subSeconds(90))
            ->whereNull('clicked_product_id')
            ->latest('id');

        if ($this->visitorId) {
            $queryBuilder->where('visitor_id', $this->visitorId);
        } elseif ($this->ipAddress) {
            $queryBuilder->where('ip_address', $this->ipAddress);
        } else {
            $queryBuilder = null;
        }

        if ($queryBuilder) {
            $recentLog = $queryBuilder->first();
            if ($recentLog) {
                $prevNorm = $recentLog->normalized_query;
                // If user is progressively typing (e.g., 'facew' -> 'facewash'), backspacing, or minor typo change
                if (
                    str_starts_with($normalized, $prevNorm) ||
                    str_starts_with($prevNorm, $normalized) ||
                    (strlen($normalized) > 3 && strlen($prevNorm) > 3 && levenshtein($normalized, $prevNorm) <= 2)
                ) {
                    $recentLog->update([
                        'query' => mb_substr($rawQuery, 0, 255),
                        'normalized_query' => mb_substr($normalized, 0, 255),
                        'results_count' => max(0, $this->resultsCount),
                        'source' => $this->source,
                        'client_id' => $this->clientId ?? $recentLog->client_id,
                        'ip_address' => $this->ipAddress ? mb_substr($this->ipAddress, 0, 45) : $recentLog->ip_address,
                        'user_agent' => $this->userAgent ? mb_substr($this->userAgent, 0, 1000) : $recentLog->user_agent,
                    ]);

                    return;
                }
            }
        }

        SearchLog::create([
            'query' => mb_substr($rawQuery, 0, 255),
            'normalized_query' => mb_substr($normalized, 0, 255),
            'results_count' => max(0, $this->resultsCount),
            'source' => $this->source,
            'visitor_id' => $this->visitorId ? mb_substr($this->visitorId, 0, 100) : null,
            'client_id' => $this->clientId,
            'ip_address' => $this->ipAddress ? mb_substr($this->ipAddress, 0, 45) : null,
            'user_agent' => $this->userAgent ? mb_substr($this->userAgent, 0, 1000) : null,
        ]);
    }
}
