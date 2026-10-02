<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Services\AutoAudit\AutoAuditSources;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Свои счета ОСВ у проверок автоаудита в фирме.
 *
 * Зачем: у КЛВ Эксперт единый налог лежит и на 3410, и на 3490, а у Fineco только на 3410.
 * С одним общим счётом у КЛВ по №3 вышли бы ложные «Не совпало». Фирме задают свой список,
 * обороты по нему складываются (AutoAuditRunner::accountsFor).
 *
 * Пока на странице «Настройки → Автоаудит» нет правки, счета задаются здесь.
 *
 *   autoaudit:accounts --tenant=4                         как сейчас, все проверки
 *   autoaudit:accounts --tenant=4 --rule=3                одна проверка и где её счета есть в ведомостях
 *   autoaudit:accounts --tenant=4 --rule=3 --set=3410,3490   показать, что изменится
 *   ... --apply                                            сохранить
 *   autoaudit:accounts --tenant=4 --rule=3 --reset --apply   вернуть общий счёт
 *
 * Перед сохранением команда читает ведомости фирмы за последние месяцы и считает, у
 * скольких клиентов каждый счёт есть. Счёт, которого нет ни у кого, скорее всего опечатка:
 * такой сохраняется только с --force. Файлы документов читает только веб-сервер, поэтому
 * запускать от www-data.
 */
class AutoAuditAccounts extends Command
{
    protected $signature = 'autoaudit:accounts
        {--tenant= : Фирма (id)}
        {--rule= : Номер проверки}
        {--set= : Счета через запятую, например 3410,3490}
        {--reset : Вернуть общий счёт проверки}
        {--apply : Сохранить; без флага только показать}
        {--force : Сохранить, даже если счёта нет ни в одной ведомости}';

    protected $description = 'Показать или задать фирме свои счета ОСВ у проверок автоаудита';

    /** За сколько последних месяцев смотреть ведомости, проверяя счета. */
    private const COVERAGE_MONTHS = 3;

    public function handle(): int
    {
        $tenant = Tenant::find((int) $this->option('tenant'));

        if (!$tenant) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        $rule = $this->option('rule');

        if ($rule === null) {
            if ($this->option('set') !== null || $this->option('reset')) {
                $this->error('Укажите проверку: --rule=3');

                return self::FAILURE;
            }

            $this->overview($tenant);

            return self::SUCCESS;
        }

        if (!ctype_digit((string) $rule) || !isset(AutoAuditRunner::RULES[(int) $rule])) {
            $this->error('Нет такой проверки. Номера: ' . implode(', ', array_keys(AutoAuditRunner::RULES)));

            return self::FAILURE;
        }

        return TenantContext::for($tenant, fn () => $this->rule($tenant, (int) $rule));
    }

    /** Все проверки фирмы: общий счёт, свой, кто и когда задал. */
    private function overview(Tenant $tenant): void
    {
        $own = $tenant->autoAuditAccounts();

        $this->line("<info>Фирма:</info> {$tenant->name}");
        $this->table(
            ['№', 'Проверка', 'Общий счёт', 'Счета фирмы', 'Кто и когда'],
            array_map(fn (int $number) => [
                $number,
                AutoAuditRunner::RULES[$number]['name'],
                AutoAuditRunner::RULES[$number]['account'],
                isset($own[$number]) ? implode(', ', $own[$number]['accounts']) : 'общий',
                isset($own[$number]) ? trim(($own[$number]['by'] ?? '') . ', ' . ($own[$number]['at'] ?? ''), ', ') : '',
            ], array_keys(AutoAuditRunner::RULES)),
        );
    }

