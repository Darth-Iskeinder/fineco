<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Периодичность «По запросу»: у неё нет дат срока, задачи по такому БП
     * не планируются вовсе. Его добавляют руками из каталога в БухЗадачнике,
     * а срок считается от дня добавления плюс services.deadline_days.
     *
     * Справочник общий для всех фирм, поэтому запись заводится миграцией.
     * Поведение завязано на kind (on_request), имя можно переименовывать.
     */
    public function up(): void
    {
        if (DB::table('periodicities')->where('kind', 'on_request')->exists()) {
            return;
        }

        DB::table('periodicities')->insert([
            'name'       => 'По запросу',
            'kind'       => 'on_request',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('periodicities')->where('kind', 'on_request')->delete();
    }
};
