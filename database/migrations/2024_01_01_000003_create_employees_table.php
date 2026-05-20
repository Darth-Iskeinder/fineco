<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('full_name'); // ФИО
            $table->string('position'); // Должность
            $table->string('email')->unique(); // Email (логин)
            $table->string('phone')->nullable(); // Телефон
            $table->string('password')->nullable(); // Пароль (null до подтверждения инвайта)

            $table->foreignId('role_id')->constrained('roles')->onDelete('restrict');

            // Статусы: pending (ожидает подтверждения), active (активен), inactive (неактивен)
            $table->enum('status', ['pending', 'active', 'inactive'])->default('pending');

            // Токен для инвайта
            $table->string('invite_token')->nullable()->unique();
            $table->timestamp('invite_sent_at')->nullable();
            $table->timestamp('invite_accepted_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes(); // Soft delete для сохранения истории

            // Индексы для поиска
            $table->index('status');
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
