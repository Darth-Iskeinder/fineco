<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Биллинг переезжает на основной БП: раньше у БП с подпунктами режим тарификации
 * и ставку несли подпункты, теперь их задают только на родителе, а подпункты наследуют.
 *
 * Поднимаем биллинг первого подпункта, у которого он задан, на родителя (если у того
 * своего нет), затем выравниваем все подпункты по родителю.
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

            if ($parent->billing === null) {
                $source = DB::table('services')
                    ->where('parent_id', $parentId)
                    ->whereNotNull('billing')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

                if ($source) {
                    DB::table('services')->where('id', $parentId)->update([
                        'billing' => $source->billing,
                        'rate_id' => $source->rate_id,
                    ]);
                    $parent->billing = $source->billing;
                    $parent->rate_id = $source->rate_id;
                }
            }

            DB::table('services')->where('parent_id', $parentId)->update([
                'billing' => $parent->billing,
                'rate_id' => $parent->rate_id,
            ]);
        }
    }

    public function down(): void
    {
        // Обратной операции нет: исходное распределение биллинга по подпунктам не сохраняется.
    }
};
