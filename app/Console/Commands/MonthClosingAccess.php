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
        {--off : Закрыть страницу руководителю}';

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

        $tenant = Tenant::find($id);

        if (!$tenant) {
            $this->error("Фирмы {$id} нет");

            return self::FAILURE;
        }

        if ($this->option('on') || $this->option('off')) {
            $tenant->setMonthClosingEnabled((bool) $this->option('on'));
        }

        $this->line(sprintf(
            'Фирма %d «%s»: «Закрытие месяца» руководителю %s',
            $tenant->id,
            $tenant->name,
            $tenant->monthClosingEnabled() ? 'открыто' : 'закрыто',
        ));

        return self::SUCCESS;
    }
}
