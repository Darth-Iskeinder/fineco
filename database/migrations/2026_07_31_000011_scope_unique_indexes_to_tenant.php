<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Уникальность внутри аккаунта, а не на всю базу.
 *
 * Обнаружено при первой же попытке скопировать справочники в аккаунт-образец:
 * копия падала на «Duplicate entry 'trade'», потому что код вида деятельности
 * уникален глобально. То же ждало бы тарифы.
 *
 * Но главное здесь — ИНН клиента. Он тоже был уникален на всю базу, а это
 * значит, что две бухфирмы не смогли бы вести одного и того же клиента: вторая
 * получила бы «клиент с таким ИНН уже существует» и не поняла бы почему —
 * чужого клиента она не видит. Для сервиса, где фирмы работают в одном городе,
 * это встретилось бы в первую неделю.
 *
 * Что НЕ трогаем: почта сотрудника и токен приглашения остаются уникальными
 * глобально. Почта — это логин, один человек работает в одной фирме.
 */
return new class extends Migration
{
    /** таблица => [колонка, старое имя индекса] */
    private const INDEXES = [
        'clients'        => ['inn',  'clients_inn_unique'],
        'activity_types' => ['code', 'activity_types_code_unique'],
        'tariffs'        => ['code', 'tariffs_code_unique'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$column, $oldIndex]) {
            Schema::table($table, function (Blueprint $t) use ($table, $column, $oldIndex) {
                $t->dropUnique($oldIndex);
                $t->unique(['tenant_id', $column], $table . '_tenant_' . $column . '_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$column, $oldIndex]) {
            Schema::table($table, function (Blueprint $t) use ($table, $column, $oldIndex) {
                $t->dropUnique($table . '_tenant_' . $column . '_unique');
                $t->unique($column, $oldIndex);
            });
        }
    }
};
