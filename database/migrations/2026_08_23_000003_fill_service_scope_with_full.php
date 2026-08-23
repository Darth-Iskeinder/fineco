<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Действующим клиентам проставляем все три отметки, новых заводим так же.
     *
     * Пустой набор код и так считает полным обслуживанием, но состояние должно быть
     * видно глазами: иначе в карточке работающего клиента все теги серые, и через
     * месяц не отличить «ведём целиком» от «забыли проставить».
     *
     * Трогаем только тех, у кого не отмечено ничего. Если кто-то уже успел сузить
     * обслуживание руками, его выбор остаётся как есть.
     */
    private array $columns = [
        'serves_accounting',
        'serves_tax',
        'serves_payroll',
    ];

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach ($this->columns as $col) {
                $table->boolean($col)->default(true)->change();
            }
        });

        DB::table('clients')
            ->where('serves_accounting', false)
            ->where('serves_tax', false)
            ->where('serves_payroll', false)
            ->update([
                'serves_accounting' => true,
                'serves_tax'        => true,
                'serves_payroll'    => true,
            ]);
    }

    /**
     * Возвращаем прежнее значение по умолчанию. Проставленные отметки не снимаем:
     * отличить их от выставленных вручную уже нельзя, а «ведём целиком» и «не
     * отмечено ничего» для логики одно и то же — снимать нечего.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach ($this->columns as $col) {
                $table->boolean($col)->default(false)->change();
            }
        });
    }
};
