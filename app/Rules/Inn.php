<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ИНН клиента: ровно четырнадцать цифр и не заглушка.
 *
 * Раньше брали любую строку до 14 знаков, и ИНН портился молча. 13.10.2026 у КЛВ нашлось
 * 73 организации без ведущих нулей (Excel считает ИНН числом и отбрасывает их), а у Fineco
 * ИНН без двух последних цифр, обрезанные колонкой в 12 знаков. Сверка ИНН в автоаудите у
 * таких клиентов не работала. Нули тогда восстановили, а здесь не дописываем их сами:
 * Искендер решил, что ИНН основа и вводиться должен верно, а ошибка должна быть видна.
 *
 * Заглушки вида «00000000000003» не берём: настоящий ИНН не начинается с пяти нулей, у
 * организации там ноль и дата регистрации, у человека единица или двойка. Иностранных
 * клиентов, у которых ИНН был бы другим, у фирм нет.
 */
class Inn implements ValidationRule
{
    /** @param bool $fromFile строка загрузки из файла: подсказать, что ноль срезал Excel */
    public function __construct(private readonly bool $fromFile = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $inn = (string) $value;

        if (preg_match('/^\d{14}$/', $inn) === 1) {
            if (str_starts_with($inn, '00000')) {
                $fail('Это не настоящий ИНН: он не начинается с пяти нулей');
            }

            return;
        }

        $digits = preg_replace('/\D+/', '', $inn);

        if ($digits !== $inn) {
            $fail('ИНН пишется одними цифрами, без пробелов и других знаков');

            return;
        }

        if (strlen($inn) < 14 && self::lostLeadingZeros($inn)) {
            $fail(sprintf(
                'В ИНН %d цифр, а нужно 14. Похоже, потерялся ноль в начале: ИНН организации начинается с 0 и даты регистрации%s',
                strlen($inn),
                $this->fromFile ? '. Excel убирает такие нули: сделайте колонку ИНН текстовой' : '',
            ));

            return;
        }

        $fail('ИНН состоит из 14 цифр');
    }

    /** Дописать нули слева, и выйдет ИНН организации: ноль и настоящая дата регистрации. */
    private static function lostLeadingZeros(string $inn): bool
    {
        $full = str_pad($inn, 14, '0', STR_PAD_LEFT);
        [$day, $month, $year] = [(int) substr($full, 1, 2), (int) substr($full, 3, 2), (int) substr($full, 5, 4)];

        return $full[0] === '0' && $year >= 1991 && checkdate($month, $day, $year)
            && mktime(0, 0, 0, $month, $day, $year) <= time();
    }
}
