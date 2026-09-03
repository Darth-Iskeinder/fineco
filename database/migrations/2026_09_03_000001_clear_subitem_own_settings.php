<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Подпункт — строка внутри основного БП, а не отдельный процесс.
 *
 * Своей карточки в настройках у него больше нет, значит нет и собственных
 * настроек: расписания (задачи всё равно строятся только по корневым позициям),
 * требования документа и проверки. Раньше их можно было задать в карточке
 * подпункта, и в базе остались значения, которые теперь никто не покажет и не
 * поправит: у подпунктов висела периодичность, которая подписывалась в смете
 * под названием, хотя никакого расписания за ней не стояло.
 *
 * Чистим разово. Обратного хода нет: восстанавливать нечего, эти поля у
 * подпунктов больше не участвуют в работе.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('services')->whereNotNull('parent_id')->update([
            'periodicity'       => null,
            'due_day'           => null,
            'start_month'       => null,
            'start_day'         => null,
            'deadline_days'     => null,
            'requires_document' => false,
            'requires_review'   => false,
        ]);

        // Копия периодичности лежит и в позициях смет — это та самая подпись под
        // названием подпункта. Без чистки она осталась бы висеть до пересохранения сметы.
        DB::table('estimate_items')->whereNotNull('parent_id')->update([
            'periodicity' => null,
            'due_day'     => null,
        ]);
    }

    public function down(): void
    {
        // Значения стёрты безвозвратно, восстановить нечего.
    }
};
