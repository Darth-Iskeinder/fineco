<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->insert([
            'name' => 'auditor',
            'display_name' => 'Проверяющий',
            'description' => 'Закрывает задачи, требующие проверки (БП с флагом «Проверка»)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'auditor')->delete();
    }
};
