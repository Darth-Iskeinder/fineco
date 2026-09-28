<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditFinding;
use App\Models\AutoAuditResult;
use RuntimeException;

/**
 * Запись итогов автоаудита: строки прогона против действующих, история вместо удаления,
 * находки (вопросы бухгалтерам) по проблемным строкам. И пробный прогон: та же сверка с
 * действующими строками, но без записи.
 *
 * Вынесено из AutoAuditRunner 28.09.2026 без смены поведения. Транзакцию держит
 * AutoAuditRunner::run: строки и находки пишутся вместе или никак.
 *
 * Все запросы идут через модели с правилом фирмы: строки других фирм сюда не попадают.
 */
class AutoAuditStore
{
    /**
     * Что изменит запись строк, без записи. Ключ и вердикт те же, что у store.
     *
     * @return array{
     *     added: array<int, array>,
     *     changed: array<int, array{0: AutoAuditResult, 1: array}>,
     *     gone: array<int, AutoAuditResult>,
     *     kept: int
     * }
     */
    public function preview(array $rows): array
    {
        $current = AutoAuditResult::current()->orderBy('id')->get()->keyBy(fn (AutoAuditResult $r) => $r->key());
        $diff    = ['added' => [], 'changed' => [], 'gone' => [], 'kept' => 0];

        foreach ($rows as $row) {
            $key      = AutoAuditResult::keyOf($row);
            $previous = $current->pull($key);

            if (!$previous) {
                $diff['added'][] = $row;
            } elseif ($previous->sameVerdict($row)) {
                $diff['kept']++;
            } else {
                $diff['changed'][] = [$previous, $row];
            }
        }

        $diff['gone'] = $current->values()->all();

        return $diff;
    }

    /**
     * Открыть и закрыть находки по действующим строкам. Идёт сразу после store, в той же
     * транзакции.
     *
     * По ключу строки:
     *   - итог проблемный (FINDING_OUTCOMES), открытой находки нет: открываем. Висит она с
     *     того дня, когда появилась строка: при первом прогоне после выкатки это честнее,
     *     чем день выкатки;
     *   - итог проблемный, находка открыта: не трогаем, даже если итог сменился с одного
     *     проблемного на другой. Разговор тот же;
     *   - итог стал другим (совпало, скан, не удалось проверить) или строки нет: закрываем.
     *     Ничего не удаляем, переписка остаётся.
     */
    public function syncFindings(): array
    {
        $now     = now();
        $changes = ['opened' => 0, 'closed' => 0, 'open' => 0];

        // Ключ у действующих строк один на строку, это держит store.
        $current = AutoAuditResult::current()->orderBy('id')->get()->keyBy(fn (AutoAuditResult $r) => $r->key());

        $open = [];

        foreach (AutoAuditFinding::open()->orderBy('id')->get() as $finding) {
            // Двух открытых с одним ключом быть не должно. Если вышло, оставляем старшую: у
            // неё переписка. Младшую закрываем, иначе строка показала бы чужой разговор.
            if (isset($open[$finding->key])) {
                $finding->update(['closed_at' => $now]);

                continue;
            }

            $open[$finding->key] = $finding;
        }

        foreach ($current as $key => $result) {
            if (!in_array($result->outcome, AutoAuditResult::FINDING_OUTCOMES, true)) {
                continue;
            }

            $changes['open']++;

            if (isset($open[$key])) {
                unset($open[$key]);

                continue;
            }

            AutoAuditFinding::create([
                'client_id' => $result->client_id,
                'key'       => $key,
                'opened_at' => $result->created_at,
            ]);
            $changes['opened']++;
        }

        // Остались находки, у которых проблемной строки больше нет.
        foreach ($open as $key => $finding) {
            $finding->update(['closed_at' => $now, 'closed_outcome' => $current[$key]->outcome ?? null]);
            $changes['closed']++;
        }

        return $changes;
    }

    /**
     * Сверить строки прогона с действующими и записать разницу.
     *
     * По ключу строки (AutoAuditResult::keyOf):
     *   - итог тот же: строку обновляем на месте. Причина, файлы и ФИО могли поменяться,
     *     а после замены файла ссылка иначе вела бы на удалённый. Время обновления при этом
     *     сдвигается всегда: по нему страница пишет «Данные на»;
     *   - итог другой: прежней ставим superseded_at, новую пишем рядом;
     *   - строки больше нет (исправили, клиента удалили, сняли с полного обслуживания):
     *     прежней ставим superseded_at. Ничего не удаляем;
     *   - строка новая: пишем.
     */
    public function store(array $rows): array
    {
        $now     = now();
        $changes = ['kept' => 0, 'changed' => 0, 'added' => 0, 'gone' => 0];

        // Правило фирмы на модели: строки других фирм сюда не попадают.
        $current = [];

        foreach (AutoAuditResult::current()->orderBy('id')->get() as $result) {
            $key = $result->key();

            // Двух действующих с одним ключом быть не должно. Если всё же вышло, лишнюю
            // закрываем, иначе она висела бы на странице вечно.
            if (isset($current[$key])) {
                $current[$key]->update(['superseded_at' => $now]);
            }

            $current[$key] = $result;
        }

        $seen = [];

        foreach ($rows as $row) {
            $key = AutoAuditResult::keyOf($row);

            // Два одинаковых ключа в одном прогоне значат ошибку в ключе: строки перепутались
            // бы между прогонами. Лучше упасть, ничего не записав, чем тихо копить путаницу.
            if (isset($seen[$key])) {
                throw new RuntimeException("Две строки автоаудита с одним ключом {$key}. Результаты прошлого прогона не тронуты");
            }

            $seen[$key] = true;
            $previous   = $current[$key] ?? null;
            unset($current[$key]);

            if ($previous && $previous->sameVerdict($row)) {
                $previous->fill($row);
                $previous->updated_at = $now;
                $previous->save();
                $changes['kept']++;

                continue;
            }

            if ($previous) {
                $previous->update(['superseded_at' => $now]);
                $changes['changed']++;
            } else {
                $changes['added']++;
            }

            AutoAuditResult::create($row);
        }

        foreach ($current as $gone) {
            $gone->update(['superseded_at' => $now]);
            $changes['gone']++;
        }

        return $changes;
    }
}
