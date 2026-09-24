<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Когда сотрудник последний раз нажал «Понятно» на уведомлении о вопросах автоаудита.
 *
 * Одна отметка на человека, а не на вопрос: новые вопросы в уведомлении идут одной строкой-
 * сводкой, и гасятся вместе. Снова всплывает то, что случилось позже этой отметки: новый
 * вопрос, «Не принято», новый итог после замены файла или объяснения.
 *
 * Пусто: ещё не нажимал, увидит все свои вопросы. Колонку только добавляем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('audit_alert_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('audit_alert_seen_at');
        });
    }
};
