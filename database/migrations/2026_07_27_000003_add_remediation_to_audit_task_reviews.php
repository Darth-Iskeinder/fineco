<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Устранение замечаний: замечание из аудита передаётся бухгалтеру и живёт дальше,
 * уже после завершения самого аудита (отчёт заморожен, замечание — нет).
 *
 * Исполнение идёт через обычную внеплановую задачу (buh_adhoc_tasks): бухгалтер
 * видит её там же, где всю свою работу, а главбух — в списке задач своих бухгалтеров.
 * Закрывает замечание не исполнитель, а аудитор: выполненная задача переводит
 * замечание в «на проверке», дальше аудитор подтверждает или возвращает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_task_reviews', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('comment')
                ->constrained('employees')->nullOnDelete();
            $table->date('due_date')->nullable()->after('assignee_id');
            $table->timestamp('sent_at')->nullable()->after('due_date');       // передано на исправление
            $table->foreignId('adhoc_task_id')->nullable()->after('sent_at')
                ->constrained('buh_adhoc_tasks')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('adhoc_task_id'); // аудитор подтвердил
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')
                ->constrained('employees')->nullOnDelete();
            $table->unsignedInteger('returns_count')->default(0)->after('resolved_by');
            $table->index(['assignee_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_task_reviews', function (Blueprint $table) {
            $table->dropIndex(['assignee_id', 'resolved_at']);
            $table->dropConstrainedForeignId('assignee_id');
            $table->dropConstrainedForeignId('adhoc_task_id');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['due_date', 'sent_at', 'resolved_at', 'returns_count']);
        });
    }
};
