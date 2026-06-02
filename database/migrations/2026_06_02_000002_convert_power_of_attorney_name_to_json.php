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
     *
     * Конвертация делается кросс-СУБД: данные оборачиваем в JSON на стороне PHP
     * (без СУБД-специфичных функций вроде JSON_ARRAY, которой нет в PostgreSQL < 16),
     * а смену типа колонки выполняем с учётом драйвера (PostgreSQL требует USING).
     */
    public function up(): void
    {
        // 1. Оборачиваем существующие строковые значения в JSON-массив, пока колонка ещё строковая.
        DB::table('clients')
            ->whereNotNull('power_of_attorney_name')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $value = $row->power_of_attorney_name;

                    // Пустую строку превращаем в NULL.
                    if (trim((string) $value) === '') {
                        DB::table('clients')->where('id', $row->id)
                            ->update(['power_of_attorney_name' => null]);
                        continue;
                    }

                    // Пропускаем значения, которые уже являются JSON-массивом.
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        continue;
                    }

                    DB::table('clients')->where('id', $row->id)->update([
                        'power_of_attorney_name' => json_encode([$value], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });

        // 2. Меняем тип колонки на json с учётом драйвера.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clients ALTER COLUMN power_of_attorney_name TYPE json USING power_of_attorney_name::json');
        } else {
            Schema::table('clients', function (Blueprint $table) {
                $table->json('power_of_attorney_name')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // Берём первый элемент массива и переводим колонку обратно в строку.
            DB::statement("ALTER TABLE clients ALTER COLUMN power_of_attorney_name TYPE varchar(255) USING (power_of_attorney_name->>0)");
        } else {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('power_of_attorney_name', 255)->nullable()->change();
            });

            DB::statement("UPDATE clients SET power_of_attorney_name = JSON_UNQUOTE(JSON_EXTRACT(power_of_attorney_name, '$[0]')) WHERE power_of_attorney_name IS NOT NULL AND JSON_VALID(power_of_attorney_name)");
        }
    }
};
