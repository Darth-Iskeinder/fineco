<?php

namespace App\Services\AutoAudit;

use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Откуда автоаудит берёт числа: задачи и документы клиента по эталонным БП, чтение файла
 * читалкой со сверкой ИНН, и запись «откуда взято» для страницы.
 *
 * Вынесено из AutoAuditRunner 28.09.2026 без смены поведения, чтобы сбор источников и
 * сверка менялись отдельно. Сверку (пары, филиалы, вердикт) ведёт AutoAuditRunner.
 *
 * Один экземпляр на прогон: разбор файла запоминается, чтобы читать файл один раз на
 * каждое число. reset() в начале прогона.
 */
class AutoAuditSources
{
    /**
     * Документы, из которых берутся числа. Ключ: сторона сверки.
     *
     * ref:      эталонный номер БП, к задачам которого документ прикладывают;
     * label:    как подписать файл на странице;
     * genitive: «нет …» в причине;
     * probe:    чем проверить, что файл вообще нужная форма. Форму и период читалка
     *           проверяет до того, как искать число, поэтому годится любое поле;
     * has_inn:  есть ли в шапке формы ИНН организации. У ведомости его нет по природе, и без
     *           этого признака требование «сверь ИНН» выключило бы автоаудит целиком.
     */
    public const SIDES = [
        'osv'  => ['ref' => AutoAuditRunner::REF_BALANCE_SHEET, 'label' => 'ОСВ',         'genitive' => 'оборотно-сальдовой ведомости', 'probe' => '3210',  'has_inn' => false],
        'tax'  => ['ref' => AutoAuditRunner::REF_TAX_REPORT,    'label' => 'Отчёт по ЕН', 'genitive' => 'отчёта по единому налогу',     'probe' => 'base',  'has_inn' => true],
        'f161' => ['ref' => AutoAuditRunner::REF_FORM_161,      'label' => 'Форма 161',   'genitive' => 'формы 161',                    'probe' => 'income', 'has_inn' => true],
    ];

    /** Начало причины «документ чужой». Собираем и узнаём её в одном месте, чтобы не разошлись. */
    public const INN_MISMATCH = 'ИНН не совпадает';

    /** Картинки вместо PDF или Excel: скан или фото, без распознавания прочитать нечем. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'webp', 'heic'];

    /** Задачи, документам которых верим: работа закрыта или сдана на проверку. */
    private const DONE_STATUSES = ['completed', 'review'];

    /** Прочитанное за прогон: один файл разбираем один раз на каждое число. */
    private array $cache = [];

    public function __construct(
        private readonly BalanceSheetReader $balanceSheet,
        private readonly SingleTaxReportReader $taxReport,
        private readonly Form161Reader $form161,
    ) {}

    /** Забыть прочитанное: файлы могли поменяться с прошлого прогона. */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Документы закрытых задач клиента по одному БП, последние загруженные первыми.
     *
     * @return Collection<int, array{0: BuhTaskLog, 1: BuhTaskDocument}>
     */
    public function documents(Client $client, Service $service): Collection
    {
        return BuhTaskLog::where('client_id', $client->id)
            ->whereIn('status', self::DONE_STATUSES)
            ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
            ->with(['documents', 'employee:id,full_name'])
            ->get()
            ->flatMap(fn (BuhTaskLog $log) => $log->documents->map(fn (BuhTaskDocument $document) => [$log, $document]))
            ->sortByDesc(fn (array $pair) => $pair[1]->id)
            ->values();
    }

    /**
     * Принудительно закрытые без файла задачи, где бухгалтер выбрал «Нулевой» или
     * «Освобождён»: документа нет, потому что по нему ноль. Такую задачу сверка берёт
     * нулём и сравнивает со второй стороной по факту (Искендер, 28.09.2026).
     *
     * «Раз в квартал», «Другое» и старые задачи без выбора сюда не попадают: там ноль не
     * заявлен. Квартал проверит квартальный отчёт, а про «Другое» мы не знаем ничего.
     */
    public function forcedZeros(Client $client, Service $service): Collection
    {
        return BuhTaskLog::where('client_id', $client->id)
            ->whereIn('status', self::DONE_STATUSES)
            ->where('force_closed', true)
            ->whereIn('force_close_reason', [BuhTaskLog::FORCE_ZERO, BuhTaskLog::FORCE_EXEMPT])
            ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
            ->whereDoesntHave('documents')
            ->with('employee:id,full_name')
            ->orderBy('year')->orderBy('month')
            ->get();
    }

    /**
     * Период принудительно закрытой задачи: месяц перед месяцем задачи, как у задачи без
     * файла. Файла нет, прочитать период больше неоткуда.
     */
    public static function periodOfTask(BuhTaskLog $log): DocumentPeriod
    {
        // С первого числа, иначе «31 августа минус месяц» перельётся мимо июля.
        $month = CarbonImmutable::create($log->year, $log->month, 1)->subMonth();

        return DocumentPeriod::of($month->year, $month->month);
    }

