<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Задача из каталога забирает у услуги описание и подпункты.
 *
 * Раньше переносилось только название: описание приходилось перепечатывать руками,
 * а чек-листа у внеплановых не было вовсе, хотя у самого БП он есть.
 *
 * Оба поля — СНИМОК на момент создания, а не ссылка на услугу. Иначе правка каталога
 * задним числом меняла бы уже выполненные и принятые задачи.
 *
 * `clarification` — то, что автор дописывает от себя («за второй квартал, срочно»):
 * описание из каталога редактировать нельзя, а деталь сообщить надо.
 *
 * `checklist` — список вида [{"name": "...", "done": false}]. Отдельная таблица тут
 * не нужна: у внеплановых подпункт — просто галочка, без стоимости и количества,
 * которые у плановых живут в смете.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->text('clarification')->nullable()->after('description');
            $table->json('checklist')->nullable()->after('clarification');
        });
    }

    public function down(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->dropColumn(['clarification', 'checklist']);
        });
    }
};
