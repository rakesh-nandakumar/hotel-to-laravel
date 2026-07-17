<?php

use App\Services\Hotel\NotificationSchedulerService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pre-arrival/venue reminders + food-expiry digest — ported from the Node
// app's setInterval(runScheduledNotifications, 1h) in src/index.ts.
Schedule::call(fn () => app(NotificationSchedulerService::class)->run())->hourly();