    /** Источник «закрыта принудительно: нулевой». Число 0, файла нет, видно чья задача. */
    public function forcedSource(string $side, BuhTaskLog $log): array
    {
        return array_merge($this->missingSource($side, $log), [
            'status' => AutoAuditRunner::SOURCE_FORCED_ZERO,
            'value'  => 0.0,
            'reason' => 'закрыта принудительно: «'
                . (BuhTaskLog::FORCE_REASONS[$log->force_close_reason][1] ?? 'Нулевой') . '»',
        ]);
    }

    /** Все задачи клиента по БП в любом статусе: по ним считаем, от скольких филиалов ждать документ. */
    public function taskLogs(Client $client, Service $service): Collection
    {
        return BuhTaskLog::where('client_id', $client->id)
            ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
            ->get(['id', 'estimate_item_id', 'year', 'month', 'force_closed']);
    }

    /**
     * Закрытые или сданные на проверку задачи клиента по БП, где нет ни одного файла.
     *
     * Принудительно закрытые не берём: человек записал причину, почему файла не будет.
     */
    public function closedWithoutFiles(Client $client, Service $service): Collection
    {
        return BuhTaskLog::where('client_id', $client->id)
            ->whereIn('status', self::DONE_STATUSES)
            ->where(fn ($q) => $q->where('force_closed', false)->orWhereNull('force_closed'))
            ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
            ->whereDoesntHave('documents')
            ->with('employee:id,full_name')
            ->orderBy('year')->orderBy('month')
            ->get();
    }

    /**
     * Источник «задача закрыта без файла». Файла нет, поэтому document_id и name пустые,
     * а status missing. Страница по нему пишет, чья задача, а исполнитель получает вопрос.
     */
    public function missingSource(string $side, BuhTaskLog $log): array
    {
        return [
            'side'        => $side,
            'label'       => self::SIDES[$side]['label'],
            'log_id'      => $log->id,
            'branch_id'   => $log->estimate_item_id,
            'task_month'  => sprintf('%02d.%d', $log->month, $log->year),
            'employee'    => $this->shortName($log->employee?->full_name),
            'document_id' => null,
            'name'        => null,
            'status'      => AutoAuditRunner::SOURCE_MISSING,
            'value'       => null,
            'reason'      => 'файл не приложен',
        ];
    }

    /**
     * Одно число из документа клиента.
     *
     * @param string $field для ОСВ номер счёта, для второго документа его поле
     */
    public function read(Client $client, string $side, string $field, BuhTaskDocument $document): DocumentValue
    {
        return $this->cache["{$side}:{$field}:{$document->id}"] ??= $this->checkInn($client, $side, $this->readFile($side, $field, $document));
    }

    /**
     * Оборот ведомости по счетам проверки. Один счёт читаем как раньше, несколько
     * складываем (DocumentValue::sum): так фирма задаёт свои счета вместо общего.
     *
     * @param string[] $accounts
     */
    public function readAccounts(Client $client, array $accounts, BuhTaskDocument $document): DocumentValue
    {
        if (count($accounts) === 1) {
            return $this->read($client, 'osv', reset($accounts), $document);
        }

        $values = [];

        foreach ($accounts as $account) {
            $values[$account] = $this->read($client, 'osv', $account, $document);
        }

        return DocumentValue::sum($values);
    }

    /**
     * ИНН в шапке документа против ИНН в карточке клиента.
     *
     * Без этой проверки чужая форма дала бы «не совпало» по числам, и расхождение искали бы
     * в учёте, хотя перепутан файл. Так нашлась форма 161 «Нова Трек» у «Нова трек плюс».
     *
     * Кто ошибся, документ или карточка, мы не знаем: у ИНАМ отчёт и форма 161 показывают
     * один и тот же ИНН, а в карточке записан другой. Поэтому причину пишем без обвинения.
     *
     * Раньше сверка молча выключалась в трёх случаях: ИНН не прочитался из документа, в
     * карточке не 14 цифр, в карточке заглушка вроде «00000000000003» (клиентов заводили,
     * пока ИНН не знали). Во всех трёх чужой документ проходил как свой, а строка выглядела
     * проверенной. Теперь это «не удалось проверить»: защиты не было, и делать вид, что она
     * сработала, нельзя. Настоящий ИНН не начинается с пяти нулей: у организации там ноль и
     * дата регистрации, у человека единица или двойка.
     *
     * В ведомости ИНН нет вовсе, а у скана, чужого бланка и битого файла есть свой, более
     * точный исход. И то, и другое проходит мимо сверки нетронутым.
     */
    private function checkInn(Client $client, string $side, DocumentValue $value): DocumentValue
    {
        // Период читалка отдаёт, только когда форма опознана: это и есть признак, что перед
        // нами нужный бланк и разговор про его ИНН вообще имеет смысл.
        if (!self::SIDES[$side]['has_inn'] || $value->period === null) {
            return $value;
        }

        $clientInn = preg_replace('/\D+/', '', (string) $client->inn);
        $cardIsOk  = strlen($clientInn) === 14 && !str_starts_with($clientInn, '00000');

        if ($value->inn !== null && $cardIsOk) {
            return $value->inn === $clientInn ? $value : DocumentValue::wrongDocument(sprintf(
                '%s: в документе %s, в карточке клиента %s. Документ чужой или ошибка в карточке',
                self::INN_MISMATCH,
                $value->inn,
                $clientInn,
            ));
        }

        // Число и так не прочитано: своя причина у строки точнее нашей.
        if ($value->status === DocumentValue::UNCERTAIN) {
            return $value;
        }

        return DocumentValue::uncertain(
            $value->inn === null
                ? 'ИНН в документе не прочитан: проверить, что документ принадлежит этому клиенту, нельзя'
                : "В карточке клиента нет ИНН из 14 цифр (записано «{$client->inn}»): сверить документ с клиентом не с чем",
            $value->trace,
            $value->period,
            $value->inn,
        );
    }

