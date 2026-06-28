<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расширение внеплановых задач: создание из каталога (только имя) или своё с описанием,
 * флаг «на проверку» с полным циклом ревью (как у плановых) и опциональный документ.
 * Статус — string, новые значения review|rework не требуют схемных изменений.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('client_id')
                ->constrained('services')->nullOnDelete(); // origin, если выбрана из каталога
            $table->text('description')->nullable()->after('name');
            $table->boolean('requires_review')->default(false)->after('description');

            // Цикл проверки (зеркало buh_task_logs)
            $table->text('review_comment')->nullable()->after('requires_review');
            $table->timestamp('review_started_at')->nullable()->after('completed_at');
            $table->timestamp('reviewed_at')->nullable()->after('review_started_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')
                ->constrained('employees')->nullOnDelete();

            // Необязательный документ
            $table->string('document_path')->nullable()->after('reviewed_by');
            $table->string('document_name')->nullable()->after('document_path');
        });
    }

    public function down(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'service_id', 'description', 'requires_review', 'review_comment',
                'review_started_at', 'reviewed_at', 'reviewed_by',
                'document_path', 'document_name',
            ]);
        });
    }
};
