<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Количество и цена переезжают на основной БП.
 *
 * Раньше у БП с подпунктами и количество, и сумма собирались по подпунктам: счётчик
 * у основного БП в смете вообще пропадал, как только включали первый подпункт, а его
 * сумма была суммой подпунктов. Теперь подпункт задаёт только состав работы, а число
 * и деньги живут на основном БП.
 *
 * Что делаем с накопленным:
 *  - количества подпунктов складываем в основной БП. Ставка у группы одна (подпункты
 *    наследуют её от родителя), поэтому «ставка × сумма количеств» даёт ровно ту же
 *    сумму, что была;
 *  - у подпунктов гасим количество, цену и сумму;
 *  - сумму строки пересчитываем по новой формуле и заново складываем итог сметы.
 *
 * Строки, у которых сумма всё же изменилась (цена подпункта отличалась от цены
 * основного БП — так бывает у старых записей без режима биллинга), печатаем в вывод
 * миграции: их стоит просмотреть глазами после выкатки.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Количество у подпунктов больше не задаётся нигде: ни в каталоге, ни в смете.
        DB::table('services')->whereNotNull('parent_id')->update(['allows_quantity' => false]);

        $parentIds = DB::table('estimate_items')
            ->whereNotNull('parent_id')
            ->distinct()
            ->pluck('parent_id');

        if ($parentIds->isEmpty()) {
            return;
        }

        $changed     = [];
        $estimateIds = [];

        foreach ($parentIds->chunk(200) as $chunk) {
            $parents = DB::table('estimate_items')->whereIn('id', $chunk)->get();

            foreach ($parents as $parent) {
                $children = DB::table('estimate_items')->where('parent_id', $parent->id)->get();

                // Количество разрешено самим БП. У своей услуги (без БП) его нет.
                $allowsQuantity = $parent->service_id
                    && DB::table('services')->where('id', $parent->service_id)->value('allows_quantity');

                $quantity = $allowsQuantity
                    ? max(1, (int) $children->sum('quantity'))
                    : 1;
                $total = round((float) $parent->cost * $quantity, 2);

                DB::table('estimate_items')->where('id', $parent->id)
                    ->update(['quantity' => $quantity, 'total' => $total]);

                DB::table('estimate_items')->where('parent_id', $parent->id)
                    ->update(['quantity' => 1, 'cost' => 0, 'total' => 0]);

                $estimateIds[$parent->estimate_id] = true;

                if (abs($total - (float) $parent->total) >= 0.01) {
                    $changed[] = sprintf(
                        '  позиция %d «%s» (смета %d): было %s, стало %s',
                        $parent->id, $parent->name, $parent->estimate_id, $parent->total, $total,
                    );
                }
            }
        }

        // Итог сметы — сумма корневых позиций (см. Estimate::recalc).
        foreach (array_keys($estimateIds) as $estimateId) {
            DB::table('estimates')->where('id', $estimateId)->update([
                'total' => DB::table('estimate_items')
                    ->where('estimate_id', $estimateId)
                    ->whereNull('parent_id')
                    ->sum('total'),
            ]);
        }

        if ($changed) {
            echo 'Суммы изменились, проверьте эти строки:' . PHP_EOL . implode(PHP_EOL, $changed) . PHP_EOL;
        }
    }

    public function down(): void
    {
        // Количества подпунктов сложены в основной БП, разложить обратно нечем.
    }
};
