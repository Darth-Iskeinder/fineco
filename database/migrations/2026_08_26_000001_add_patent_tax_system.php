<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Патент как режим налогообложения.
 *
 * Справочник режимов общий для всех бухфирм и правится только миграцией: список
 * задаёт государство, а не бухгалтер (роутов на запись у страницы нет намеренно).
 *
 * Вставляем осторожно. На бою справочник уже правили руками, и запись «Патент»
 * там могла появиться со своим кодом — ищем и по коду, и по названию, иначе
 * получим двойника, как это уже вышло с периодичностью «По запросу».
 *
 * LOWER вместо ilike специально: на бою PostgreSQL, локально MySQL, а ilike
 * понимает только первый.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('tax_systems')
            ->where('code', 'patent')
            ->orWhereRaw('LOWER(name) = ?', ['патент'])
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('tax_systems')->insert([
            'name'        => 'Патент',
            'code'        => 'patent',
            'description' => 'Налог на основе патента',
            'is_active'   => true,
            // В конец списка: место освободившегося режима занимать не будем,
            // на разных базах там разное.
            'sort_order'  => (int) DB::table('tax_systems')->max('sort_order') + 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        // Только своё: запись, заведённую руками под другим кодом, не трогаем.
        // У БП с привязкой к режиму удаление стёрло бы связи, поэтому сначала они.
        $id = DB::table('tax_systems')->where('code', 'patent')->value('id');

        if (!$id) {
            return;
        }

        DB::table('service_tax_system')->where('tax_system_id', $id)->delete();
        DB::table('tax_systems')->where('id', $id)->delete();
    }
};
