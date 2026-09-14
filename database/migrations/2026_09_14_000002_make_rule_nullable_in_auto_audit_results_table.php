<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Строка «приложен не тот документ» не относится ни к одной проверке.
 *
 * Бухгалтер закрыл задачу с файлом, который не читается как нужная форма (например,
 * форма 161 вместо отчёта по единому налогу). Это ошибка самой задачи, а не сверки,
 * поэтому номера проверки у такой строки нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_audit_results', function (Blueprint $table) {
            $table->string('rule', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Результаты пересчитываются кнопкой, терять их не страшно.
        DB::table('auto_audit_results')->whereNull('rule')->delete();

        Schema::table('auto_audit_results', function (Blueprint $table) {
            $table->string('rule', 64)->nullable(false)->change();
        });
    }
};
