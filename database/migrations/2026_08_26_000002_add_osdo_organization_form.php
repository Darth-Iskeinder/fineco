<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ОсДО в формы организации.
 *
 * Справочник общий для всех бухфирм, экрана настроек у него нет: записи в него
 * заводили руками прямо в базе. Значит на бою «ОсДО» уже может лежать, и
 * вставлять вслепую нельзя — сравниваем по названию без учёта регистра.
 *
 * LOWER вместо ilike специально: на бою PostgreSQL, локально MySQL, а ilike
 * понимает только первый.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('organization_forms')
            ->whereRaw('LOWER(name) = ?', ['осдо'])
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('organization_forms')->insert([
            'name'       => 'ОсДО',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Форму, уже проставленную клиентам, не удаляем: связь у клиента
        // обнулилась бы молча.
        $id = DB::table('organization_forms')->where('name', 'ОсДО')->value('id');

        if (!$id || DB::table('clients')->where('organization_form_id', $id)->exists()) {
            return;
        }

        DB::table('organization_forms')->where('id', $id)->delete();
    }
};
