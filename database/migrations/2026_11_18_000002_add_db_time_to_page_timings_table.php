<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Счётчик скорости страниц: сколько из времени ушло на базу (18.11.2026).
 *
 * Суммы за день, как и total_ms: число запросов к базе и их время. Делим на hits,
 * получаем среднее на одно открытие. Обе колонки с нулём по умолчанию, старые
 * строки просто считаются «не мерили».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_timings', function (Blueprint $table) {
            $table->unsignedBigInteger('total_queries')->default(0);
            $table->unsignedBigInteger('total_db_ms')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('page_timings', function (Blueprint $table) {
            $table->dropColumn(['total_queries', 'total_db_ms']);
        });
    }
};
