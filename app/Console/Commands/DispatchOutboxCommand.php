<?php

namespace App\Console\Commands;

use App\Jobs\Publishing\UploadToPlatformJob;
use App\Models\PublishingBatch;
use App\Models\TransactionalOutbox;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100} {--watch} {--interval=3}';
    protected $description = 'Poll pending transactional outbox events and dispatch queue jobs reliably';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $watch = (bool) $this->option('watch');
        $interval = max(1, (int) $this->option('interval'));

        if ($watch) {
            $this->info("Outbox worker listening for pending events every {$interval}s...");
        }

        do {
            $this->dispatchCycle($limit);
            if ($watch) {
                sleep($interval);
            }
        } while ($watch);

        return Command::SUCCESS;
    }

    protected function dispatchCycle(int $limit): void
    {
        $events = DB::transaction(function () use ($limit) {
            $pendingEvents = TransactionalOutbox::where('status', 'pending')
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($pendingEvents->isEmpty()) {
                return $pendingEvents;
            }

            // Atomically mark events as processing inside the transaction so concurrent workers cannot claim them
            TransactionalOutbox::whereIn('id', $pendingEvents->pluck('id'))
                ->update(['status' => 'processing']);

            return $pendingEvents;
        });

        if ($events->isEmpty()) {
            return;
        }

        $this->info("Processing {$events->count()} outbox events...");

        foreach ($events as $event) {
            try {
                if ($event->event_type === 'batch_publishing_due') {
                    $batchId = $event->payload['batch_id'] ?? null;
                    $batch = PublishingBatch::with('schedules')->find($batchId);

                    if ($batch) {
                        $batch->update(['batch_status' => 'processing']);

                        foreach ($batch->schedules as $schedule) {
                            if (in_array($schedule->upload_status, ['pending', 'scheduled'])) {
                                $schedule->update(['upload_status' => 'queued']);
                                UploadToPlatformJob::dispatch($schedule)->onQueue('publishing');
                            }
                        }
                    }
                }

                $event->update([
                    'status' => 'dispatched',
                    'published_at' => now(),
                ]);

            } catch (Exception $e) {
                Log::error("Outbox dispatch failed for event ID {$event->id}: " . $e->getMessage());

                $event->update([
                    'status' => 'failed',
                    'attempts' => $event->attempts + 1,
                    'last_error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Outbox dispatch cycle completed.');
    }
}
