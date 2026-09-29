<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditResult;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Автоаудит: ОСВ против отчёта по единому налогу и против формы 161.
 *
 * Правила зашиты здесь же, списком. Это сознательно топорно: цель увидеть на боевых
 * документах, совпадают числа или нет. Каталог правил, допуски и переключатели появятся
 * потом, когда станет понятно, как выглядит настоящее расхождение.
 *
 * Как идёт сверка у одного клиента:
 *   1. берём документы закрытых задач эталонных БП: ведомость и второй документ проверки;
 *   2. читаем каждый и узнаём период из самого документа, а не из месяца задачи;
 *   3. ИНН в шапке документа сверяем с карточкой клиента: чужая организация, чужой файл;
 *   4. ставим в пару ведомость и документы за один и тот же период. Ведомость всегда
 *      помесячная, а отчёт бывает квартальным: тогда складываем ведомости трёх месяцев;
 *   5. документы филиалов за период складываем и сравниваем с ведомостью до копейки.
 *
 * Какие строки пишем:
 *   - совпало или не совпало, когда пару удалось сравнить. Пары нет вовсе или документа
 *     одного из филиалов не хватает: такую строку не пишем, чтобы не засорять страницу;
 *   - нет документа: задача закрыта без файла, или у квартального отчёта нет ведомости за
 *     какой-то месяц. Одна строка на клиента и период, в ней проверки, которые это ломает;
 *   - беда с документом: не тот документ, скан или файл не открылся. Стоит под каждой
 *     проверкой клиента, которая берёт числа из этого документа.
 *
 * Принудительно закрытые задачи «нет документа» не дают: человек записал причину. Если он
 * выбрал «Нулевой» (или «Освобождён» для налога), задача встаёт в пару нулём и сверяется со
 * второй стороной по факту: там цифры, значит «Не совпало»; там тоже ноль, значит «Совпало».
 * «Раз в квартал», «Другое» и старые задачи без выбора в сверку не идут, как раньше.
 *
 * Работа поделена на три класса (с 28.09.2026): AutoAuditSources находит задачи и документы
 * и читает числа, здесь пары и вердикт, AutoAuditStore записывает итог и вопросы.
 *
 * Прогон проверяет всех клиентов, но прошлое не стирает, а сравнивает с ним (AutoAuditStore).
 * Новая строка появляется, только когда итог стал другим, прежняя остаётся историей.
 *
 * Проверять только изменившихся клиентов сознательно не стали. Итог зависит от многого:
 * задачи, файлы, ИНН, метод учёта, смета, тип обслуживания. Забыть что-то одно значило бы
 * молча оставить на странице устаревший вердикт. Полный прогон на бою идёт 35 секунд.
 */
class AutoAuditRunner
{
    public const REF_TAX_REPORT    = 3;
    public const REF_FORM_161      = 7;
    public const REF_BALANCE_SHEET = 11;

    /** Статус источника «задача закрыта без файла», см. AutoAuditSources::missingSource. */
    public const SOURCE_MISSING = 'missing';

    /** Статус источника «задача закрыта принудительно как нулевая»: файла нет, число 0. */
    public const SOURCE_FORCED_ZERO = 'forced_zero';

