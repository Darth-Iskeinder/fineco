<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Services\AutoAudit\DocumentPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Итог одной проверки автоаудита у одного клиента за один период.
 *
 * Пишет его только AutoAuditRunner. Строки не стираются: когда итог меняется, у старой
 * строки ставится superseded_at, а новая пишется рядом. Страница показывает только
 * действующие (current), остальное история.
 *
 * Одну и ту же строку в разных прогонах узнаём по ключу (key): клиент, проверка и период,
 * у бед с файлом ещё задача. Отдельной колонки для него нет, он собирается из самой строки.
 */
class AutoAuditResult extends Model
{
    use BelongsToTenant;

    public const MATCHED          = 'matched';           // числа совпали
    public const MISMATCH         = 'mismatch';          // числа разные
    public const UNVERIFIED       = 'unverified';        // одно из чисел прочитать не удалось: вердикта нет
    public const MISSING_DOCUMENT = 'missing_document';  // задача закрыта без файла, или у квартала нет ведомости за месяц
    public const WRONG_DOCUMENT   = 'wrong_document';    // все файлы задачи прочитаны, но нужной формы среди них нет
    public const SCAN             = 'scan';              // среди файлов скан или фото, нужную форму не прочитать
    public const UNREADABLE       = 'unreadable';        // файл не открылся: битый или его нет на диске

    /** Подписи статусов для страницы и сводок, в порядке показа. */
    public const LABELS = [
        self::MATCHED          => 'Совпало',
        self::MISMATCH         => 'Не совпало',
        self::UNVERIFIED       => 'Не удалось проверить',
        self::MISSING_DOCUMENT => 'Нет документа',
        self::WRONG_DOCUMENT   => 'Не тот документ',
        self::SCAN             => 'Скан, не прочитать',
        self::UNREADABLE       => 'Файл не открылся',
    ];

    /** Файлы есть, но прочитать нужную форму не вышло. Период у таких строк взят по задаче. */
    public const DOCUMENT_PROBLEMS = [self::WRONG_DOCUMENT, self::SCAN, self::UNREADABLE];

    protected $fillable = [
        'client_id', 'rule', 'period_from', 'period_to', 'outcome',
        'left_value', 'right_value', 'difference', 'reason', 'sources', 'superseded_at',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to'   => 'date',
        'left_value'  => 'decimal:2',
        'right_value' => 'decimal:2',
        'difference'  => 'decimal:2',
        'sources'     => 'array',
        'superseded_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        // Клиента могли удалить после прогона: строка должна показаться, а не упасть.
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** Действующие строки, без истории. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }

    /**
     * Ключ строки: по нему прогон узнаёт ту же строку в прошлом прогоне.
     *
     * Строка с таким ключом у клиента всегда одна:
     *   - сверка (совпало, не совпало, не удалось проверить): одна на проверку и период;
     *   - нет документа: одна на период. Номера проверок в ключ не входят: их станет
     *     больше, если пропадёт и второй документ, а строка при этом та же;
     *   - беда с файлом: одна на задачу. За один месяц бывает несколько задач (филиалы),
     *     и по периоду их строки слились бы.
     *
     * @param array $row строка как её собирает прогон, или атрибуты записанной строки
     */
    public static function keyOf(array $row): string
    {
        $client = $row['client_id'];
        $period = self::date($row['period_from'] ?? null) . '..' . self::date($row['period_to'] ?? null);

        return match (true) {
            $row['outcome'] === self::MISSING_DOCUMENT => "missing:{$client}:{$period}",
            // Все файлы строки из одной задачи: прогон собирает её по задаче.
            in_array($row['outcome'], self::DOCUMENT_PROBLEMS, true) => "file:{$client}:" . ($row['sources'][0]['log_id'] ?? ''),
            default => "check:{$client}:{$row['rule']}:{$period}",
        };
    }

    public function key(): string
    {
        // Не attributesToArray: он отдаёт даты в UTC, и в Бишкеке 1 июля стало бы 30 июня.
        return self::keyOf([
            'client_id'   => $this->client_id,
            'rule'        => $this->rule,
            'period_from' => $this->period_from,
            'period_to'   => $this->period_to,
            'outcome'     => $this->outcome,
            'sources'     => $this->sources,
        ]);
    }

    /**
     * Тот же ли итог у новой строки прогона: статус и числа.
     *
     * Остальное (текст причины, файлы, ФИО исполнителя) истории не заслуживает: поменяли
     * формулировку в коде или заменили файл на такой же, итог от этого не стал другим.
     * Суммы сравниваем строкой до копеек: из базы они приходят как «1200000.00», из прогона
     * числом, и напрямую одинаковое показалось бы разным.
     */
    public function sameVerdict(array $row): bool
    {
        if ($this->outcome !== $row['outcome']) {
            return false;
        }

        foreach (['left_value', 'right_value', 'difference'] as $field) {
            if (self::money($this->{$field}) !== self::money($row[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function money(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    private static function date(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    public function ruleName(): string
    {
        return AutoAuditRunner::RULES[(int) $this->rule]['name'] ?? (string) $this->rule;
    }

    /** Номера проверок строки. У «нет документа» их может быть несколько: «1,3». */
    public function ruleNumbers(): array
    {
        return array_map('intval', array_filter(explode(',', (string) $this->rule), 'strlen'));
    }

    /**
     * Период взят по месяцу задачи, а не прочитан из документа.
     *
     * Так у всех строк про беду с файлом и у «нет документа» по задаче без файла. У «нет
     * документа» по кварталу период взят из самого отчёта. Отличаем по длине: задача всегда
     * даёт один месяц, а квартал три.
     */
    public function periodFromTask(): bool
    {
        if (in_array($this->outcome, self::DOCUMENT_PROBLEMS, true)) {
            return true;
        }

        return $this->outcome === self::MISSING_DOCUMENT
            && $this->period_from
            && $this->period_to
            && $this->period_from->isSameMonth($this->period_to);
    }

    /** Ключ периода для фильтра на странице: «2026-07-01..2026-07-31». */
    public function periodKey(): string
    {
        return $this->period_from?->toDateString() . '..' . $this->period_to?->toDateString();
    }

    /** «июль 2026», «2 квартал 2026», всё остальное датами. */
    public function periodLabel(): string
    {
        if (!$this->period_from || !$this->period_to) {
            return 'период не разобран';
        }

        // Неизменяемые копии: у обычной даты расчёт конца месяца сдвигает её саму.
        return (new DocumentPeriod($this->period_from->toImmutable(), $this->period_to->toImmutable()))->title();
    }
}
