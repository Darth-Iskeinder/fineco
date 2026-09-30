<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Открыть или закрыть страницу автоаудита руководителю фирмы.
 *
 * --findings-on и --findings-off отдельно открывают руководителю ответы по находкам и
 * кнопки «Принять» и «Не принято». Без открытой страницы от них толку нет.
 *
 * --from=2026-09 задаёт первый отчётный месяц, который автоаудит проверяет в фирме, --from=all
 * снимает ограничение. Ставится до первого прогона у фирмы, подключённой не с начала.
 *
 * --nightly-on включает фирму в ночной прогон `autoaudit:run --all`. Порядок подключения:
 * разметить эталонные БП, задать --from, пробный прогон фирмы, и только потом --nightly-on.
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
        {--off : Закрыть страницу руководителю}
        {--findings-on : Показать руководителю ответы по находкам}
        {--findings-off : Спрятать от руководителя ответы по находкам}
        {--from= : С какого отчётного месяца проверять фирму, 2026-09; all = все месяцы}
        {--nightly-on : Включить фирму в ночной прогон (autoaudit:run --all)}
        {--nightly-off : Убрать фирму из ночного прогона}';

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

        if ($this->option('findings-on') && $this->option('findings-off')) {
            $this->error('Выберите что-то одно: --findings-on или --findings-off');

            return self::FAILURE;
        }

        if ($this->option('nightly-on') && $this->option('nightly-off')) {
            $this->error('Выберите что-то одно: --nightly-on или --nightly-off');

            return self::FAILURE;
        }

        $from = $this->option('from');

        if ($from !== null && $from !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $from)) {
            $this->error('Месяц пишется так: --from=2026-09, или --from=all для всех месяцев');

            return self::FAILURE;
        }

        $tenant = Tenant::find($id);

        if (!$tenant) {
            $this->error("Фирмы {$id} нет");

            return self::FAILURE;
        }

        if ($from !== null) {
            $tenant->setAutoAuditFrom($from === 'all' ? null : $from);
        }

        if ($this->option('nightly-on') || $this->option('nightly-off')) {
            $tenant->setAutoAuditNightly((bool) $this->option('nightly-on'));
        }

        if ($this->option('on') || $this->option('off')) {
            $tenant->setAutoAuditEnabled((bool) $this->option('on'));
        }

        if ($this->option('findings-on') || $this->option('findings-off')) {
            $tenant->setAutoAuditFindingsEnabled((bool) $this->option('findings-on'));
        }

        $this->line(sprintf(
            'Фирма %d «%s»: автоаудит руководителю %s',
            $tenant->id,
            $tenant->name,
            $tenant->autoAuditEnabled() ? 'открыт' : 'закрыт',
        ));
        $this->line('Ответы по находкам руководителю: ' . ($tenant->autoAuditFindingsEnabled() ? 'видны' : 'скрыты'));
        $this->line('Проверяет отчёты: ' . ($tenant->autoAuditFrom() ? 'с ' . $tenant->autoAuditFrom() : 'за все месяцы'));
        $this->line('Ночной прогон: ' . ($tenant->autoAuditNightly() ? 'включён' : 'выключен'));

        return self::SUCCESS;
    }
}