    /**
     * Проверки.
     *
     * Ключ: номер проверки, он же номер строки в таблице правил (колонка A). Номер не
     * меняем и не переиспользуем: по нему связаны результаты и фильтр на странице.
     *
     * name:      название для людей: страница, фильтр, вопрос бухгалтеру, справка. Взято
     *            из таблицы правил Искендера (колонка «Название») 29.09.2026;
     * hint:      подсказка при наведении на название (колонка «Подсказка при наведении»),
     *            со счётом: бухгалтер ищет строку в ведомости по номеру;
     * account:   счёт в ОСВ, берём оборот за период по кредиту;
     * document:  сторона второго документа, см. AutoAuditSources::SIDES;
     * field:     число из него: 'base' и 'tax' у отчёта по налогу; 'income', 'income_tax',
     *            'contributions' и 'pension' у формы 161;
     * method:    метод учёта клиента из карточки, null = любой. Клиент с незаполненным
     *            методом проверки с методом не проходит. Полное обслуживание нужно всем.
     * from:      первый отчётный период ('2026-09'), который проверка смотрит; null = все.
     *            Новая проверка не лезет в прошлое: иначе в день выкатки бухгалтеры получают
     *            вопросы про давно сданные месяцы. Так было с №2 29.09.2026: два вопроса из
     *            трёх пришли про июнь. Периоды берём из документа, квартал по первому месяцу.
     *            Старт фирмы (Tenant::autoAuditFrom) сдвигает его ещё позже, но не раньше.
     *
     * Все проверки берут обороты, поэтому ведомости за квартал складываются. У будущих
     * проверок по сальдо так нельзя: там нужен последний месяц.
     *
     * У №4 в таблице правил стоит счёт 3510, но в ведомостях фирм его нет вовсе: зарплата
     * на 3520 «Начисленная заработная плата». На боевых парах за июль 2026 оборот 3520
     * сошёлся с доходом формы 161 у всех, у кого форма своя.
     */
    public const RULES = [
        1 => [
            'name'      => 'Доход в отчёте по ЕН = полученные деньги',
            'hint'      => 'ОСВ: 3210 полученные деньги, оборот по кредиту ↔ Отчёт по ЕН: налогооблагаемая база (кассовый метод)',
            'account'   => '3210',
            'document'  => 'tax',
            'field'     => 'base',
            'method'    => Client::ACCOUNTING_CASH,
            'from'      => null,
        ],
        2 => [
            'name'      => 'Доход в отчёте по ЕН = выручка в учёте',
            'hint'      => 'ОСВ: 6110 выручка, оборот по кредиту ↔ Отчёт по ЕН: налогооблагаемая база (метод начисления)',
            'account'   => '6110',
            'document'  => 'tax',
            'field'     => 'base',
            'method'    => Client::ACCOUNTING_ACCRUAL,
            'from'      => '2026-09',
        ],
        3 => [
            'name'      => 'Единый налог в отчёте = начислен в учёте',
            'hint'      => 'ОСВ: 3410 единый налог, оборот по кредиту ↔ Отчёт по ЕН: сумма налога',
            'account'   => '3410',
            'document'  => 'tax',
            'field'     => 'tax',
            'method'    => null,
            'from'      => null,
        ],
        4 => [
            'name'      => 'Зарплата в Форме 161 = начислена в учёте',
            'hint'      => 'ОСВ: 3520 начисленная зарплата, оборот по кредиту ↔ Форма 161: начисленный доход',
            'account'   => '3520',
            'document'  => 'f161',
            'field'     => 'income',
            'method'    => null,
            'from'      => null,
        ],
        5 => [
            'name'      => 'Подоходный налог в Форме 161 = в учёте',
            'hint'      => 'ОСВ: 3420 подоходный налог, оборот по кредиту ↔ Форма 161: ПН к уплате',
            'account'   => '3420',
            'document'  => 'f161',
            'field'     => 'income_tax',
            'method'    => null,
            'from'      => null,
        ],
        6 => [
            'name'      => 'Соцвзносы в Форме 161 = в учёте',
            'hint'      => 'ОСВ: 3531 страховые взносы, оборот по кредиту ↔ Форма 161: начисленные взносы',
            'account'   => '3531',
            'document'  => 'f161',
            'field'     => 'contributions',
            'method'    => null,
            'from'      => null,
        ],
        7 => [
            'name'      => 'Взносы ГНПФ в Форме 161 = в учёте',
            'hint'      => 'ОСВ: 3534 ГНПФ, оборот по кредиту ↔ Форма 161: взносы ГНПФ',
            'account'   => '3534',
            'document'  => 'f161',
            'field'     => 'pension',
            'method'    => null,
            'from'      => null,
        ],
    ];

    /**
     * Допуск на округление, в сомах. Разница до него включительно считается совпадением.
     *
     * До 24.09.2026 сравнивали до копейки, и на бою две строки стали красными из-за 0,01 и
     * 0,02: 1С и налоговая форма округляют по-разному. Руководитель, увидев красное из-за
     * копеек, перестал бы верить странице. Сом выбрал Искендер.
     */
    private const TOLERANCE = 1.00;

    /** Что изменил последний прогон, см. AutoAuditStore::store. */
    private array $changes = [];

    /** Что прогон сделал с находками, см. AutoAuditStore::syncFindings. */
    private array $findingChanges = [];

    public function __construct(
        private readonly AutoAuditSources $sources,
        private readonly AutoAuditStore $store,
    ) {}

    /**
     * Прогнать проверку по текущей фирме и записать результат.
     *
     * @return array<string, int> сколько строк с каким исходом
     */
    public function run(): array
    {
        $rows = $this->collect();

        // Одна транзакция на весь прогон: либо записалось всё, либо ничего, и половинчатой
        // страницы не бывает. Находки в той же: без неё строка и её находка могли бы разойтись.
        $this->changes = DB::transaction(function () use ($rows) {
            $changes = $this->store->store($rows);
            $this->findingChanges = $this->store->syncFindings();

            return $changes;
        });

        return collect($rows)->countBy('outcome')->all();
    }

