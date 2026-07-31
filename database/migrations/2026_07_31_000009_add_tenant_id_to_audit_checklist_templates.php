<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Шаблон чек-листа аудита — у каждой фирмы свой.
 *
 * Чек-лист внутри аудита фирма правит сама: добавляет разделы и пункты,
 * переименовывает, удаляет. Значит и стандарт, из которого он подставляется
 * в новый аудит, у каждой должен быть свой — иначе методика одной фирмы
 * уезжает всем остальным.
 *
 * Сами пункты аудита (audit_checklist_items) пометку получили ещё на этапе 1,
 * здесь — только шаблон и его пункты.
 *
 * Поведение не меняется: колонка добавляется и заполняется действующей фирмой.
 */
return new class extends Migration
{
    private const TABLES = [
        'audit_checklist_templates',
        'audit_checklist_template_items',
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
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $t->index('tenant_id');
            });

            DB::table($table)->update(['tenant_id' => $tenantId]);

            // default — временная подпорка, пока код не проставляет tenant_id сам.
            // Снять на этапе 2 вместе со всеми остальными.
            Schema::table($table, function (Blueprint $t) use ($tenantId) {
                $t->unsignedBigInteger('tenant_id')->nullable(false)->default($tenantId)->change();
                $t->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
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
