<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // admin, employee
            $table->string('display_name'); // Администратор, Сотрудник
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Создаём базовые роли
        DB::table('roles')->insert([
            [
                'name' => 'admin',
                'display_name' => 'Администратор',
                'description' => 'Полный доступ ко всем модулям системы',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'employee',
                'display_name' => 'Сотрудник',
                'description' => 'Доступ к разрешённым модулям',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
