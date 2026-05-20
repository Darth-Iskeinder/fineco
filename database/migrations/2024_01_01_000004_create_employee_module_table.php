<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_module', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('module_id')->constrained('modules')->onDelete('cascade');
            $table->timestamps();

            // Уникальная комбинация сотрудник-модуль
            $table->unique(['employee_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_module');
    }
};
