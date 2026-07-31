<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Аккаунт-образец: из него новая фирма получает стартовый набор справочников.
 *
 * Без него новый аккаунт стартует с пустыми таблицами и не может завести даже
 * первого клиента — не из чего выбрать вид деятельности, нечем тарифицировать
 * бизнес-процессы.
 *
 * Это обычная строка в списке аккаунтов, но с флагом is_template: войти в неё
 * нельзя, данных клиентов в ней нет, она существует только чтобы из неё
 * копировать. Так набор можно править через обычный интерфейс, не выкатывая
 * новую версию ради одного бизнес-процесса.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->boolean('is_template')->default(false)->after('status');
            $t->index('is_template');
        });

        if (!DB::table('tenants')->where('is_template', true)->exists()) {
            DB::table('tenants')->insert([
                'name'        => 'Образец',
                'slug'        => 'template',
                'status'      => 'template',
                'is_template' => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Аккаунт-образец удаляем только если в нём ничего не завели: FK на
        // tenant_id стоит restrictOnDelete, и молча снести данные не получится.
        DB::table('tenants')->where('is_template', true)->delete();

        Schema::table('tenants', function (Blueprint $t) {
            $t->dropIndex('tenants_is_template_index');
            $t->dropColumn('is_template');
        });
    }
};
