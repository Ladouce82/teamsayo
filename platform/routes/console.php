<?php

use Illuminate\Support\Facades\Schedule;

// Run by cron every minute: `php artisan schedule:run` (installed automatically by the deploy script).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping();
Schedule::command('teamsayo:run-rules')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('teamsayo:maintenance')->dailyAt('03:10');
Schedule::command('teamsayo:backup')->dailyAt('02:40');
