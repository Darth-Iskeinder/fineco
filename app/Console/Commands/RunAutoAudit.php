<?php

namespace App\Console\Commands;

use App\Jobs\RunAutoAuditJob;
use App\Models\AutoAuditResult;
use App\Models\Client;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Прогон автоаудита из терминала: по одной фирме (--tenant) или по всем фирмам ночного
 * прогона (--all, флаг autoaudit:access --nightly-on).
 *
 * Единственный способ запустить проверку: кнопки на странице больше нет. Прошлые
 * результаты не стирает: дописывает только то, где итог поменялся, остальное остаётся
 * историей.
 *
 * На бою идёт каждую ночь из crontab пользователя www-data одной строкой с --all (до
 * 30.09.2026 была строка на каждую фирму, её приходилось дописывать руками), а не через
 * планировщик в routes/console.php: schedule:run запускает client, а ему sudo -u www-data
 * доступен только с паролем. Руками запускать тоже можно, замок не даст двух прогонов.
 *
 * На сервере запускать от www-data: из-под другого пользователя папки документов не
 * читаются, и все строки станут «Файл не открылся».
 *
 * Прогон идёт через RunAutoAuditJob::perform: замок не даёт запустить второй поверх
 * идущего, а состояние в кеше показывает страница.
 */
class RunAutoAudit extends Command
{
    protected $signature = 'autoaudit:run
        {--tenant= : Фирма (id)}
        {--all : Все фирмы ночного прогона по очереди (autoaudit:access --nightly-on)}
        {--dry-run : Посчитать и показать, что изменится, ничего не записывая}';

    protected $description = 'Прогнать автоаудит по фирме или по всем фирмам ночного прогона и записать результат';

