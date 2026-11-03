<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Модуль «Расчёты с клиентами» в общей системе модулей (03.11.2026).
 *
 * Модули общие для всех фирм, а раздел открывается фирме флагом (settlements:access).
 * Пока флага нет, модуль спрятан и в меню, и в карточке сотрудника, см. SettlementsAccess.
 * «Настройки» сдвигаем на одно место вниз, чтобы стояли последними.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('modules')->where('name', 'settlements')->exists()) {
            return;
        }

        DB::table('modules')->where('name', 'settings')->where('sort_order', 6)->update(['sort_order' => 7]);

        DB::table('modules')->insert([
            'name' => 'settlements',
            'display_name' => 'Расчёты с клиентами',
            'icon' => 'banknotes',
            'route' => 'settlements',
            'sort_order' => 6,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('modules')->where('name', 'settlements')->value('id');

        if ($id) {
            DB::table('employee_module')->where('module_id', $id)->delete();
            DB::table('modules')->where('id', $id)->delete();
        }

        DB::table('modules')->where('name', 'settings')->where('sort_order', 7)->update(['sort_order' => 6]);
    }
};
