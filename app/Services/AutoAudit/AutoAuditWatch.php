<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditDocumentRead;
use App\Models\AutoAuditResult;
use App\Models\AutoAuditSnapshot;
use App\Models\AutoAuditWatchClient;
use App\Models\AutoAuditWatchRun;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Наблюдение за прогоном: кого из клиентов нужно было проверять, а кого можно было
 * пропустить (этап 2а, 08.10.2026).
 *
 * Зачем. Позже сверки может делать ИИ, и каждая станет платной. Тогда ночь должна
 * проверять только тех, у кого что-то поменялось, и никого при этом не пропустить. Пока
 * прогон по-прежнему проверяет всех, а этот класс после него записывает, что было бы при
 * экономии. Ни строки результатов, ни страницы он не трогает.
 *
 * Как понимаем, что поменялось. После каждой проверки запоминаем снимок клиента:
 *   - общие части: карточка (ИНН, метод учёта, что ведём), настройки фирмы (размеченные БП,
 *     счета, выключенные проверки, старт) и код проверок. Поменялась любая, значит
 *     перепроверять клиента целиком;
 *   - по месяцам: задачи эталонных БП и файлы в них. Файл относим к месяцу, за который он
 *     составлен, по прочитанному из него периоду (квартальный отчёт к трём месяцам). Не
 *     прочитался, значит к месяцу перед задачей, как и задача без файла.
 * Месяц задет, когда его отпечаток стал другим. Сверка задета, когда задет хоть один месяц
 * её периода: так квартальный ЕН попадает в перепроверку при замене любой из трёх ОСВ.
 *
 * Тревога. Снимок не поменялся, а итог сверки поменялся: снимок что-то упустил, и
 * пропускать по нему пока нельзя. Тревоги две, по клиенту целиком и по месяцам. Журнал
 * собираем, пока тревог нет неделями, тогда и включаем экономию.
 *
 * Сверка = клиент × период × проверка (№1-№7). Строка «4,5,6,7» считается за четыре.
 */
class AutoAuditWatch
{
    /** Файлы автоаудита, от которых итог сверки не зависит: экран, вопросы, запись, наблюдение. */
    private const NOT_VERDICT = [
        'AutoAuditClientBoard.php',
        'AutoAuditQuestions.php',
        'AutoAuditStore.php',
        'AutoAuditWatch.php',
        'SourceDocumentLinks.php',
    ];

    /** Причины, по которым клиента перепроверяют целиком. */
    private const WHOLE = [AutoAuditWatchClient::NEW, AutoAuditWatchClient::CODE, AutoAuditWatchClient::SETTINGS, AutoAuditWatchClient::CLIENT];

