<?php

namespace App\Jobs;

use App\Models\Combo;
use App\Contracts\AiServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateComboSlugWithAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $comboId,
        public bool $forceRegenerate = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AiServiceInterface $aiService): void
    {
        $combo = Combo::find($this->comboId);
        if (! $combo) {
            return;
        }

        // If combo already has a custom slug and we are not forcing regeneration, skip
        if (! $this->forceRegenerate && ! empty($combo->slug)) {
            return;
        }

        $combo->loadMissing(['items.product']);
        $productNames = $combo->items
            ->map(fn ($item) => $item->product?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        try {
            $generatedSlug = $aiService->generateComboSlug(
                name: $combo->name,
                banglaName: $combo->bangla_name,
                shortDescription: $combo->short_description,
                bundledProducts: $productNames
            );

            // Ensure uniqueness within combos table
            $finalSlug = $this->ensureUniqueSlug($generatedSlug, $combo->id);

            $combo->slug = $finalSlug;
            $combo->saveQuietly();

            Log::info("AI successfully generated slug for Combo #{$combo->id}: '{$finalSlug}'");
        } catch (\Exception $e) {
            Log::error("Failed to generate AI slug for Combo #{$combo->id}: " . $e->getMessage());

            // Fallback to basic Str::slug if not already set
            if (empty($combo->slug)) {
                $combo->slug = $this->ensureUniqueSlug(Str::slug($combo->name), $combo->id);
                $combo->saveQuietly();
            }
        }
    }

    /**
     * Ensure slug is unique in combos table.
     */
    protected function ensureUniqueSlug(string $slug, int $comboId): string
    {
        $baseSlug = $slug;
        $counter = 1;

        while (Combo::where('slug', $slug)->where('id', '!=', $comboId)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