    /**
     * Пробный прогон: посчитать всё как обычно и ничего не записать.
     *
     * Сверяет строки прогона с действующими по тем же ключу и вердикту, что store, и
     * отдаёт только разницу. Нужен, чтобы увидеть на боевых данных, что поменяет правка
     * правил, до того как строки и вопросы увидят люди.
     *
     * @return array{
     *     added: array<int, array>,
     *     changed: array<int, array{0: AutoAuditResult, 1: array}>,
     *     gone: array<int, AutoAuditResult>,
     *     kept: int
     * }
     */
    public function preview(): array
    {
        return $this->store->preview($this->collect());
    }

    /**
     * Строки прогона по текущей фирме, без записи.
     *
     * @return array<int, array>
     */
    private function collect(): array
    {
        // Без фирмы в контексте запись после сбора снесла бы результаты всех фирм разом.
        if (!TenantContext::has()) {
            throw new RuntimeException('Автоаудит запускается только внутри фирмы');
        }

        $this->sources->reset();

        $byRef    = Service::whereIn('reference_id', array_column(AutoAuditSources::SIDES, 'ref'))->get()->keyBy('reference_id');
        $services = [];   // сторона => БП этой фирмы

        foreach (AutoAuditSources::SIDES as $side => $definition) {
            if ($byRef->has($definition['ref'])) {
                $services[$side] = $byRef[$definition['ref']];
            }
        }

        // Проверка работает, только если в фирме размечены оба её БП.
        $rules = array_filter(self::RULES, fn (array $rule) => isset($services['osv'], $services[$rule['document']]));

        // Ни одна проверка не работает: в фирме не размечены эталонные БП. Это ошибка
        // разметки, а не пустой результат, и останавливаемся мы до записи. Раньше прогон
        // шёл дальше, стирал все прошлые строки фирмы и записывал ноль, а страница после
        // этого писала «Проверки ещё не было»: потерю было не отличить от чистой фирмы.
        if (!$rules) {
            throw new RuntimeException(
                'В фирме не размечены эталонные БП, проверять нечего. Результаты прошлого прогона не тронуты',
            );
        }

        // У фирмы свой старт: проверка смотрит с того месяца, что позже, её или фирмы.
        // Строки 'ГГГГ-ММ' сравниваются как числа.
        $start = Tenant::find(TenantContext::id())?->autoAuditFrom();

        if ($start) {
            $rules = array_map(fn (array $rule) => array_merge($rule, ['from' => max($rule['from'] ?? '', $start)]), $rules);
        }

        $rows = [];

        foreach (Client::orderBy('name')->get() as $client) {
            array_push($rows, ...$this->checkClient($client, $services, $rules));
        }

        return $rows;
    }

    /**
     * Что изменил последний прогон: сколько строк осталось как было, сколько сменилось
     * новым итогом, сколько появилось впервые и сколько ушло.
     *
     * @return array{kept: int, changed: int, added: int, gone: int}
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * Что последний прогон сделал с находками: сколько открыл, сколько закрыл и сколько
     * открытых осталось всего.
     *
     * @return array{opened: int, closed: int, open: int}
     */
    public function findingChanges(): array
    {
        return $this->findingChanges;
    }

    /**
     * @param array<string, Service> $services сторона => БП
     * @param array<int, array>      $rules    проверки, для которых в фирме есть оба БП
     */
    private function checkClient(Client $client, array $services, array $rules): array
    {
        // Ведём не всё: ведомость или отчёт может делать кто-то другой, сверка ни о чём.
        if (!$client->servesEverything()) {
            return [];
        }

        $rules = array_filter(
            $rules,
            fn (array $rule) => $rule['method'] === null || $client->accounting_method === $rule['method'],
        );

        // Ведомость нужна всем проверкам, вторые документы только своим.
        $documents = [];

        foreach (array_unique(['osv', ...array_column($rules, 'document')]) as $side) {
            $documents[$side] = $this->sources->documents($client, $services[$side]);
        }

        // Принудительно закрытые как нулевые: документа нет, число 0, см. forcedZeros.
        $forced = [];

        foreach (array_keys($documents) as $side) {
            $forced[$side] = $this->sources->forcedZeros($client, $services[$side]);
        }

        $rows = $this->missingDocuments($client, $services, $documents, $rules, $forced['osv']);

        // Проверяем по отдельности: ключи у обоих одни (osv, tax), и слияние их затёрло бы.
        $isEmpty = fn (Collection $found) => $found->isEmpty();

        if (collect($documents)->every($isEmpty) && collect($forced)->every($isEmpty)) {
            return $this->withoutEarlyPeriods($rows, $rules);
        }

        // Беда с документом ломает каждую проверку, которая берёт из него число. Строка одна,
        // в ней номера этих проверок, как у «нет документа». Раньше строка повторялась под
        // каждой проверкой, и одна неверная форма 161 на бою давала шесть красных строк.
        // Второй стороны может не быть вовсе: беда от этого не пропадает.
        foreach ($documents as $side => $found) {
            $numbers = $side === 'osv'
                ? array_keys($rules)
                : array_keys(array_filter($rules, fn (array $rule) => $rule['document'] === $side));

            foreach ($this->documentProblems($client, $side, $found) as $row) {
                $rows[] = array_merge($row, ['rule' => implode(',', $numbers)]);
            }
        }

        $logs = [];

        foreach ($rules as $number => $rule) {
            $side = $rule['document'];

            $logs[$side] ??= $this->sources->taskLogs($client, $services[$side]);

            array_push($rows, ...$this->checkRule(
                $client, $number, $rule, $documents['osv'], $documents[$side], $logs[$side], $forced['osv'], $forced[$side],
            ));
        }

        return $this->withoutEarlyPeriods($rows, $rules);
    }

