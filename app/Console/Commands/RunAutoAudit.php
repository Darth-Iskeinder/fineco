<?php

namespace App\Console\Commands;

use App\Jobs\RunAutoAuditJob;
use App\Models\AutoAuditResult;
use App\Services\AutoAudit\AutoAuditRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Прогон автоаудита по одной фирме из терминала.
 *
 * Единственный способ запустить проверку: кнопки на странице больше нет. Стирает прошлые
 * результаты фирмы и пишет новые. По крону не запускается намеренно, прогон руками.
 *
 * На сервере запускать от www-data: из-под другого пользователя папки документов не
 * читаются, и все строки станут «Файл не открылся».
 *
 * Прогон идёт через RunAutoAuditJob::perform: замок не даёт запустить второй поверх
 * идущего, а состояние в кеше показывает страница.
 */
class RunAutoAudit extends Command
{
    protected $signature = 'autoaudit:run {--tenant= : Фирма (id)}';

    protected $description = 'Прогнать автоаудит по фирме и записать результат';

    public function handle(AutoAuditRunner $runner): int
    {
        $tenant = (int) $this->option('tenant');

        if (!$tenant) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        // Без доступа к файлам прогон не упадёт, а честно запишет всем «Файл не открылся» и
        // сотрёт настоящие результаты. Поэтому не начинаем вовсе.
        $documents = Storage::disk('local')->path('');

        if (!is_readable($documents) || !is_executable($documents)) {
            $this->error("Нет доступа к папке документов {$documents}");
            $this->line("Запускайте от www-data: sudo -u www-data php artisan autoaudit:run --tenant={$tenant}");
            $this->line('Прошлые результаты не тронуты');

            return self::FAILURE;
        }

        $started = microtime(true);
        $counts  = RunAutoAuditJob::perform($tenant, $runner);

        if ($counts === null) {
            $this->error('По этой фирме уже идёт проверка: второй прогон не начат');

            return self::FAILURE;
        }

        foreach (AutoAuditResult::LABELS as $outcome => $label) {
            // str_pad считает байты, а не буквы: с кириллицей столбцы разъезжаются.
            $this->line($label . ':' . str_repeat(' ', max(1, 21 - mb_strlen($label))) . ($counts[$outcome] ?? 0));
        }

        $this->line(sprintf('Заняло %.1f с', microtime(true) - $started));

        return self::SUCCESS;
    }
}
