<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Доверенность теперь может быть оформлена на нескольких сотрудников,
     * поэтому храним список имён как JSON-массив.
     */
    public function up(): void
    {
        // Оборачиваем существующие строковые значения в JSON-массив,
        // пока колонка ещё строковая (JSON_ARRAY вернёт валидный JSON-текст).
        DB::statement("UPDATE clients SET power_of_attorney_name = JSON_ARRAY(power_of_attorney_name) WHERE power_of_attorney_name IS NOT NULL AND power_of_attorney_name <> ''");
        DB::statement("UPDATE clients SET power_of_attorney_name = NULL WHERE power_of_attorney_name = ''");

        Schema::table('clients', function (Blueprint $table) {
            $table->json('power_of_attorney_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('power_of_attorney_name', 255)->nullable()->change();
        });

        // Возвращаем первое имя из массива в виде обычной строки.
        DB::statement("UPDATE clients SET power_of_attorney_name = JSON_UNQUOTE(JSON_EXTRACT(power_of_attorney_name, '$[0]')) WHERE power_of_attorney_name IS NOT NULL AND JSON_VALID(power_of_attorney_name)");
    }
};
