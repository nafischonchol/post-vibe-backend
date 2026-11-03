<?php


use Illuminate\Support\Facades\Schedule;

Schedule::command('telescope:prune --hours=1')->everyTwoHours();

// Automated Blog Curation via r.jina.ai & OpenRouter AI (every 3 hours)
Schedule::command('blog:auto-curate --limit=2')
    ->everyThreeHours()
    ->withoutOverlapping(60)
    ->runInBackground();

