<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Счётчик возвратов с проверки (для колонки «возвраты» на странице руководителя).
 * Статус rework перезаписывается при повторной сдаче, поэтому историю возвратов
 * иначе не восстановить; возвраты до этой миграции в счётчик не попадают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('rework_count')->default(0)->after('review_comment');
        });
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('rework_count')->default(0)->after('review_comment');
        });
    }

    public function down(): void
    {
        Schema::table('buh_task_logs', fn (Blueprint $table) => $table->dropColumn('rework_count'));
        Schema::table('buh_adhoc_tasks', fn (Blueprint $table) => $table->dropColumn('rework_count'));
    }
};
