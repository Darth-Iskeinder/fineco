<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Роль «Юрисконсульт». На бою её завели руками 21.10.2026 (id 7), поэтому только
 * если её ещё нет: там миграция ничего не сделает, а локально и в тестах роль появится.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->where('name', 'legal_counsel')->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'name' => 'legal_counsel',
            'display_name' => 'Юрисконсульт',
            'description' => 'Ведёт юридические БП клиента, например договоры',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Роль не удаляем: на бою она старше миграции, и на ней уже стоят сотрудники.
    public function down(): void
    {
    }
};
