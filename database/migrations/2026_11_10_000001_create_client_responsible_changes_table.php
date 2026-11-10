<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История ответственных за клиента (10.11.2026): кто вёл клиента с какого момента.
 *
 * Нужна «Закрытию месяца»: месяц засчитывается тому, кто вёл клиента тогда, а не тому, кто
 * ответственный сегодня. Без неё новая главбух получила в плитку июль, который закрывали
 * до неё (Сыдыкова, Fineco). Пишется моделью клиента при любой смене. Прошлое не
 * восстанавливаем: до первой записи ответственный = previous_employee_id первой записи.
 * Только добавление таблицы, старые данные не трогаются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_responsible_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('previous_employee_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['client_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_responsible_changes');
    }
};
