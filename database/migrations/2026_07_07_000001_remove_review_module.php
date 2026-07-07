<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Шаг 7.4: убираем старый отдельный экран «Проверка».
 * Проверку теперь делает главбух прямо в списке задач (BuhTasksController), отдельный
 * модуль/экран больше не нужен. Удаляем строку модуля `review` и его назначения сотрудникам.
 * Статус задачи `review` и поля reviewed_by/review_comment/review_started_at — НЕ трогаем,
 * их использует новый поток проверки.
 */
return new class extends Migration
{
    public function up(): void
    {
        $module = DB::table('modules')->where('name', 'review')->first();
        if ($module) {
            DB::table('employee_module')->where('module_id', $module->id)->delete();
            DB::table('modules')->where('id', $module->id)->delete();
        }
    }

    public function down(): void
    {
        if (! DB::table('modules')->where('name', 'review')->exists()) {
            DB::table('modules')->insert([
                'name' => 'review',
                'display_name' => 'Проверка',
                'icon' => 'clipboard-document-check',
                'route' => 'review',
                'sort_order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
