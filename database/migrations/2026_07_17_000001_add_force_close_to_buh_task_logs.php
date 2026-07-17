<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Принудительное закрытие задачи: сотрудник закрывает задачу в обход требования
 * документа и чеклиста подпунктов, обязательно объяснив причину. Флаг остаётся
 * на записи навсегда (плашка «закрыта принудительно» в выполненных и на проверке).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->boolean('force_closed')->default(false)->after('rework_count');
            $table->text('force_close_comment')->nullable()->after('force_closed');
        });
    }

    public function down(): void
    {
        Schema::table('buh_task_logs', fn (Blueprint $table) => $table->dropColumn(['force_closed', 'force_close_comment']));
    }
};
