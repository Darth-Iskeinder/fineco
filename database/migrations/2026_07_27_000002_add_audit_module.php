<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Модуль «Аудит» в общей системе модулей (слот 5 освободился после удаления «Проверки»).
 * Доступ выдаётся сотруднику галочкой модуля; админ и руководитель видят его всегда.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('modules')->where('name', 'audit')->exists()) {
            return;
        }

        DB::table('modules')->insert([
            'name' => 'audit',
            'display_name' => 'Аудит',
            'icon' => 'shield-check',
            'route' => 'audit',
            'sort_order' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('modules')->where('name', 'audit')->delete();
    }
};
