<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Открыть или закрыть раздел «Расчёты с клиентами» руководителю фирмы.
 *
 * Без --on и --off только показывает, как сейчас: случайный запуск ничего не трогает.
 * Вендору, зашедшему в фирму, раздел виден всегда, флаг его не касается.
 */
class SettlementsAccess extends Command
{
    protected $signature = 'settlements:access
        {--tenant= : Фирма (id)}
        {--on : Открыть раздел руководителю}
        {--off : Закрыть раздел руководителю}';

    protected $description = 'Показать или поменять, видит ли руководитель фирмы «Расчёты с клиентами»';

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
            $tenant->setSettlementsEnabled((bool) $this->option('on'));
        }

        $this->line(sprintf(
            'Фирма %d «%s»: «Расчёты с клиентами» руководителю %s',
            $tenant->id,
            $tenant->name,
            $tenant->settlementsEnabled() ? 'открыты' : 'закрыты',
        ));

        return self::SUCCESS;
    }
}