    /**
     * @param array                         $rows   строки этого прогона
     * @param Collection<int, AutoAuditResult> $before действующие строки до записи прогона
     * @param array                         $reads  что прогон прочитал, AutoAuditSources::documentReads
     */
    public function record(array $rows, Collection $before, array $reads, string $trigger, float $seconds): AutoAuditWatchRun
    {
        $tenant   = Tenant::find(TenantContext::id());
        $services = Service::whereIn('reference_id', array_column(AutoAuditSources::SIDES, 'ref'))->get(['id', 'reference_id', 'splits_by_branch']);
        $sideOf   = [];   // id БП => сторона сверки

        foreach (AutoAuditSources::SIDES as $side => $definition) {
            foreach ($services->where('reference_id', $definition['ref']) as $service) {
                $sideOf[$service->id] = $side;
            }
        }

        $common = ['settings' => $this->settingsHash($tenant, $services), 'code' => self::codeHash()];

        $logs = BuhTaskLog::whereHas('estimateItem', fn ($q) => $q->whereIn('service_id', array_keys($sideOf)))
            ->with(['documents:id,documentable_type,documentable_id,path', 'estimateItem:id,service_id'])
            ->get(['id', 'client_id', 'estimate_item_id', 'year', 'month', 'status', 'force_closed', 'force_close_reason']);

        $stored = AutoAuditDocumentRead::get()->keyBy('document_id');

        return DB::transaction(function () use ($rows, $before, $reads, $trigger, $seconds, $logs, $sideOf, $stored, $common) {
            $this->storeReads($reads, $logs, $stored);

            $inputs  = $this->inputs($logs, $sideOf, $reads, $stored);
            $changed = $this->changedKeys($rows, $before);
            $checks  = [];   // клиент => [[сверок, месяцы], ...]

            foreach ($rows as $row) {
                $checks[$row['client_id']][] = [self::checksIn($row['rule']), self::monthsOf($row['period_from'] ?? null, $row['period_to'] ?? null, $row['sources'] ?? [])];
            }

            $snapshots = AutoAuditSnapshot::get()->keyBy('client_id');
            $total     = ['clients' => 0, 'clients_needed' => 0, 'checks' => 0, 'checks_by_client' => 0, 'checks_by_month' => 0, 'alarms' => 0, 'alarms_by_month' => 0];
            $written   = [];

            $clients = Client::get(['id', 'inn', 'accounting_method', ...array_values(Client::SERVICE_SCOPE_COLUMNS)]);

            foreach ($clients as $client) {
                $parts = ['client' => $this->clientHash($client)] + $common;
                $months = array_map(fn (array $items) => sha1(implode("\n", $items)), $inputs[$client->id] ?? []);
                ksort($months);

                $previous = $snapshots[$client->id] ?? null;
                $touched  = $previous ? $this->touched($months, $previous->months ?? []) : array_keys($months);
                $reason   = match (true) {
                    !$previous                                          => AutoAuditWatchClient::NEW,
                    ($previous->parts['code'] ?? null) !== $parts['code']         => AutoAuditWatchClient::CODE,
                    ($previous->parts['settings'] ?? null) !== $parts['settings'] => AutoAuditWatchClient::SETTINGS,
                    ($previous->parts['client'] ?? null) !== $parts['client']     => AutoAuditWatchClient::CLIENT,
                    (bool) $touched                                     => AutoAuditWatchClient::TASKS,
                    default                                             => AutoAuditWatchClient::SAME,
                };

                $whole    = in_array($reason, self::WHOLE, true);
                $own      = $checks[$client->id] ?? [];
                $all      = array_sum(array_column($own, 0));
                // Период строки неизвестен: считаем задетой, лучше переоценить, чем пропустить.
                $hit      = fn (array $rowMonths) => $whole || !$rowMonths || array_intersect($rowMonths, $touched);
                $byMonth  = array_sum(array_map(fn (array $c) => $hit($c[1]) ? $c[0] : 0, $own));
                $keys     = $changed[$client->id] ?? [];
                $alarm    = $reason === AutoAuditWatchClient::SAME && $keys;
                $alarmBy  = $alarm || ($reason === AutoAuditWatchClient::TASKS && array_filter($keys, fn (array $m) => !$m || !array_intersect($m, $touched)));
                $needed   = $reason !== AutoAuditWatchClient::SAME;

                $total['clients']++;
                $total['clients_needed']   += (int) $needed;
                $total['checks']           += $all;
                $total['checks_by_client'] += $needed ? $all : 0;
                $total['checks_by_month']  += $needed ? $byMonth : 0;
                $total['alarms']           += (int) $alarm;
                $total['alarms_by_month']  += (int) $alarmBy;

                if ($needed || $alarmBy) {
                    $written[] = [
                        'client_id'       => $client->id,
                        'reason'          => $reason,
                        'months'          => $whole ? [] : array_values($touched),
                        'checks'          => $all,
                        'checks_by_month' => $byMonth,
                        'changed'         => array_keys($keys),
                        'alarm'           => $alarm,
                        'alarm_by_month'  => $alarmBy,
                    ];
                }

                $hash = sha1(json_encode([$parts, $months]));

                if ($previous?->hash !== $hash) {
                    AutoAuditSnapshot::updateOrCreate(['client_id' => $client->id], ['hash' => $hash, 'parts' => $parts, 'months' => $months]);
                }
            }

            $run = AutoAuditWatchRun::create($total + ['trigger' => $trigger, 'seconds' => round($seconds, 1), 'code_hash' => $common['code']]);

            foreach ($written as $client) {
                $run->clients()->create($client);
            }

            return $run;
        });
    }

