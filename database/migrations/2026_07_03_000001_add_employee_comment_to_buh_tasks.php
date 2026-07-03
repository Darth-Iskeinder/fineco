<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Личная заметка сотрудника, выполняющего задачу («вспомнить нюансы позже»).
    // Отдельно от review_comment (проверяющий) и комментария услуги из сметы.
    public function up(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->text('employee_comment')->nullable()->after('review_comment');
        });

        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->text('employee_comment')->nullable()->after('review_comment');
        });
    }

    public function down(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->dropColumn('employee_comment');
        });

        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->dropColumn('employee_comment');
        });
    }
};
