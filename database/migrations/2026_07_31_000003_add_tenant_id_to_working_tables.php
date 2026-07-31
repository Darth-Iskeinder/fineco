<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пометка «чей это ряд» на рабочих данных — тех, что заводит сама фирма.
 *
 * Поведение системы не меняется: колонка добавляется, заполняется действующей
 * фирмой и становится обязательной. Разделение по аккаунтам включается позже,
 * отдельным этапом (трейт BelongsToTenant + глобальный скоуп) — так откат
 * остаётся дешёвым, а прод не замечает выкатки.
 *
 * Справочников здесь намеренно нет: у каждого своя судьба (коды налоговых
 * органов одинаковы у всех фирм, тарифы у каждой свои), разбираем отдельно.
 */
return new class extends Migration
{
    /** Рабочие данные фирмы: клиенты, сотрудники, сметы, задачи, аудит. */
    private const TABLES = [
        // Клиенты
        'clients',
        'client_documents',
        'client_employee',
        'client_service_schedules',
        // Сотрудники
        'employees',
        'employee_module',
        // Сметы
        'estimates',
        'estimate_items',
        // Задачи
        'buh_task_logs',
        'buh_adhoc_tasks',
        'buh_task_documents',
        'task_reminders',
        // Аудит
        'audits',
        'audit_task_reviews',
        'audit_checklist_items',
    ];

    public function up(): void
    {
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');

        if (!$tenantId) {
            throw new RuntimeException(
                'В таблице tenants нет ни одного аккаунта — привязывать данные не к чему'
            );
        }

        foreach (self::TABLES as $table) {
            // Колонка появляется пустой: у существующих строк её значения ещё нет.
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $t->index('tenant_id');
            });

            // Всё, что уже есть в базе, принадлежит действующей фирме.
            DB::table($table)->update(['tenant_id' => $tenantId]);

            // Теперь строка без хозяина физически не может появиться.
            //
            // default нужен именно сейчас: код ещё нигде не проставляет tenant_id,
            // и без него любая запись нового клиента или задачи упала бы с ошибкой.
            // Следующим этапом значение начнёт проставлять трейт BelongsToTenant,
            // и default надо будет снять: пока он есть, забытая привязка молча
            // уедет в первый аккаунт вместо того, чтобы честно упасть.
            Schema::table($table, function (Blueprint $t) use ($tenantId) {
                $t->unsignedBigInteger('tenant_id')->nullable(false)->default($tenantId)->change();
                $t->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign($table . '_tenant_id_foreign');
                $t->dropIndex($table . '_tenant_id_index');
                $t->dropColumn('tenant_id');
            });
        }
    }
};
