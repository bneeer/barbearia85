<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:reminders')->everyMinute()->withoutOverlapping();
Schedule::command('finance:generate-recurring-expenses')->dailyAt('01:00')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
