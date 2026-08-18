<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Флаг «можно указывать количество» переезжает на основной БП — как и биллинг.
 * Раньше его ставили на каждом подпункте, теперь он задаётся один раз на родителе.
 *
 * Если количество разрешал хотя бы один подпункт, включаем флаг родителю (иначе в смете
 * пропала бы возможность ввести кол-во), затем выравниваем все подпункты по родителю.
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentIds = DB::table('services')
            ->whereNotNull('parent_id')
            ->distinct()
            ->pluck('parent_id');

        foreach ($parentIds as $parentId) {
            $parent = DB::table('services')->where('id', $parentId)->first();
            if (!$parent) {
                continue;
            }

            $allows = (bool) $parent->allows_quantity
                || DB::table('services')->where('parent_id', $parentId)->where('allows_quantity', 1)->exists();

            DB::table('services')->where('id', $parentId)->update(['allows_quantity' => $allows]);
            DB::table('services')->where('parent_id', $parentId)->update(['allows_quantity' => $allows]);
        }
    }

    public function down(): void
    {
        // Обратной операции нет: исходное распределение флага по подпунктам не сохраняется.
    }
};
