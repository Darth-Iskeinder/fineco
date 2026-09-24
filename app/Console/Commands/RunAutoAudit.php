<?php

namespace App\Console\Commands;

use App\Jobs\RunAutoAuditJob;
use App\Models\AutoAuditResult;
use App\Services\AutoAudit\AutoAuditRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Прогон автоаудита по одной фирме из терминала.
 *
 * Единственный способ запустить проверку: кнопки на странице больше нет. Прошлые
 * результаты не стирает: дописывает только то, где итог поменялся, остальное остаётся
 * историей. По крону не запускается намеренно, прогон руками.
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

        // Быстрая проверка до начала работы. Её одной мало: на бою корень диска после chown
        // принадлежит client и читается, а закрыты вложенные папки задач. Их проверяет сам
        // прогон на каждом файле и падает, ничего не записав.
        $documents = Storage::disk('local')->path('');

        if (!is_readable($documents) || !is_executable($documents)) {
            $this->error("Нет доступа к папке документов {$documents}");
            $this->line("Запускайте от www-data: sudo -u www-data php artisan autoaudit:run --tenant={$tenant}");
            $this->line('Прошлые результаты не тронуты');

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $counts = RunAutoAuditJob::perform($tenant, $runner);
        } catch (Throwable $e) {
            // Состояние «упала» и журнал сбоев perform уже записал, здесь только сказать.
            $this->error('Проверка не прошла: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($counts === null) {
            $this->error('По этой фирме уже идёт проверка: второй прогон не начат');

            return self::FAILURE;
        }

        foreach (AutoAuditResult::LABELS as $outcome => $label) {
            // str_pad считает байты, а не буквы: с кириллицей столбцы разъезжаются.
            $this->line($label . ':' . str_repeat(' ', max(1, 21 - mb_strlen($label))) . ($counts[$outcome] ?? 0));
        }

        $changes = $runner->changes();

        $this->line(sprintf(
            'Без изменений %d, сменилось %d, новых %d, ушло %d',
            $changes['kept'] ?? 0, $changes['changed'] ?? 0, $changes['added'] ?? 0, $changes['gone'] ?? 0,
        ));
        $findings = $runner->findingChanges();

        $this->line(sprintf(
            'Находки: открыто %d, закрыто %d, всего открытых %d',
            $findings['opened'] ?? 0, $findings['closed'] ?? 0, $findings['open'] ?? 0,
        ));
        $this->line(sprintf('Заняло %.1f с', microtime(true) - $started));

        return self::SUCCESS;
    }
}