    /**
     * Убрать из строк проверки, чей период раньше их «from». Строка с несколькими номерами
     * («2,3») теряет только лишний номер, строка без номеров уходит целиком.
     */
    private function withoutEarlyPeriods(array $rows, array $rules): array
    {
        $result = [];

        foreach ($rows as $row) {
            $month   = substr($row['period_from'], 0, 7);
            $numbers = array_filter(
                explode(',', (string) $row['rule']),
                fn (string $number) => ($rules[(int) $number]['from'] ?? null) === null || $month >= $rules[(int) $number]['from'],
            );

            if ($numbers) {
                $result[] = array_merge($row, ['rule' => implode(',', $numbers)]);
            }
        }

        return $result;
    }

    /**
     * Нет документа: одна строка на период, в ней проверки, которые это ломает.
     *
     * Два случая:
     *   - задача закрыта (или сдана на проверку), а файла в ней нет. Работу отметили
     *     сделанной, а подтверждения нет. Незакрытые задачи не трогаем: это обычная работа
     *     или просрочка, её уже показывает БухЗадачник. Период берём месяцем перед задачей;
     *   - отчёт квартальный, а ведомости за какой-то месяц квартала нет. Нет ни одной
     *     ведомости за квартал: пары нет вовсе, строку не пишем. Период берём из отчёта.
     *
     * Нет ведомости, значит сломаны все проверки клиента. Нет второго документа, значит
     * только те, что берут из него число.
     *
     * Числа в такой строке не показываем: строка общая для проверок, а числа у них разные.
     */
    private function missingDocuments(Client $client, array $services, array $documents, array $rules, Collection $sheetForced): array
    {
        $affects = ['osv' => array_keys($rules)];

        foreach ($rules as $number => $rule) {
            $affects[$rule['document']][] = $number;
        }

        // Опознанные документы: сторона => подпись периода => период и источники.
        $recognized = [];

        foreach ($documents as $side => $found) {
            foreach ($found as [$log, $document]) {
                $value = $this->sources->read($client, $side, AutoAuditSources::SIDES[$side]['probe'], $document);

                if (!$value->period) {
                    continue;
                }

                $label = $value->period->label();
                $recognized[$side][$label]['period']    = $value->period;
                $recognized[$side][$label]['sources'][] = array_merge($this->sources->source($side, $log, $document, $value), ['value' => null]);
            }
        }

        // Ведомость, закрытая как нулевая, закрывает свой месяц в квартале: оборот ноль.
        foreach ($sheetForced as $log) {
            $period = AutoAuditSources::periodOfTask($log);
            $label  = $period->label();

            $recognized['osv'][$label]['period']    ??= $period;
            $recognized['osv'][$label]['sources'][] = array_merge($this->sources->forcedSource('osv', $log), ['value' => null]);
        }

        // Подпись периода => период, номера проверок, причины и файлы, найденные сверх опознанных.
        $rows = [];

        foreach (array_keys($documents) as $side) {
            foreach ($this->sources->closedWithoutFiles($client, $services[$side]) as $log) {
                // С первого числа, иначе «31 августа минус месяц» перельётся мимо июля.
                $month  = CarbonImmutable::create($log->year, $log->month, 1)->subMonth();
                $period = DocumentPeriod::of($month->year, $month->month);
                $label  = $period->label();

                $rows[$label] ??= ['period' => $period, 'numbers' => [], 'notes' => [], 'extra' => []];
                array_push($rows[$label]['numbers'], ...$affects[$side]);
                $rows[$label]['notes'][] = $this->closedWithoutFileNote($log, $services[$side]);
                // Сама задача без файла: по ней видно, с кого спросить и куда приложить файл.
                $rows[$label]['extra'][] = $this->sources->missingSource($side, $log);
            }
        }

        foreach ($recognized as $side => $periods) {
            if ($side === 'osv') {
                continue;
            }

            foreach ($periods as $label => $report) {
                // Документ месячный, или ведомость ровно за его период есть: сверка идёт обычным путём.
                if (count($report['period']->months()) === 1 || isset($recognized['osv'][$label])) {
                    continue;
                }

                $present = [];
                $missing = [];

                foreach ($report['period']->months() as [$year, $month]) {
                    $monthPeriod = DocumentPeriod::of($year, $month);

                    if (isset($recognized['osv'][$monthPeriod->label()])) {
                        array_push($present, ...$recognized['osv'][$monthPeriod->label()]['sources']);
                    } else {
                        $missing[] = $monthPeriod->title();
                    }
                }

                if (!$missing || !$present) {
                    continue;
                }

                $rows[$label] ??= ['period' => $report['period'], 'numbers' => [], 'notes' => [], 'extra' => []];
                array_push($rows[$label]['numbers'], ...$affects[$side]);
                array_push($rows[$label]['extra'], ...$present);
                $rows[$label]['notes'][] = 'Нет ведомости за ' . implode(', ', $missing);
            }
        }

        $result = [];

        foreach ($rows as $label => $row) {
            $numbers = array_values(array_unique($row['numbers']));
            sort($numbers);

            // Рядом видны уже приложенные документы этих проверок за тот же период.
            $sources = $row['extra'];

            foreach (array_unique(['osv', ...array_map(fn (int $number) => $rules[$number]['document'], $numbers)]) as $side) {
                array_push($sources, ...($recognized[$side][$label]['sources'] ?? []));
            }

            $result[] = [
                'client_id'   => $client->id,
                'rule'        => implode(',', $numbers),
                'period_from' => $row['period']->from->toDateString(),
                'period_to'   => $row['period']->to->toDateString(),
                'outcome'     => AutoAuditResult::MISSING_DOCUMENT,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => implode('. ', $row['notes']),
                // У задачи без файла document_id пустой: такие различаем по задаче, иначе
                // две задачи без файла слились бы в одну.
                'sources'     => collect($sources)->unique(fn (array $s) => $s['document_id'] ?? 'log:' . $s['log_id'])->values()->all(),
            ];
        }

        return $result;
    }

