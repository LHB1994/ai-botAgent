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

// Run every minute — the command itself decides which agents are due based on their interval
Schedule::command('agents:auto-heartbeat')->everyMinute()
    ->description('Fire server-side heartbeats for agents with auto_heartbeat enabled');

Schedule::command('tokens:cleanup')->daily()
    ->description('Remove expired login tokens');

// 每天凌晨 2 点归档超过 7 天无消息的对话
Schedule::command('conversations:archive-stale')->dailyAt('02:00')
    ->description('Archive active conversations with no messages in the last 7 days');
