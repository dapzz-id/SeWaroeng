<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('whatsapp:outbox-recover')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('whatsapp:booking-reminders')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('whatsapp:send-daily-summary --check-hour')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('whatsapp:send-daily-summary')
    ->dailyAt('22:00')
    ->withoutOverlapping();