    private function readFile(string $side, string $field, BuhTaskDocument $document): DocumentValue
    {
        $path = Storage::disk('local')->path($document->path);

        if (!is_readable($path)) {
            // Файл, до которого нет прав, снаружи выглядит так же, как удалённый. Но это
            // поломка запуска, а не документа: прогон не от того пользователя честно записал бы
            // всем «Файл не открылся» и стёр настоящие результаты. Так было на бою 23.09.2026.
            if ($this->accessDenied($path)) {
                throw new RuntimeException(
                    "Нет прав на чтение файлов документов (первый: «{$document->name}»). "
                    . 'Запускайте проверку от пользователя веб-сервера (www-data). Прошлые результаты не тронуты',
                );
            }

            return DocumentValue::unreadable('Файла нет на диске');
        }

        // Картинку не открыть ни как таблицу, ни как PDF. Документ на ней может быть и тем,
        // просто прочитать его без распознавания нечем.
        if (in_array(strtolower(pathinfo($document->path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
            return DocumentValue::scan('Это картинка, а не PDF или Excel');
        }

        try {
            return match ($side) {
                'osv'  => $this->balanceSheet->turnover($path, $field, 'credit'),
                'tax'  => $field === 'tax' ? $this->taxReport->totalTax($path) : $this->taxReport->taxableBase($path),
                'f161' => $this->form161->read($path, $field),
            };
        } catch (Throwable $e) {
            // Один кривой файл не должен ронять проверку всей фирмы.
            return DocumentValue::unreadable(class_basename($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Файл не читается из-за прав, а не потому, что его нет.
     *
     * Сам файл в закрытой папке не виден вовсе, поэтому идём вверх до первой папки, которая
     * существует. Войти в неё нельзя, значит, дело в правах. Можно, значит, файла и правда нет.
     */
    private function accessDenied(string $path): bool
    {
        if (file_exists($path)) {
            return true;
        }

        $dir = dirname($path);

        while (!file_exists($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }

        return !is_executable($dir);
    }

    /** Откуда взято число: по этому человек откроет файл и проверит вывод сам. */
    public function source(string $side, BuhTaskLog $log, BuhTaskDocument $document, DocumentValue $value): array
    {
        $source = [
            'side'        => $side,
            'label'       => self::SIDES[$side]['label'],
            'log_id'      => $log->id,
            // Филиал: у филиального БП своя строка сметы на каждый налоговый орган. По ней
            // сверка понимает, кто из филиалов сдал, а кто нет.
            'branch_id'   => $log->estimate_item_id,
            'task_month'  => sprintf('%02d.%d', $log->month, $log->year),
            'employee'    => $this->shortName($log->employee?->full_name),
            'document_id' => $document->id,
            'name'        => $document->name,
            'status'      => $value->status,
            'value'       => $value->value,
            'reason'      => $value->reason,
        ];

        // Раскладку пишем только там, где счетов несколько: у остальных источник остаётся
        // ровно прежним, и прогон не примет его за изменившийся итог.
        if ($value->parts) {
            $source['parts'] = $value->parts;
        }

        return $source;
    }

    /**
     * Исполнитель задачи коротко: «Обозова Айзада Алмасбековна» становится «Обозова А. А.».
     * Полные ФИО раздувают колонку с документами.
     */
    private function shortName(?string $fullName): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY);

        if (!$parts) {
            return null;
        }

        $initials = array_map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)) . '.', array_slice($parts, 1));

        return trim($parts[0] . ' ' . implode(' ', $initials));
    }
}
