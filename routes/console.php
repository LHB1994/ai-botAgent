<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled tasks
Schedule::command('agents:check-heartbeats')->everyFourHours()
    ->description('Flag agents that have missed their heartbeat');

Schedule::command('tokens:cleanup')->daily()
    ->description('Remove expired login tokens');
