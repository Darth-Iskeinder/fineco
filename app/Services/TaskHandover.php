<?php

namespace App\Services;

use App\Models\BuhTaskLog;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Передача начатой работы при смене исполнителя задачи.
 *
 * Задачу видит тот, кто сейчас её исполнитель: исполнитель позиции сметы, а без него
 * ответственный клиента (у подпункта берём родителя). Если задачу перевели на другого,
 * незакрытая запись прежнего раньше оставалась «на паузе» и пропадала с экрана: прежний
 * её больше не видел, новый начинал с нуля, а таймер мог идти дальше сутками.
 *
 * Теперь такая запись получает статус «Передана»: таймер останавливается на моменте
 * передачи, время остаётся за тем, кто его потратил, и запоминается, кому и когда отдали.
 * Прежний видит её во «Выполненных», новый в карточке задачи строкой «До вас».
 *
 * Обратный случай тоже здесь: задачу вернули тому, кто её уже начинал. Его «Передана»
 * снова становится обычной записью, и он продолжает со своим временем. Иначе ему пришлось
 * бы завести вторую запись на ту же задачу, а база такого не допускает.
 *
 * Вызывать после любой смены исполнителя или ответственного. Повторный вызов ничего
 * не меняет: смотрит только на то, что расходится с текущим исполнителем.
 */
class TaskHandover
{
    private const OPEN = ['pending', 'running', 'paused', 'rework'];

    /**
     * @return Collection<int, array{log: BuhTaskLog, action: string, to: ?int}>
     *         action: handed (передана) или returned (вернулась прежнему)
     */
    public function forClient(Client $client, bool $apply = true): Collection
    {
        $changes = collect();

        $logs = BuhTaskLog::where('client_id', $client->id)
            ->whereIn('status', [...self::OPEN, BuhTaskLog::STATUS_HANDED])
            ->with('estimateItem.parent')
            ->get();

        foreach ($logs as $log) {
            $item = $log->estimateItem;
            if (!$item) {
                continue; // позицию удалили из сметы: передавать некому
            }

            $doer = (int) (($item->parent ?? $item)->assignee_id ?? $client->responsible_employee_id);
            if ($doer === 0) {
                continue; // исполнителя нет вовсе: работу не у кого и некому забирать
            }

            if ($log->status === BuhTaskLog::STATUS_HANDED) {
                if ($doer === (int) $log->employee_id) {
                    $changes->push(['log' => $log, 'action' => 'returned', 'to' => null]);
                    $apply && $this->giveBack($log);
                }
            } elseif ($doer !== (int) $log->employee_id) {
                $changes->push(['log' => $log, 'action' => 'handed', 'to' => $doer]);
                $apply && $this->handOver($log, $doer);
            }
        }

        return $changes;
    }

    private function handOver(BuhTaskLog $log, int $to): void
    {
        DB::transaction(function () use ($log, $to) {
            // Тот же замок, что у кнопки «Старт»: человек мог как раз сейчас жать на таймер.
            BuhTaskLog::lockClockOwner($log->employee_id);
            $log->refresh();

            if (!in_array($log->status, self::OPEN, true)) {
                return;
            }

            $now = now();
            $log->bankWorkedTime($now); // шёл таймер: время до минуты передачи остаётся за ним
            $log->status       = BuhTaskLog::STATUS_HANDED;
            $log->handed_to_id = $to;
            $log->handed_at    = $now;
            $log->save();
        });
    }

    private function giveBack(BuhTaskLog $log): void
    {
        $log->status       = $log->started_at ? 'paused' : 'pending';
        $log->handed_to_id = null;
        $log->handed_at    = null;
        $log->save();
    }
}
