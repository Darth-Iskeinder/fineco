<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Верхняя граница задач по позиции сметы: «задачи по такое-то число».
 *
 * Парная к `tasks_start_from`. Нужна там, где БП у клиента закончился, но история
 * по нему должна остаться: смена режима налогообложения, отказ от услуги. До сих
 * пор единственным способом прекратить БП было убрать позицию из сметы, а вместе
 * с ней каскадом уходили все отметки о выполнении (`buh_task_logs`).
 *
 * Пустая у всех действующих позиций, поведение без неё прежнее.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->date('tasks_end_at')->nullable()->after('tasks_start_from');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn('tasks_end_at');
        });
    }
};
