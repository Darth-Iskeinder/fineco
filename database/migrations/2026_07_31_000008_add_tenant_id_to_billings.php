<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Биллинг — режимы тарификации БП, у каждой фирмы свои.
 *
 * Правило то же: справочник остаётся редактируемым, значит у каждой фирмы свой
 * набор. Названия режимов фирма может переименовать под свой язык — расчёт от
 * этого не поедет, потому что цена считается по коду (billings.code), а не по
 * названию: included/none → 0, by_quantity/addon → цена из ставки.
 *
 * Поведение не меняется: колонка добавляется и заполняется действующей фирмой.
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

        Schema::table('billings', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $t->index('tenant_id');
        });

        DB::table('billings')->update(['tenant_id' => $tenantId]);

        // default — временная подпорка, пока код не проставляет tenant_id сам.
        // Снять на этапе 2 вместе со всеми остальными.
        Schema::table('billings', function (Blueprint $t) use ($tenantId) {
            $t->unsignedBigInteger('tenant_id')->nullable(false)->default($tenantId)->change();
            $t->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $t) {
            $t->dropForeign('billings_tenant_id_foreign');
            $t->dropIndex('billings_tenant_id_index');
            $t->dropColumn('tenant_id');
        });
    }
};
