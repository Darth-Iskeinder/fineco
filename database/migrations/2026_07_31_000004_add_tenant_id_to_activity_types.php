<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Виды деятельности — первый справочник, который получает пометку «чей».
 *
 * Правило прохода по настройкам: справочник оставили редактируемым — значит у
 * каждой фирмы свой набор, значит нужна пометка. Справочник закрыли на просмотр
 * (как режимы налогообложения) — таблица остаётся общей, пометка не нужна.
 *
 * Виды деятельности решено оставить редактируемыми: на расчёты они не влияют
 * (участвуют только в карточке клиента), а набор у каждой фирмы свой — кто-то
 * ведёт аптеки и автосервисы, кто-то НКО и стройку.
 *
 * Поведение не меняется: колонка добавляется и заполняется действующей фирмой.
 * Разделение включается позже, вместе с остальными таблицами.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');

        if (!$tenantId) {
            throw new RuntimeException(
                'В таблице tenants нет ни одного аккаунта — привязывать данные не к чему'
            );
        }

        Schema::table('activity_types', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $t->index('tenant_id');
        });

        DB::table('activity_types')->update(['tenant_id' => $tenantId]);

        // default — та же временная подпорка, что и на рабочих таблицах: код ещё
        // нигде не проставляет tenant_id, без неё создание вида деятельности
        // упало бы с ошибкой. Снять вместе со всеми остальными на этапе 2.
        Schema::table('activity_types', function (Blueprint $t) use ($tenantId) {
            $t->unsignedBigInteger('tenant_id')->nullable(false)->default($tenantId)->change();
            $t->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_types', function (Blueprint $t) {
            $t->dropForeign('activity_types_tenant_id_foreign');
            $t->dropIndex('activity_types_tenant_id_index');
            $t->dropColumn('tenant_id');
        });
    }
};
