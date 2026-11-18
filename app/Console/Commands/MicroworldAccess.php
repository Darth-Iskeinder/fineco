<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Открыть или закрыть «Микромир» сотрудникам фирмы.
 *
 * Без --on и --off только показывает, как сейчас: случайный запуск ничего не трогает.
 * Вендору, зашедшему в фирму, страница видна всегда, флаг его не касается.
 */
class MicroworldAccess extends Command
{
    protected $signature = 'microworld:access
        {--tenant= : Фирма (id)}
        {--on : Открыть страницу сотрудникам}
        {--off : Закрыть страницу сотрудникам}';

    protected $description = 'Показать или поменять, видят ли сотрудники фирмы «Микромир»';

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
            $tenant->setMicroworldEnabled((bool) $this->option('on'));
        }

        $this->line(sprintf(
            'Фирма %d «%s»: «Микромир» сотрудникам %s',
            $tenant->id,
            $tenant->name,
            $tenant->microworldEnabled() ? 'открыт' : 'закрыт',
        ));

        return self::SUCCESS;
    }
}
