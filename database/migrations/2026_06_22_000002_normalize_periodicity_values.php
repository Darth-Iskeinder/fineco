<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Кривые/вариативные написания → каноническое имя из справочника periodicities. */
    private array $map = [
        'эжемесячно'      => 'Ежемесячно',
        'Ежемесячный'     => 'Ежемесячно',
        'ежемесячный'     => 'Ежемесячно',
        'Ежеквартальный'  => 'Ежеквартально',
        'ежеквартальный'  => 'Ежеквартально',
        'Ежегодный'       => 'Ежегодно',
        'Еженедельный'    => 'Еженедельно',
    ];

    public function up(): void
    {
        // Поле periodicity (имя из справочника) встречается в трёх таблицах
        foreach (['estimate_items', 'services', 'client_service_schedules'] as $table) {
            foreach ($this->map as $bad => $good) {
                DB::table($table)->where('periodicity', $bad)->update(['periodicity' => $good]);
            }
        }
    }

    public function down(): void
    {
        // Нормализация необратима без потерь — откат не предусмотрен.
    }
};