    /** «Задача «Закрытие месяца и ОСВ» за 08.2026: исполнитель Иванова А., закрыта 05.08.2026 без файла». */
    private function closedWithoutFileNote(BuhTaskLog $log, Service $service): string
    {
        $parts = [];

        if ($log->employee) {
            $parts[] = 'исполнитель ' . $log->employee->full_name;
        }

        $parts[] = $log->status === 'review'
            ? 'сдана на проверку без файла'
            : trim('закрыта ' . ($log->completed_at?->format('d.m.Y') ?? '')) . ' без файла';

        return sprintf('Задача «%s» за %02d.%d: %s', $service->name, $log->month, $log->year, implode(', ', $parts));
    }

    /**
     * Задачи, где файлы приложены, но нужную форму среди них прочитать не удалось.
     *
     * Смотрим задачу целиком, а не каждый файл: рядом с отчётом часто лежит квитанция об
     * оплате, и сама по себе она не ошибка.
     *
     * Причину называем уверенно, только когда можем:
     *   - среди файлов есть скан или фото: нужный документ может быть как раз им, поэтому
     *     «скан», а не «не тот документ»;
     *   - какой-то файл не открылся: по той же причине «файл не открылся»;
     *   - все файлы открылись и прочитались, но это другие формы или другая организация:
     *     вот тогда «не тот документ».
     *
     * Отчётный период из такого файла не прочитать, а на странице строки выбираются по
     * периоду. Берём месяц перед месяцем задачи: отчёт за июль сдают в августовской задаче.
     */
    private function documentProblems(Client $client, string $side, Collection $documents): array
    {
        $rows = [];

        foreach ($documents->groupBy(fn (array $pair) => $pair[0]->id) as $pairs) {
            $sources    = [];
            $recognized = false;

            foreach ($pairs as [$log, $document]) {
                $value = $this->sources->read($client, $side, AutoAuditSources::SIDES[$side]['probe'], $document);

                // Период читалка отдаёт, только когда форма опознана.
                $recognized = $recognized || $value->period !== null;
                $sources[]  = $this->sources->source($side, $log, $document, $value);
            }

            if ($recognized) {
                continue;
            }

            $statuses = array_column($sources, 'status');

            [$outcome, $reason] = match (true) {
                in_array(DocumentValue::SCAN, $statuses, true) => [
                    AutoAuditResult::SCAN,
                    'Документ отсканирован или сфотографирован, прочитать его пока нельзя',
                ],
                in_array(DocumentValue::UNREADABLE, $statuses, true) => [
                    AutoAuditResult::UNREADABLE,
                    'Файл не открылся',
                ],
                default => [
                    AutoAuditResult::WRONG_DOCUMENT,
                    // Причину берём из самого файла: чужой ИНН, ведомость за год, форма не
                    // сходится внутри. Иначе человек читает «нет формы 161» и идёт искать
                    // файл, который лежит на месте. Общие слова только когда файлов несколько
                    // и беды у них разные.
                    $this->innMismatchReason($sources)
                        ?? $this->commonReason($sources)
                        ?? 'Среди файлов задачи нет ' . AutoAuditSources::SIDES[$side]['genitive'],
                ],
            };

            // С первого числа, иначе «31 августа минус месяц» перельётся мимо июля.
            $month  = CarbonImmutable::create($log->year, $log->month, 1)->subMonth();
            $period = DocumentPeriod::of($month->year, $month->month);

            $rows[] = [
                'client_id'   => $client->id,
                'rule'        => null,
                'period_from' => $period->from->toDateString(),
                'period_to'   => $period->to->toDateString(),
                'outcome'     => $outcome,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => $reason,
                'sources'     => $sources,
            ];
        }

        return $rows;
    }

