<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Одна смета на клиента: смета — это стабильное определение услуг клиента,
     * не привязанное к месяцу. Помесячное исполнение остаётся в buh_task_logs.
     * Существующие сметы очищаются (по согласованию).
     */
    public function up(): void
    {
        // Очистка: каскадом удалит estimate_items и buh_task_logs (FK cascadeOnDelete)
        DB::table('estimates')->delete();

        // Сначала вернём индекс по client_id (нужен FK), затем уберём составной
        Schema::table('estimates', function (Blueprint $table) {
            $table->unique('client_id');
        });
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'year', 'month']);
        });
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->default(0)->after('client_id');
            $table->unsignedTinyInteger('month')->default(0)->after('year');
        });
        Schema::table('estimates', function (Blueprint $table) {
            $table->unique(['client_id', 'year', 'month']);
        });
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
        });
    }
};
