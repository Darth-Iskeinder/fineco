<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * СЭЗ (свободная экономическая зона) как режим налогообложения.
 *
 * Справочник режимов общий для всех бухфирм и правится только миграцией, как
 * это было с «Патентом».
 *
 * Режим добавляется пустым: ни один БП им не помечен. Клиенту на СЭЗ смета
 * соберётся из обязательных БП и признаков бизнеса, а режимные БП появятся,
 * когда фирма отметит СЭЗ у себя в каталоге. Чужие каталоги миграция не трогает:
 * что вести резиденту СЭЗ, каждая фирма решает сама.
 *
 * Двойника ищем и по коду, и по названию: на бою справочник правили руками.
 * LOWER вместо ilike специально: на бою PostgreSQL, локально MySQL, а ilike
 * понимает только первый.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('tax_systems')
            ->where('code', 'sez')
            ->orWhereRaw('LOWER(name) = ?', ['сэз'])
            ->orWhereRaw('LOWER(name) LIKE ?', ['%свободная экономическая зона%'])
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('tax_systems')->insert([
            'name'        => 'СЭЗ',
            'code'        => 'sez',
            'description' => 'Свободная экономическая зона',
            'is_active'   => true,
            'sort_order'  => (int) DB::table('tax_systems')->max('sort_order') + 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        // Только своё: запись, заведённую руками под другим кодом, не трогаем.
        $id = DB::table('tax_systems')->where('code', 'sez')->value('id');

        if (!$id) {
            return;
        }

        // Клиентов с этим режимом без режима не оставляем: откат при живых
        // клиентах на СЭЗ должен решать человек, а не миграция.
        if (DB::table('clients')->where('tax_system_id', $id)->exists()) {
            throw new RuntimeException('На режиме СЭЗ есть клиенты. Сначала переведите их на другой режим.');
        }

        DB::table('clients')->where('previous_tax_system_id', $id)->update(['previous_tax_system_id' => null]);
        DB::table('service_tax_system')->where('tax_system_id', $id)->delete();
        DB::table('tax_systems')->where('id', $id)->delete();
    }
};
