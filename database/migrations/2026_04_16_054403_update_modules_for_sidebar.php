<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Удаляем старые модули
        DB::table('modules')->whereIn('name', ['tasks', 'documents', 'reports'])->delete();

        // Обновляем clients
        DB::table('modules')->where('name', 'clients')->update([
            'icon' => 'building-office',
            'sort_order' => 2,
        ]);

        // Добавляем новые модули
        DB::table('modules')->insert([
            [
                'name' => 'buhsmeta',
                'display_name' => 'БухСмета',
                'icon' => 'calculator',
                'route' => 'buhsmeta',
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'buhtasks',
                'display_name' => 'БухЗадачник',
                'icon' => 'clipboard-document-list',
                'route' => 'buhtasks',
                'sort_order' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'settings',
                'display_name' => 'Настройки',
                'icon' => 'cog-6-tooth',
                'route' => 'settings',
                'sort_order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('modules')->whereIn('name', ['buhsmeta', 'buhtasks', 'settings'])->delete();

        DB::table('modules')->insert([
            [
                'name' => 'tasks',
                'display_name' => 'Задачи',
                'icon' => 'clipboard-list',
                'route' => 'tasks',
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'documents',
                'display_name' => 'Документы',
                'icon' => 'folder',
                'route' => 'documents',
                'sort_order' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'reports',
                'display_name' => 'Отчёты',
                'icon' => 'chart-bar',
                'route' => 'reports',
                'sort_order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
};