    public function handle(AutoAuditRunner $runner): int
    {
        $tenant = (int) $this->option('tenant');
        $all    = (bool) $this->option('all');

        if ($all && $tenant) {
            $this->error('Выберите что-то одно: --tenant или --all');

            return self::FAILURE;
        }

        if (!$all && !$tenant) {
            $this->error('Укажите фирму: --tenant=1, или --all для всех фирм ночного прогона');

            return self::FAILURE;
        }

        // Быстрая проверка до начала работы. Её одной мало: на бою корень диска после chown
        // принадлежит client и читается, а закрыты вложенные папки задач. Их проверяет сам
        // прогон на каждом файле и падает, ничего не записав.
        $documents = Storage::disk('local')->path('');

        if (!is_readable($documents) || !is_executable($documents)) {
            $this->error("Нет доступа к папке документов {$documents}");
            $this->line('Запускайте от www-data: sudo -u www-data php artisan autoaudit:run ' . ($all ? '--all' : "--tenant={$tenant}"));
            $this->line('Прошлые результаты не тронуты');

            return self::FAILURE;
        }

        if ($all) {
            return $this->runAll($runner);
        }

        return $this->runTenant($tenant, $runner) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Все фирмы ночного прогона, по одной. Упавшая фирма не останавливает остальные: её
     * сбой уже в журнале сбоев (см. RunAutoAuditJob::perform), здесь он только в выводе.
     * Команда вернёт ошибку, если упала хоть одна, чтобы это было видно и в логе крона.
     */
    private function runAll(AutoAuditRunner $runner): int
    {
        $started = microtime(true);
        $tenants = Tenant::forNightlyAutoAudit();

        if ($tenants->isEmpty()) {
            $this->line('Ни одна фирма не включена в ночной прогон: autoaudit:access --tenant=N --nightly-on');

            return self::SUCCESS;
        }

        $failed = [];

        foreach ($tenants as $tenant) {
            $this->line('');
            $this->line("== Фирма {$tenant->id} «{$tenant->name}», " . now()->format('d.m.Y H:i:s'));

            if (!$this->runTenant($tenant->id, $runner)) {
                $failed[] = $tenant->id;
            }
        }

        $this->line('');
        $this->line(sprintf(
            'Фирм %d, прошли %d, не прошли %d%s. Всего заняло %.1f с',
            $tenants->count(),
            $tenants->count() - count($failed),
            count($failed),
            $failed ? ' (' . implode(', ', $failed) . ')' : '',
            microtime(true) - $started,
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Прогон одной фирмы с выводом. Общий для --tenant и --all. */
    private function runTenant(int $tenant, AutoAuditRunner $runner): bool
    {
        $started = microtime(true);

        if ($this->option('dry-run')) {
            try {
                $this->preview($tenant, $runner);
            } catch (Throwable $e) {
                $this->error('Пробный прогон не прошёл: ' . $e->getMessage());

                return false;
            }

            $this->line('');
            $this->line(sprintf('Заняло %.1f с', microtime(true) - $started));

            return true;
        }

        try {
            $counts = RunAutoAuditJob::perform($tenant, $runner);
        } catch (Throwable $e) {
            // Состояние «упала» и журнал сбоев perform уже записал, здесь только сказать.
            $this->error('Проверка не прошла: ' . $e->getMessage());

            return false;
        }

        if ($counts === null) {
            $this->error('По этой фирме уже идёт проверка: второй прогон не начат');

            return false;
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

        return true;
    }

    /**
     * Пробный прогон: ни строк, ни находок, ни состояния для страницы не пишет, замок не
     * берёт. Печатает только разницу с тем, что сейчас на странице.
     */
    private function preview(int $tenant, AutoAuditRunner $runner): void
    {
        TenantContext::for($tenant, function () use ($runner) {
            $diff    = $runner->preview();
            $clients = Client::withTrashed()->pluck('name', 'id');

            $this->line(sprintf(
                'Пробный прогон, ничего не записано. Без изменений %d, сменится %d, новых %d, уйдёт %d',
                $diff['kept'], count($diff['changed']), count($diff['added']), count($diff['gone']),
            ));

            foreach ($diff['added'] as $row) {
                $this->line('');
                $this->line('+ ' . $this->describe($row, $clients));
            }

            foreach ($diff['changed'] as [$previous, $row]) {
                $this->line('');
                $this->line('~ ' . $this->describe($row, $clients));
                $this->line('  было: ' . (AutoAuditResult::LABELS[$previous->outcome] ?? $previous->outcome)
                    . $this->values($previous->left_value, $previous->right_value));
            }

            foreach ($diff['gone'] as $previous) {
                $this->line('');
                $this->line('- ' . $this->describe($previous->only([
                    'client_id', 'rule', 'period_from', 'period_to', 'outcome', 'left_value', 'right_value', 'reason',
                ]), $clients));
            }
        });
    }

    /** «Иванов ИП, №3, 2026-07-01..2026-07-31: Не совпало (1 200,00 и 0,00), вопрос бухгалтеру. Причина». */
    private function describe(array $row, $clients): string
    {
        $from = substr((string) $row['period_from'], 0, 10);
        $to   = substr((string) $row['period_to'], 0, 10);

        return sprintf(
            '%s, №%s, %s: %s%s%s%s',
            $clients[$row['client_id']] ?? "клиент {$row['client_id']}",
            $row['rule'],
            $from === $to ? $from : "{$from}..{$to}",
            AutoAuditResult::LABELS[$row['outcome']] ?? $row['outcome'],
            $this->values($row['left_value'] ?? null, $row['right_value'] ?? null),
            in_array($row['outcome'], AutoAuditResult::FINDING_OUTCOMES, true) ? ', вопрос бухгалтеру' : '',
            empty($row['reason']) ? '' : ". {$row['reason']}",
        );
    }

    private function values(mixed $left, mixed $right): string
    {
        if ($left === null && $right === null) {
            return '';
        }

        $format = fn ($v) => $v === null ? '?' : number_format((float) $v, 2, ',', ' ');

        return " ({$format($left)} и {$format($right)})";
    }
}
