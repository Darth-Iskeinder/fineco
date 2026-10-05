<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Воркер напоминаний о сроках БП: раз в сутки утром. Идемпотентно — повторный запуск безопасен.
// Часовой пояс явно: приложение и сервер живут по UTC, без него 06:00 было бы 12:00 по Бишкеку.
//
// Автоаудита здесь нет намеренно: ему нужен www-data, а schedule:run идёт от client. Он стоит
// отдельной строкой в crontab www-data на сервере, см. RunAutoAudit.
Schedule::command('tasks:generate')
    ->timezone('Asia/Bishkek')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

// Вечерняя пауза забытых таймеров БухЗадачника: время засчитывается до 20:00, дальше таймер
// считается забытым. Выходные так же. Повторный запуск безопасен.
Schedule::command('buhtasks:stop-forgotten-timers --apply')
    ->timezone('Asia/Bishkek')
    ->dailyAt('20:00')
    ->withoutOverlapping()
    ->onOneServer();
