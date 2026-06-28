<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дневная идентичность логов задач. До сих пор лог был помесячным (year+month+item),
 * из-за чего еженедельные БП (напр. вторник+пятница, 8 раз/мес) схлопывались в одну задачу.
 * Для weekly храним конкретную дату вхождения; для остальных периодичностей due_date = NULL
 * (помесячный слот, как раньше) — обратная совместимость без бэкфилла.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('month');
        });

        // Уникальность задачи теперь включает дату: для weekly это слот-вхождение,
        // для остальных due_date=NULL (MySQL допускает несколько NULL — помесячную
        // идемпотентность держит firstOrCreate через SELECT, как и раньше).
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->dropUnique('buh_task_logs_unique');
            $table->unique(
                ['employee_id', 'client_id', 'estimate_item_id', 'year', 'month', 'due_date'],
                'buh_task_logs_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->dropUnique('buh_task_logs_unique');
            $table->unique(
                ['employee_id', 'client_id', 'estimate_item_id', 'year', 'month'],
                'buh_task_logs_unique'
            );
            $table->dropColumn('due_date');
        });
    }
};
