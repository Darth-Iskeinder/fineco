<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Открыть или закрыть страницу «Закрытие месяца» руководителю фирмы.
 *
 * Без --on и --off только показывает, как сейчас: случайный запуск ничего не трогает.
 * Вендору, зашедшему в фирму, страница видна всегда, флаг его не касается.
 */
class MonthClosingAccess extends Command
{
    protected $signature = 'month-closing:access
        {--tenant= : Фирма (id)}
        {--on : Открыть страницу руководителю}
        {--off : Закрыть страницу руководителю}
        {--taxes-on : Показывать строку «Налоги и отчетность»}
        {--taxes-off : Скрыть строку «Налоги и отчетность»}';

    protected $description = 'Показать или поменять, видит ли руководитель фирмы «Закрытие месяца»';

    public function handle(): int
    {
        $id = (int) $this->option('tenant');

        if (!$id) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        if ($this->option('on') && $this->option('off')) {
            $this->error('Выберите что-то одно: --on или --off');

            return self::FAILURE;
        }

        if ($this->option('taxes-on') && $this->option('taxes-off')) {
            $this->error('Выберите что-то одно: --taxes-on или --taxes-off');

            return self::FAILURE;
        }

        $tenant = Tenant::find($id);

        if (!$tenant) {
            $this->error("Фирмы {$id} нет");

            return self::FAILURE;
        }

        if ($this->option('on') || $this->option('off')) {
            $tenant->setMonthClosingEnabled((bool) $this->option('on'));
        }

        if ($this->option('taxes-on') || $this->option('taxes-off')) {
            $tenant->setMonthClosingTaxesEnabled((bool) $this->option('taxes-on'));
        }

        $this->line(sprintf(
            'Фирма %d «%s»: «Закрытие месяца» руководителю %s, строка «Налоги и отчетность» %s',
            $tenant->id,
            $tenant->name,
            $tenant->monthClosingEnabled() ? 'открыто' : 'закрыто',
            $tenant->monthClosingTaxesEnabled() ? 'показана' : 'скрыта',
        ));

        return self::SUCCESS;
    }
}
