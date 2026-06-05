<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Воркер напоминаний о сроках БП: раз в сутки утром. Идемпотентно — повторный запуск безопасен.
Schedule::command('tasks:generate')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();