    /** Причина про чужой ИНН среди файлов задачи, если она там есть. */
    private function innMismatchReason(array $sources): ?string
    {
        foreach ($sources as $source) {
            if (str_starts_with((string) ($source['reason'] ?? ''), AutoAuditSources::INN_MISMATCH)) {
                return $source['reason'];
            }
        }

        return null;
    }

    /** Причина, общая для всех файлов задачи, если она у них одна. */
    private function commonReason(array $sources): ?string
    {
        $reasons = array_unique(array_filter(array_map(fn (array $source) => $source['reason'] ?? null, $sources)));

        return count($reasons) === 1 ? reset($reasons) : null;
    }

    private function checkRule(
        Client $client,
        int $number,
        array $rule,
        Collection $sheetDocuments,
        Collection $reportDocuments,
        Collection $reportLogs,
        Collection $sheetForced,
        Collection $reportForced,
    ): array {
        $periods = [];   // подпись периода => ['period' => DocumentPeriod, 'osv' => [...], 'report' => [...]]

        foreach (['osv' => $sheetDocuments, 'report' => $reportDocuments] as $slot => $documents) {
            $side  = $slot === 'osv' ? 'osv' : $rule['document'];
            $field = $slot === 'osv' ? $rule['account'] : $rule['field'];

            foreach ($documents as [$log, $document]) {
                $value = $this->sources->read($client, $side, $field, $document);

                // Не тот документ, скан или не открылся: в пару его не поставить.
                if (!$value->period) {
                    continue;
                }

                $label = $value->period->label();
                $periods[$label]['period'] = $value->period;
                $periods[$label][$slot][]  = $this->sources->source($side, $log, $document, $value);
            }
        }

        $this->addForcedZeros($periods, 'osv', 'osv', $sheetForced, false);
        // «Освобождён» заявляет ноль только по налогу: выручка у селлера ВБ есть, отчёта нет.
        $this->addForcedZeros($periods, 'report', $rule['document'], $reportForced, $rule['field'] === 'tax');

        $rows = [];

        foreach ($periods as $entry) {
            $osv     = $entry['osv'] ?? [];
            $reports = $entry['report'] ?? [];

            // Документ за несколько месяцев, а ведомости за тот же период нет: ведомости у нас
            // помесячные, собираем их по месяцам документа.
            $sheets = !$osv && $reports && count($entry['period']->months()) > 1
                ? $this->sheetsByMonth($entry['period'], $periods)
                : [$entry['period']->title() => $osv];

            $row = $this->compare($client, $number, $rule, $entry['period'], $sheets, $reports, $reportLogs);

            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Принудительно закрытые как нулевые задачи встают в пары нулём.
     *
     * Если за тот же период от той же строки сметы (филиала) есть настоящий документ, верим
     * документу: ноль нужен там, где сравнивать иначе не с чем.
     *
     * @param bool $exemptCounts «Освобождён» тоже ноль (только налог), иначе только «Нулевой»
     */
    private function addForcedZeros(array &$periods, string $slot, string $side, Collection $logs, bool $exemptCounts): void
    {
        foreach ($logs as $log) {
            if ($log->force_close_reason === BuhTaskLog::FORCE_EXEMPT && !$exemptCounts) {
                continue;
            }

            $period = AutoAuditSources::periodOfTask($log);
            $label  = $period->label();
            $filed  = array_column($periods[$label][$slot] ?? [], 'branch_id');

            if (in_array($log->estimate_item_id, $filed)) {
                continue;
            }

            $periods[$label]['period'] ??= $period;
            $periods[$label][$slot][]    = $this->sources->forcedSource($side, $log);
        }
    }

    /**
     * Ведомости за каждый месяц периода, ключ «май 2026». Пустой список, если за месяц
     * ведомости нет.
     */
    private function sheetsByMonth(DocumentPeriod $period, array $periods): array
    {
        $sheets = [];

        foreach ($period->months() as [$year, $month]) {
            $monthPeriod = DocumentPeriod::of($year, $month);
            $sheets[$monthPeriod->title()] = $periods[$monthPeriod->label()]['osv'] ?? [];
        }

        return $sheets;
    }

    /**
     * Строка результата, или null, если сравнивать не с чем.
     *
     * @param array<string, array> $sheets ведомости по месяцам периода: «июль 2026» => источники
     */
    private function compare(
        Client $client,
        int $number,
        array $rule,
        DocumentPeriod $period,
        array $sheets,
        array $reports,
        Collection $reportLogs,
    ): ?array {
        // Документы только с одной стороны: пары нет.
        if (!$reports || !array_filter($sheets)) {
            return null;
        }

        // Квартал без ведомости за какой-то месяц: сумма двух месяцев дала бы ложное красное.
        // Строку «нет документа» об этом пишет missingDocuments().
        if (count(array_filter($sheets)) < count($sheets)) {
            return null;
        }

        $notes = [];

        $left        = 0.0;
        $sources     = [];
        $unknown     = [];   // числа, которые прочитать не удалось
        $assumedZero = [];   // обороты, которых в ведомости нет и которые взяты нулём

        foreach ($sheets as $month => $osv) {
            // Ведомость за месяц одна. Приложили несколько, берём последнюю загруженную.
            if (count($osv) > 1) {
                $notes[] = sprintf('Ведомостей за %s: %d, взята последняя', $month, count($osv));
            }

            if ($osv[0]['status'] === DocumentValue::UNCERTAIN) {
                $unknown[] = $osv[0]['reason'];
            } elseif ($osv[0]['status'] === DocumentValue::NOT_FOUND) {
                // В ОСВ 1С не печатает счета без оборотов и сальдо: нет строки, значит ноль.
                $assumedZero[] = "в ведомости за {$month} нет оборота по счёту {$rule['account']}";
            }

            $left += (float) ($osv[0]['value'] ?? 0);
            array_push($sources, ...$osv);
        }

        // Ноль, который заявил человек, закрыв задачу как нулевую. С ним «ноль против нуля»
        // уже проверенный итог, а не два нуля из пустых ячеек.
        $forced = [];

        foreach ([...$sources, ...$reports] as $source) {
            if ($source['status'] === self::SOURCE_FORCED_ZERO) {
                $forced[] = "{$source['label']}: задача {$source['reason']}";
            }
        }

        foreach ($reports as $report) {
            if ($report['status'] === DocumentValue::UNCERTAIN) {
                $unknown[] = $report['reason'];
            } elseif ($report['status'] === DocumentValue::NOT_FOUND) {
                $assumedZero[] = sprintf('в документе «%s» нет показателя', $report['name']);
            }
        }

        [$branchUnknown, $branchNotes] = $this->branchIssues($reportLogs, $period, $reports);
        array_push($unknown, ...$branchUnknown);
        array_push($notes, ...$branchNotes);

        $left  = round($left, 2);
        $right = round((float) array_sum(array_column($reports, 'value')), 2);

        $row = [
            'client_id'   => $client->id,
            'rule'        => (string) $number,
            'period_from' => $period->from->toDateString(),
            'period_to'   => $period->to->toDateString(),
            'sources'     => array_merge($sources, $reports),
        ];

        // Числа нет: вердикт не выносим ни в какую сторону. Строку всё равно пишем, иначе
        // клиент пропал бы со страницы, а это выглядит как «у него всё в порядке».
        if ($unknown) {
            return $row + [
                'outcome'     => AutoAuditResult::UNVERIFIED,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => 'Не удалось проверить: ' . implode('. ', array_unique(array_filter($unknown))),
            ];
        }

        $difference = round($left - $right, 2);
        $matched    = abs($difference) <= self::TOLERANCE;

        // Сошлось, но одна из сторон не прочитана, а взята нулём: «Совпало» тут собралось бы
        // из двух нулей, а не из проверенных чисел. Расхождение при этом остаётся
        // расхождением и показывается как раньше: если оборот не напечатан, он почти
        // наверняка нулевой, и красное по ненулевому документу это настоящая находка.
        if ($matched && $assumedZero && !$forced) {
            return $row + [
                'outcome'     => AutoAuditResult::UNVERIFIED,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => 'Не удалось проверить: числа сошлись только потому, что '
                    . implode(', ', array_unique($assumedZero)) . ', а второе число тоже нулевое',
            ];
        }

        array_push($notes, ...array_unique($forced));

        foreach (array_unique($assumedZero) as $note) {
            $notes[] = mb_strtoupper(mb_substr($note, 0, 1)) . mb_substr($note, 1) . ', считаем его нулевым';
        }

        // Совпало в пределах допуска, но не до копейки: разницу не прячем.
        if ($matched && $difference != 0.0) {
            $notes[] = 'Разница ' . number_format(abs($difference), 2, ',', ' ') . ' на округлении';
        }

        return $row + [
            'outcome'     => $matched ? AutoAuditResult::MATCHED : AutoAuditResult::MISMATCH,
            'left_value'  => $left,
            'right_value' => $right,
            'difference'  => $difference,
            'reason'      => $notes ? implode('. ', $notes) : null,
        ];
    }

    /**
     * Все ли филиалы сдали отчёт за период и нет ли у кого лишнего.
     *
     * Филиалы считаем множествами, а не числами. Раньше сравнивалось количество файлов с
     * количеством филиалов, и два файла от одного филиала закрывали дыру за второй: суммы
     * складывались, выходило «Совпало» по половине оборота, и ни одной пометки рядом.
     *
     * Задача, закрытая как нулевая, тоже сдача: её ноль стоит среди отчётов.
     *
     * @return array{0: string[], 1: string[]} почему число неизвестно, и пометки к строке
     */
    private function branchIssues(Collection $reportLogs, DocumentPeriod $period, array $reports): array
    {
        $counted = array_column(
            array_filter($reports, fn (array $report) => $report['status'] === self::SOURCE_FORCED_ZERO),
            'log_id',
        );
        $expected = $this->expectedBranches($reportLogs, $period, $counted);
        $filed    = [];
        $unknown  = [];
        $notes    = [];

        foreach ($reports as $report) {
            $filed[(int) $report['branch_id']][] = $report['name'] ?? $report['reason'];
        }

        if (!$expected) {
            $unknown[] = 'не нашли задачу за этот период, и сколько филиалов должны были сдать, неизвестно';
        } elseif ($missing = array_diff($expected, array_keys($filed))) {
            $unknown[] = sprintf(
                'отчёт сдали не все филиалы, %d из %d',
                count($expected) - count($missing),
                count($expected),
            );
        }

        foreach ($filed as $files) {
            if (count($files) > 1) {
                $unknown[] = sprintf(
                    'у одного филиала несколько документов за период (%s)',
                    implode(', ', $files),
                );
            }
        }

        // Документ от филиала, которого мы не ждали: его задачу закрыли принудительно, а файл
        // всё же приложили. Сумму это не портит, но человеку стоит знать, откуда лишний файл.
        if ($expected && array_diff(array_keys($filed), $expected)) {
            $notes[] = 'Среди документов есть отчёт филиала, чья задача закрыта принудительно';
        }

        return [$unknown, $notes];
    }

    /**
     * Филиалы, от которых ждём документ за период: по строке сметы на каждый.
     *
     * Задача идёт следом за периодом: отчёт за июль сдают в августовской задаче. У
     * квартального отчёта окно шире, три месяца после квартала: квартал сдают не день в
     * день, и привязка к одному месяцу отсекала бы задачу, заведённую позже. Раньше окно
     * считалось как «месяц задачи минус один внутри периода», и для квартала оно сходилось
     * только на июльской задаче; на августовской не находилось ни одной, счёт филиалов
     * откатывался на единицу, и недостающий филиал переставал замечаться.
     *
     * Принудительно закрытую задачу не считаем: человек записал, почему документа не будет
     * («только один район», «ежеквартально»). Кроме закрытых как нулевые, которые встали в
     * сверку нулём ($counted): они сдали свой ноль.
     *
     * @return int[] номера строк сметы
     */
    private function expectedBranches(Collection $logs, DocumentPeriod $period, array $counted = []): array
    {
        $months = [];
        $end    = $period->to->startOfMonth();

        for ($i = 1, $length = count($period->months()); $i <= $length; $i++) {
            $months[] = $end->addMonths($i)->format('Y-m');
        }

        return $logs
            ->reject(fn (BuhTaskLog $log) => $log->force_closed && !in_array($log->id, $counted))
            ->filter(fn (BuhTaskLog $log) => in_array(
                CarbonImmutable::create($log->year, $log->month, 1)->format('Y-m'),
                $months,
                true,
            ))
            ->pluck('estimate_item_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