    /**
     * Отпечаток кода проверок. Меняется от любой правки читалок и сверки, так что после
     * выкатки снимок другой у всех, и забыть перепроверку нельзя.
     */
    public static function codeHash(): string
    {
        $files = glob(__DIR__ . '/*.php');
        sort($files);

        $parts = [];

        foreach ($files as $file) {
            if (!in_array(basename($file), self::NOT_VERDICT, true)) {
                $parts[] = basename($file) . ':' . sha1_file($file);
            }
        }

        return sha1(implode("\n", $parts));
    }

    /** Сколько сверок в строке: «4,5,6,7» это четыре. */
    public static function checksIn(?string $rule): int
    {
        return count(array_filter(explode(',', (string) $rule), fn (string $n) => $n !== ''));
    }

    /**
     * Месяцы строки «ГГГГ-ММ». У строки без периода (беда с файлом) берём месяц перед задачей.
     *
     * @return string[]
     */
    public static function monthsOf(mixed $from, mixed $to, array $sources): array
    {
        $month = fn (mixed $date) => $date instanceof DateTimeInterface ? $date->format('Y-m') : substr((string) $date, 0, 7);

        if ($from && $to) {
            $months = [];

            for ($m = CarbonImmutable::parse($month($from) . '-01'); $m->format('Y-m') <= $month($to); $m = $m->addMonth()) {
                $months[] = $m->format('Y-m');
            }

            return $months;
        }

        // task_month пишется как «08.2026».
        if (preg_match('/^(\d{2})\.(\d{4})$/', (string) ($sources[0]['task_month'] ?? ''), $m)) {
            return [CarbonImmutable::create((int) $m[2], (int) $m[1], 1)->subMonth()->format('Y-m')];
        }

        return [];
    }

    /**
     * Что лежит в каждом месяце клиента: задачи эталонных БП и файлы в них.
     *
     * Закрытые и сданные на проверку задачи берём с состоянием и файлами: их сверка читает.
     * Открытые задачи отчёта и формы 161 тоже берём, но без состояния: по ним сверка ждёт
     * филиал, и появление или удаление такой задачи меняет итог.
     *
     * @return array<int, array<string, string[]>> клиент => месяц => строки отпечатка
     */
    private function inputs(Collection $logs, array $sideOf, array $reads, Collection $stored): array
    {
        $inputs = [];

        foreach ($logs as $log) {
            $side = $sideOf[$log->estimateItem?->service_id] ?? null;

            if (!$side) {
                continue;
            }

            $taskMonth = CarbonImmutable::create($log->year, $log->month, 1)->subMonth()->format('Y-m');
            $task      = "{$side}:{$log->id}:{$log->estimate_item_id}:" . (int) $log->force_closed;

            if (!in_array($log->status, AutoAuditSources::DONE_STATUSES, true)) {
                if ($side !== 'osv') {
                    $inputs[$log->client_id][$taskMonth][] = "open:{$task}";
                }

                continue;
            }

            $task .= ":{$log->status}:{$log->force_close_reason}";

            if ($log->documents->isEmpty()) {
                $inputs[$log->client_id][$taskMonth][] = "task:{$task}";

                continue;
            }

            foreach ($log->documents as $document) {
                foreach ($this->documentMonths($document, $reads, $stored) ?: [$taskMonth] as $month) {
                    $inputs[$log->client_id][$month][] = "file:{$task}:{$document->id}:{$document->path}";
                }
            }
        }

        foreach ($inputs as &$months) {
            foreach ($months as &$items) {
                sort($items);
            }
        }

        return $inputs;
    }

