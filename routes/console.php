<?php


use Illuminate\Support\Facades\Schedule;

Schedule::command('telescope:prune --hours=1')->everyTwoHours();

// 1. Transactional Outbox Worker: Poll and dispatch due syndication jobs every minute
Schedule::command('outbox:dispatch --limit=100')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// 2. Video Source Scanner: Ingest new short videos based on source scan frequencies
Schedule::command('sources:scan --limit=20')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();

// 3. Social Platform Analytics Synchronizer: Refresh views, likes, comments & engagement
Schedule::command('analytics:sync-platforms --limit=50')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();


