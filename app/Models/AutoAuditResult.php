<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Services\AutoAudit\DocumentPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Итог одной проверки автоаудита у одного клиента за один период.
 *
 * Пишет его только AutoAuditRunner, целиком заменяя результаты фирмы при каждом прогоне.
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
        'left_value', 'right_value', 'difference', 'reason', 'sources',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to'   => 'date',
        'left_value'  => 'decimal:2',
        'right_value' => 'decimal:2',
        'difference'  => 'decimal:2',
        'sources'     => 'array',
    ];

    public function client(): BelongsTo
    {
        // Клиента могли удалить после прогона: строка должна показаться, а не упасть.
        return $this->belongsTo(Client::class)->withTrashed();
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
