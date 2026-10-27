<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('feitoo:purge-expired-processings')->hourly()->withoutOverlapping();
Schedule::command('feitoo:expire-pending-purchases')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('feitoo:purge-quotation-drafts')->hourly()->withoutOverlapping();
Schedule::command('feitoo:reconcile-quotation-generations')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
