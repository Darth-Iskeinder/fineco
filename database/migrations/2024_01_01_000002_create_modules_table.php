<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // employees, clients, tasks, etc.
            $table->string('display_name'); // Сотрудники, Клиенты, Задачи
            $table->string('icon')->nullable(); // Иконка для меню
            $table->string('route')->nullable(); // Базовый маршрут модуля
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Создаём базовые модули системы
        DB::table('modules')->insert([
            [
                'name' => 'employees',
                'display_name' => 'Сотрудники',
                'icon' => 'users',
                'route' => 'employees',
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'clients',
                'display_name' => 'Клиенты',
                'icon' => 'building',
                'route' => 'clients',
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
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

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
