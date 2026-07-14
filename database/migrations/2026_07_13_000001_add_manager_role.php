<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Роль «Руководитель»: доступ к управленческому дашборду (/dashboard).
     * Заводится только миграцией (прямо в базе): в списках ролей админки не
     * показывается и назначить её через интерфейс нельзя — назначение выполняется
     * вручную UPDATE-ом employees.role_id.
     * Идемпотентно (updateOrInsert) — безопасно при повторном прогоне.
     */
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['name' => 'manager'],
            [
                'display_name' => 'Руководитель',
                'description' => 'Видит общую картину по задачам: сроки, просрочки и затраченное время по сотрудникам и компаниям',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'manager')->delete();
    }
};
