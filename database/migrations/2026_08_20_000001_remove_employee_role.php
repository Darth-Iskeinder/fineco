<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Убираем роль «Сотрудник».
     *
     * Прав она не давала: их дают модули плюс admin, manager и главбух — на саму
     * роль в коде не смотрел никто. Рядом при этом жили «Бухгалтер» и «Аудитор»,
     * ничем от неё не отличавшиеся, и выбор из трёх одинаковых пунктов только
     * путал того, кто заводит сотрудника.
     *
     * Порядок обязателен: employees.role_id стоит с onDelete('restrict'), и пока
     * на роль ссылается хоть одна строка, база удалить её не даст. Уволенные тоже
     * считаются — Employee под SoftDeletes, строка остаётся и ключ её видит,
     * поэтому переводим запросом к таблице напрямую, без моделей с их фильтрами.
     */
    public function up(): void
    {
        $employeeRole = DB::table('roles')->where('name', 'employee')->value('id');

        if (!$employeeRole) {
            return;
        }

        $accountantRole = DB::table('roles')->where('name', 'accountant')->value('id');

        if (!$accountantRole) {
            throw new RuntimeException('Роль accountant не найдена — переводить сотрудников не на что');
        }

        DB::table('employees')
            ->where('role_id', $employeeRole)
            ->update(['role_id' => $accountantRole, 'updated_at' => now()]);

        DB::table('roles')->where('id', $employeeRole)->delete();
    }

    /**
     * Роль возвращаем, людей — нет: кто из бухгалтеров был «Сотрудником» до
     * перевода, в базе уже не записано.
     */
    public function down(): void
    {
        DB::table('roles')->updateOrInsert(
            ['name' => 'employee'],
            [
                'display_name' => 'Сотрудник',
                'description'  => 'Доступ только к разрешённым модулям',
                'updated_at'   => now(),
                'created_at'   => now(),
            ],
        );
    }
};
