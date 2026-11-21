<?php

namespace App\Services\Microworld;

use App\Models\BuhTaskLog;
use Carbon\CarbonImmutable;

/**
 * Правило «в срок» для «Моего микромира» (согласовано 17.11.2026).
 *
 * В срок считается дата, когда сотрудник **сдал** задачу, а не когда главбух её принял.
 * Иначе бухгалтер, сдавший вовремя, терял бы процент из-за медленной проверки.
 *
 *  - ON_TIME: сдана на проверку или закрыта не позже срока;
 *  - LATE:    сдана, но позже срока;
 *  - OVERDUE: срок прошёл, а задача не сдана;
 *  - OPEN:    срок ещё не наступил, процент не портит.
 *
 * Процент месяца = (ON_TIME + LATE) ÷ все задачи месяца: сданное засчитывается, даже
 * если поздно (решено 17.11.2026). Опоздание видно отдельным числом.
 */
final class OnTimeRule
{
    public const ON_TIME = 'on_time';
    public const LATE    = 'late';
    public const OVERDUE = 'overdue';
    public const OPEN    = 'open';

    /** Статусы, при которых задача уже сдана исполнителем. */
    public const SUBMITTED = ['completed', 'review'];

    public static function classify(string $status, ?CarbonImmutable $due, ?BuhTaskLog $log, CarbonImmutable $today): string
    {
        if (in_array($status, self::SUBMITTED, true)) {
            $at = self::submittedRaw($log);

            // Без срока или без даты сдачи считаем в срок: доказать опоздание нечем.
            // Сравниваем секунды, а не даты Carbon: зовётся на каждую задачу страницы.
            return (!$due || !$at || $at->getTimestamp() <= $due->endOfDay()->getTimestamp()) ? self::ON_TIME : self::LATE;
        }

        return ($due && $due->lt($today)) ? self::OVERDUE : self::OPEN;
    }

    /**
     * Когда исполнитель сдал задачу.
     *
     * При сдаче на проверку пишется review_started_at, при закрытии без проверки и при
     * приёмке completed_at. Поле review_started_at есть с 22.06.2026, у более старых и у
     * части перенесённых задач оно пустое: тогда берём что есть. Для «на проверке» это
     * updated_at, задача с момента сдачи почти не меняется.
     */
    public static function submittedAt(?BuhTaskLog $log): ?CarbonImmutable
    {
        $at = self::submittedRaw($log);

        return $at ? CarbonImmutable::instance($at) : null;
    }

    /** То же без копии в CarbonImmutable, она дорогая на сотнях задач. */
    private static function submittedRaw(?BuhTaskLog $log): ?\DateTimeInterface
    {
        if (!$log) {
            return null;
        }

        $at = match ($log->status) {
            'review'    => $log->review_started_at ?? $log->updated_at,
            'completed' => $log->reviewed_at ? ($log->review_started_at ?? $log->completed_at) : $log->completed_at,
            default     => null,
        };

        // Поля дат приходят Carbon из кастов модели, строка только на всякий случай
        return match (true) {
            !$at                              => null,
            $at instanceof \DateTimeInterface => $at,
            default                           => CarbonImmutable::parse($at),
        };
    }
}
