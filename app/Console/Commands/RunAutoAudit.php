<?php

namespace App\Console\Commands;

use App\Models\AutoAuditResult;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Прогон автоаудита по одной фирме из терминала.
 *
 * Делает ровно то же, что кнопка «Проверить сейчас» на странице автоаудита: стирает
 * прошлые результаты фирмы и пишет новые. По крону пока не запускается намеренно:
 * сначала смотрим результат руками.
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
        $counts  = TenantContext::for($tenant, fn () => $runner->run());

        foreach ([
            AutoAuditResult::MATCHED        => 'Совпало',
            AutoAuditResult::MISMATCH       => 'Не совпало',
            AutoAuditResult::WRONG_DOCUMENT => 'Не тот документ',
        ] as $outcome => $label) {
            // str_pad считает байты, а не буквы: с кириллицей столбцы разъезжаются.
            $this->line($label . ':' . str_repeat(' ', max(1, 18 - mb_strlen($label))) . ($counts[$outcome] ?? 0));
        }

        $this->line(sprintf('Заняло %.1f с', microtime(true) - $started));

        return self::SUCCESS;
    }
}
