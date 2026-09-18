<?php

namespace App\Console\Commands;

use App\Jobs\RunAutoAuditJob;
use App\Models\AutoAuditResult;
use App\Services\AutoAudit\AutoAuditRunner;
use Illuminate\Console\Command;

/**
 * Прогон автоаудита по одной фирме из терминала.
 *
 * Делает ровно то же, что кнопка «Проверить сейчас» на странице автоаудита: стирает
 * прошлые результаты фирмы и пишет новые. По крону пока не запускается намеренно:
 * сначала смотрим результат руками.
 *
 * Прогон идёт через тот же RunAutoAuditJob::perform, что и кнопка, поэтому у команды тот же
 * замок и то же состояние в кеше. Раньше она не знала ни про то, ни про другое: её можно
 * было запустить поверх идущего прогона, а страница в это время показывала время прошлого.
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
