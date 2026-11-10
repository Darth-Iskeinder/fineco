<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Смена ответственного за клиента: был такой, стал такой, когда и кто поменял.
 *
 * Пишет модель клиента (Client::booted) при любом сохранении с новым ответственным:
 * карточка, импорт, увольнение сотрудника. Читает «Закрытие месяца», см. responsibleAt().
 */
class ClientResponsibleChange extends Model
{
    use BelongsToTenant;

    protected $fillable = ['client_id', 'previous_employee_id', 'employee_id', 'changed_by', 'changed_at'];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    /**
     * Кто вёл клиента в момент $at по списку его смен (по возрастанию changed_at).
     *
     * Смен нет: нынешний ответственный. Момент раньше первой смены: тот, кто был до неё.
     *
     * @param iterable<self> $changes
     * @return array{0: ?int, 1: bool} [сотрудник, известно ли из истории]
     */
    public static function responsibleAt(iterable $changes, ?int $current, \DateTimeInterface $at): array
    {
        $found = null;
        $first = null;

        foreach ($changes as $change) {
            $first ??= $change;

            if ($change->changed_at <= $at) {
                $found = $change;
            }
        }

        return match (true) {
            $found !== null => [$found->employee_id ? (int) $found->employee_id : null, true],
            $first !== null => [$first->previous_employee_id ? (int) $first->previous_employee_id : null, false],
            default         => [$current, false],
        };
    }
}
