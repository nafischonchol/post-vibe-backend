<?php

namespace App\Console\Commands;

use App\Models\BlogCuratedLog;
use App\Models\BlogSource;
use App\Services\Admin\BlogCurationService;
use Illuminate\Console\Command;

class AutoCurateBlogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blog:auto-curate
                            {--source-id= : Specific BlogSource ID to curate from}
                            {--url= : Direct article or blog URL to curate right now}
                            {--limit=3 : Max articles to process per source}
                            {--force : Force processing even if previously logged}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically fetch articles via r.jina.ai, rewrite to user-friendly informative Bangla with OpenRouter AI, and publish to the blog';

    public function handle(BlogCurationService $curationService): int
    {
        $this->info('Starting automated blog curation pipeline...');

        $directUrl = $this->option('url');
        $sourceId = $this->option('source-id') ? (int) $this->option('source-id') : null;
        $limit = (int) $this->option('limit') ?: 3;
        $force = (bool) $this->option('force');

        // Case 1: Direct single URL provided via CLI
        if (! empty($directUrl)) {
            $this->info("Curating single article URL: {$directUrl}");
            if ($force) {
                $hash = BlogCuratedLog::makeHash($directUrl);
                BlogCuratedLog::where('original_url_hash', $hash)->delete();
            }

            try {
                $post = $curationService->curateArticle($directUrl);
                if ($post) {
                    $this->info("Successfully curated and published post #{$post->id}: {$post->title} ({$post->slug})");
                    return Command::SUCCESS;
                }
                $this->warn('Article was skipped or could not be curated.');
                return Command::FAILURE;
            } catch (\Throwable $e) {
                $this->error("Error curating URL [{$directUrl}]: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Case 2: Curating from registered BlogSource records
        $this->info("Fetching active blog sources (Limit per source: {$limit})...");

        $result = $curationService->runCuration($sourceId, $limit, $force);

        if (! empty($result['limit_reached'])) {
            $this->warn($result['message'] ?? 'Daily AI blog post limit reached for today.');
        }

        $this->table(
            ['Sources Checked', 'Articles Published', 'Skipped (Duplicate/Exist)', 'Failed'],
            [[$result['sources_checked'], $result['processed'], $result['skipped'], $result['failed']]]
        );

        if (! empty($result['details'])) {
            $rows = [];
            foreach ($result['details'] as $detail) {
                $rows[] = [
                    $detail['source_id'],
                    $detail['source_name'],
                    $detail['articles_found'],
                    $detail['articles_processed'],
                    empty($detail['errors']) ? 'None' : count($detail['errors']) . ' error(s)',
                ];
            }
            $this->table(['Source ID', 'Source Name/URL', 'Candidates Found', 'Published', 'Errors'], $rows);
        }

        $this->info('Blog curation run completed successfully!');
        return Command::SUCCESS;
    }
}
