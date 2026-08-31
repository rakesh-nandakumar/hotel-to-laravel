<?php

use App\Services\Apartment\ApartmentLeaseBillingService;
use App\Services\Apartment\ApartmentSalesService;
use App\Services\Hotel\InventoryService;
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

// Auto-writes off every ingredient/product batch that has passed its expiry
// date, so expired stock stops counting as on-hand and can never be sold or
// listed — see InventoryService::autoWriteOffExpiredBatches(). Idempotent
// (already-written-off batches are excluded), safe for catch-up after
// downtime.
Schedule::call(fn () => app(InventoryService::class)->autoWriteOffExpiredBatches())->hourly();

// Posts this month's rent for every active apartment lease — idempotent
// (safe to also run via `php artisan schedule:run` catch-up after downtime),
// see ApartmentLeaseBillingService::generateMonthlyCharges().
Schedule::call(fn () => app(ApartmentLeaseBillingService::class)->generateMonthlyCharges())->monthlyOn(1, '02:00');

// Auto-releases a sale's unit-hold if the buyer never signed by reserved_until
// — see ApartmentSalesService::releaseExpiredHolds().
Schedule::call(fn () => app(ApartmentSalesService::class)->releaseExpiredHolds())->dailyAt('03:00');
