<?php

namespace App\Services\AutoAudit;

use InvalidArgumentException;

/**
 * Разбор списка счетов ОСВ, который вводят для проверки автоаудита.
 *
 * Одно правило на команду autoaudit:accounts и страницу «Настройки → Автоаудит», чтобы
 * они не разошлись в том, что считать счётом.
 */
class AccountList
{
    /**
     * «3410, 3490» => ['3410', '3490']. Пустой массив значит «общий счёт проверки»: один
     * общий счёт храним как отсутствие настройки, так фирма не отрывается от общего правила.
     *
     * @return string[]
     * @throws InvalidArgumentException с текстом для человека
     */
    public static function parse(string $input, string $default): array
    {
        $accounts = array_values(array_unique(preg_split('/[\s,;]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY)));

        if (!$accounts) {
            throw new InvalidArgumentException('Пустой список счетов');
        }

        foreach ($accounts as $account) {
            if (!preg_match('/^\d{2,6}(\.\d{1,3})?$/', $account)) {
                throw new InvalidArgumentException("«{$account}» не похоже на счёт ОСВ: нужны цифры, например 3410");
            }
        }

        return $accounts === [$default] ? [] : $accounts;
    }
}
