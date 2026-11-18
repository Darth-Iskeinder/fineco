<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Счётчик скорости страниц (18.11.2026).
 *
 * Одна строка на страницу, фирму и день: сколько раз открыли, сколько времени ушло
 * в сумме, сколько уложилось в 0,3 с и в 1 с, самое долгое. Каждое открытие отдельно
 * не храним. Ни адреса (в нём номера клиентов и строка поиска), ни людей здесь нет.
 *
 * tenant_id = 0, когда фирмы нет (вход, панель владельца): null в уникальном ключе
 * PostgreSQL считает разными значениями, и строки бы плодились.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_timings', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('tenant_id')->default(0);
            $table->string('method', 8);
            $table->string('route', 191);
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->unsignedInteger('max_ms')->default(0);
            $table->unsignedInteger('under_300')->default(0);
            $table->unsignedInteger('under_1000')->default(0);

            $table->unique(['day', 'tenant_id', 'method', 'route']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_timings');
    }
};
