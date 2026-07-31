<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Тарифы, справочник ставок и бизнес-процессы — у каждой фирмы свои.
 *
 * По тому же правилу, что и виды деятельности: справочник остаётся
 * редактируемым, значит у каждой фирмы свой набор, значит нужна пометка «чей».
 * Цены и методика работы — самое личное, что есть у бухфирмы; общими они быть
 * не могут в принципе.
 *
 * Поведение не меняется: колонка добавляется и заполняется действующей фирмой.
 * Разделение включается позже, вместе с остальными таблицами.
 */
return new class extends Migration
{
    private const TABLES = [
        'tariffs',  // тарифные планы обслуживания
        'rates',    // справочник ставок (цена за единицу)
        'services', // бизнес-процессы
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

            // default — временная подпорка, пока код нигде не проставляет
            // tenant_id сам. Снять на этапе 2 вместе со всеми остальными.
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