    private function rule(Tenant $tenant, int $rule): int
    {
        $default = AutoAuditRunner::RULES[$rule]['account'];
        $current = AutoAuditRunner::accountsFor($rule, $tenant);
        $target  = $this->target($rule, $default);

        if ($target === false) {
            return self::FAILURE;
        }

        $this->line("<info>Фирма:</info> {$tenant->name}");
        $this->line("<info>Проверка №{$rule}:</info> " . AutoAuditRunner::RULES[$rule]['name']);
        $this->line('Общий счёт: ' . $default);
        $this->line('Сейчас: ' . implode(', ', $current));

        if ($target !== null) {
            $this->line('Станет: ' . implode(', ', $target ?: [$default]) . ($target ? '' : ' (общий)'));
        }

        // Проверяем то, что будет действовать после сохранения; без --set то, что действует сейчас.
        $checked = $target === null ? $current : ($target ?: [$default]);

        try {
            $coverage = $this->coverage($checked);
        } catch (RuntimeException $e) {
            // Нет прав на файлы: запуск не от веб-сервера. Без проверки не сохраняем.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $unseen = $this->printCoverage($coverage, $checked);

        if ($target === null) {
            return self::SUCCESS;
        }

        if ($target === $current || (!$target && $current === [$default])) {
            $this->info('Уже так, менять нечего.');

            return self::SUCCESS;
        }

        if (!$this->option('apply')) {
            $this->newLine();
            $this->line('Ничего не сохранено. Сохранить: добавьте --apply');

            return self::SUCCESS;
        }

        // Общий счёт не проверяем на опечатку: его выбирали не здесь.
        if ($target && $unseen && !$this->option('force')) {
            $this->error('Счёт ' . implode(', ', $unseen) . ' не встречается ни в одной ведомости. '
                . 'Проверьте номер. Если уверены, добавьте --force');

            return self::FAILURE;
        }

        $tenant->setAutoAuditAccounts($rule, $target ?: null, 'вендор');

        $this->info('Сохранено.');
        $this->line("Следующий прогон пересчитает проверку №{$rule} по всем месяцам с её старта.");

        return self::SUCCESS;
    }

    /**
     * Что задать: список счетов, [] для общего счёта, null если только смотрим,
     * false при ошибке ввода.
     *
     * @return string[]|null|false
     */
    private function target(int $rule, string $default): array|null|false
    {
        $set   = $this->option('set');
        $reset = (bool) $this->option('reset');

        if ($set !== null && $reset) {
            $this->error('Выберите что-то одно: --set или --reset');

            return false;
        }

        if ($reset) {
            return [];
        }

        if ($set === null) {
            return null;
        }

        $accounts = array_values(array_unique(preg_split('/[\s,;]+/u', trim($set), -1, PREG_SPLIT_NO_EMPTY)));

        if (!$accounts) {
            $this->error('Пустой список счетов. Вернуть общий счёт: --reset');

            return false;
        }

        foreach ($accounts as $account) {
            if (!preg_match('/^\d{2,6}(\.\d{1,3})?$/', $account)) {
                $this->error("«{$account}» не похоже на счёт ОСВ: нужны цифры, например 3410");

                return false;
            }
        }

        // Один общий счёт это и есть «по умолчанию»: храним его как отсутствие настройки.
        return $accounts === [$default] ? [] : $accounts;
    }

    /**
     * У скольких клиентов фирмы счёт есть в ведомостях за последние месяцы.
     *
     * @param string[] $accounts
     * @return array{total: int, with: array<string, int>}|null null, если в фирме не отмечен БП ведомости
     */
    private function coverage(array $accounts): ?array
    {
        $service = Service::where('reference_id', AutoAuditRunner::REF_BALANCE_SHEET)->first();

        if (!$service) {
            return null;
        }

        $sources = app(AutoAuditSources::class);
        $since   = CarbonImmutable::now()->startOfMonth()->subMonths(self::COVERAGE_MONTHS);
        $total   = 0;
        $with    = array_fill_keys($accounts, 0);

        foreach (Client::orderBy('id')->get() as $client) {
            $documents = $sources->documents($client, $service)->filter(
                fn (array $pair) => CarbonImmutable::create($pair[0]->year, $pair[0]->month, 1)->gte($since),
            );

            if ($documents->isEmpty()) {
                continue;
            }

            $total++;

            foreach ($accounts as $account) {
                foreach ($documents as [, $document]) {
                    if ($sources->read($client, 'osv', $account, $document)->isFound()) {
                        $with[$account]++;

                        break;
                    }
                }
            }
        }

        return ['total' => $total, 'with' => $with];
    }

    /**
     * @param string[] $accounts
     * @return string[] счета, которых нет ни в одной ведомости
     */
    private function printCoverage(?array $coverage, array $accounts): array
    {
        $this->newLine();

        if ($coverage === null) {
            $this->warn('В фирме не отмечен БП ведомости, проверить счета не по чему.');

            return [];
        }

        if ($coverage['total'] === 0) {
            $this->warn('За последние ' . self::COVERAGE_MONTHS . ' месяца ведомостей нет, проверить счета не по чему.');

            return [];
        }

        $this->line('В ведомостях за последние ' . self::COVERAGE_MONTHS . ' месяца:');

        foreach ($accounts as $account) {
            $this->line("  {$account}: есть у {$coverage['with'][$account]} клиентов из {$coverage['total']}");
        }

        return array_values(array_keys(array_filter($coverage['with'], fn (int $n) => $n === 0)));
    }
}
