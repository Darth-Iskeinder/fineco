<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Запрос к данным фирмы там, где неизвестно, чья это фирма.
 *
 * Падение здесь — не поломка, а защита. Без него запрос ушёл бы по всей базе
 * и тихо задел бы все аккаунты сразу: обновил цены не той фирме, удалил чужие
 * задачи, показал чужих клиентов. Ошибка видна сразу, тихая порча данных — нет.
 *
 * Если код обязан работать поверх фирм (копирование образца, супер-админка),
 * это оформляется явно: TenantContext::withoutTenant(...) или ->acrossTenants().
 */
class TenantContextMissing extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "Неизвестно, с какой фирмой работаем ({$model}). " .
            'Оберните вызов в TenantContext::for($tenant, ...), ' .
            'а если работа поверх фирм задумана — в TenantContext::withoutTenant(...).'
        );
    }
}
