<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            // Момент входа задачи в проверку — от него считается срок проверки (SLA проверяющего)
            $table->timestamp('review_started_at')->nullable()->after('reviewed_by');
        });

        // Бэкфилл уже висящих на проверке: считаем, что отсчёт пошёл с последнего изменения
        DB::table('buh_task_logs')
            ->where('status', 'review')
            ->whereNull('review_started_at')
            ->update(['review_started_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->dropColumn('review_started_at');
        });
    }
};
