<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Открыть или закрыть страницу автоаудита руководителю фирмы.
 *
 * Без --on и --off только показывает, как сейчас: команда меняет то, что видят люди
 * в фирме, и случайный запуск ничего не должен трогать. Повторный --on или --off
 * безвреден.
 *
 * Вендору, зашедшему в фирму, страница видна всегда, флаг его не касается.
 */
class AutoAuditAccess extends Command
{
    protected $signature = 'autoaudit:access
        {--tenant= : Фирма (id)}
        {--on : Открыть страницу руководителю}
        {--off : Закрыть страницу руководителю}';

    protected $description = 'Показать или поменять, видит ли руководитель фирмы страницу автоаудита';

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
            $tenant->setAutoAuditEnabled((bool) $this->option('on'));
        }

        $this->line(sprintf(
            'Фирма %d «%s»: автоаудит руководителю %s',
            $tenant->id,
            $tenant->name,
            $tenant->autoAuditEnabled() ? 'открыт' : 'закрыт',
        ));

        return self::SUCCESS;
    }
}
