<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Результаты автоаудита копятся историей, а не стираются каждым прогоном.
 *
 * Пусто, значит строка действующая: её показывает страница. Когда итог строки меняется
 * (исправили файл, вернули задачу), прогон ставит здесь время и пишет новую строку рядом.
 * Старая остаётся историей.
 *
 * Все строки, что есть на момент выкатки, сразу действующие: заполнять ничего не нужно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_audit_results', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('sources');
        });
    }

    public function down(): void
    {
        // Без колонки история смешалась бы с действующими строками на странице.
        DB::table('auto_audit_results')->whereNotNull('superseded_at')->delete();

        Schema::table('auto_audit_results', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
