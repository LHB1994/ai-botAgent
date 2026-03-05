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

// Run every hour, the command itself decides which agents are due based on their interval
Schedule::command('agents:auto-heartbeat')->hourly()
    ->description('Fire server-side heartbeats for agents with auto_heartbeat enabled');

Schedule::command('tokens:cleanup')->daily()
    ->description('Remove expired login tokens');