    /**
     * Месяцы, за которые составлен файл. Прочитан в этом прогоне: по прочитанному. Нет:
     * по прошлой записи, если файл с тех пор не заменили. Иначе неизвестно.
     *
     * @return string[]
     */
    private function documentMonths(object $document, array $reads, Collection $stored): array
    {
        if (isset($reads[$document->id])) {
            $period = $reads[$document->id]['value']->period;
        } else {
            $read   = $stored[$document->id] ?? null;
            $period = $read && $read->path === $document->path && $read->period_from
                ? new DocumentPeriod(CarbonImmutable::parse($read->period_from->toDateString()), CarbonImmutable::parse($read->period_to->toDateString()))
                : null;
        }

        return $period ? array_map(fn (array $ym) => sprintf('%04d-%02d', ...$ym), $period->months()) : [];
    }

    /** Записать, что узнали из файлов. Пишем только новое и поменявшееся. */
    private function storeReads(array $reads, Collection $logs, Collection $stored): void
    {
        $owners = [];   // id документа => [документ, задача]

        foreach ($logs as $log) {
            foreach ($log->documents as $document) {
                $owners[$document->id] = [$document, $log];
            }
        }

        foreach ($reads as $id => ['side' => $side, 'value' => $value]) {
            if (!isset($owners[$id])) {
                continue;
            }

            [$document, $log] = $owners[$id];

            $attributes = [
                'client_id'   => $log->client_id,
                'side'        => $side,
                'path'        => $document->path,
                'period_from' => $value->period?->from->toDateString(),
                'period_to'   => $value->period?->to->toDateString(),
                'status'      => $value->status,
                'inn'         => $value->inn,
            ];

            $read = $stored[$id] ?? null;

            if (!$read) {
                AutoAuditDocumentRead::create(['document_id' => $id] + $attributes);

                continue;
            }

            $same = (int) $read->client_id === (int) $attributes['client_id']
                && $read->side === $side
                && $read->path === $attributes['path']
                && $read->period_from?->toDateString() === $attributes['period_from']
                && $read->period_to?->toDateString() === $attributes['period_to']
                && $read->status === $attributes['status']
                && $read->inn === $attributes['inn'];

            if (!$same) {
                $read->update($attributes);
            }
        }
    }

    /**
     * Строки, у которых итог стал другим, появился или ушёл, с их месяцами.
     *
     * @return array<int, array<string, string[]>> клиент => ключ строки => месяцы
     */
    private function changedKeys(array $rows, Collection $before): array
    {
        $previous = $before->keyBy(fn (AutoAuditResult $r) => $r->key());
        $changed  = [];
        $seen     = [];

        foreach ($rows as $row) {
            $key        = AutoAuditResult::keyOf($row);
            $seen[$key] = true;
            $old        = $previous[$key] ?? null;

            if (!$old || !$old->sameVerdict($row)) {
                $changed[$row['client_id']][$key] = self::monthsOf($row['period_from'] ?? null, $row['period_to'] ?? null, $row['sources'] ?? []);
            }
        }

        foreach ($previous as $key => $old) {
            if (!isset($seen[$key])) {
                $changed[$old->client_id][$key] = self::monthsOf($old->period_from, $old->period_to, $old->sources ?? []);
            }
        }

        return $changed;
    }

    /** Месяцы, у которых отпечаток другой, появился или пропал. */
    private function touched(array $months, array $previous): array
    {
        $all = array_unique([...array_keys($months), ...array_keys($previous)]);
        sort($all);

        return array_values(array_filter($all, fn (string $m) => ($months[$m] ?? null) !== ($previous[$m] ?? null)));
    }

    private function clientHash(Client $client): string
    {
        return sha1(json_encode([
            preg_replace('/\D+/', '', (string) $client->inn),
            $client->accounting_method,
            $client->serviceTypeKeys(),
        ]));
    }

    private function settingsHash(?Tenant $tenant, Collection $services): string
    {
        return sha1(json_encode([
            $services->sortBy('id')->map(fn (Service $s) => [$s->id, $s->reference_id, (bool) $s->splits_by_branch])->values()->all(),
            array_keys($tenant?->autoAuditOff() ?? []),
            $tenant?->autoAuditFrom(),
            array_map(fn (int $rule) => AutoAuditRunner::accountsFor($rule, $tenant), array_keys(AutoAuditRunner::RULES)),
        ]));
    }
}
