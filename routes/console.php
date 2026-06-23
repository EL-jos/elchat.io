<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');



Schedule::command('youtube:sync-comments')
    ->everyFiveMinutes()        // ✅ pas everyMinute() — voir quota ci-dessous
    ->withoutOverlapping(600)   // verrou 10 min — couvre les syncs longs
    ->onOneServer()             // ✅ évite les doublons si plusieurs workers
    ->runInBackground();
