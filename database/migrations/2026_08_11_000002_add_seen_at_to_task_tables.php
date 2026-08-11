<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Отметки «сотрудник увидел» для всплывающих уведомлений.
 *
 * Два независимых события, поэтому и полей два: задачу поручили (`assign_seen_at`)
 * и работу вернули на доработку (`rework_seen_at`). Возврат может повториться, поэтому
 * `rework_seen_at` гасится при каждом новом отклонении — уведомление всплывает снова.
 *
 * Доработка бывает и у плановых задач из сметы, поэтому поле есть в обеих таблицах;
 * поручают только внеплановые, так что `assign_seen_at` — лишь у них.
 *
 * Существующие записи помечаем просмотренными: иначе после выкладки каждый сотрудник
 * получил бы уведомления обо всех своих старых задачах разом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->timestamp('assign_seen_at')->nullable()->after('created_by');
            $table->timestamp('rework_seen_at')->nullable()->after('review_comment');
        });

        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->timestamp('rework_seen_at')->nullable()->after('review_comment');
        });

        $now = now();
        DB::table('buh_adhoc_tasks')->update(['assign_seen_at' => $now, 'rework_seen_at' => $now]);
        DB::table('buh_task_logs')->update(['rework_seen_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->dropColumn(['assign_seen_at', 'rework_seen_at']);
        });

        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->dropColumn('rework_seen_at');
        });
    }
};
