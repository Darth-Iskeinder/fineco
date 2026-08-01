<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Убираем подпорку: у tenant_id больше нет значения по умолчанию.
 *
 * Она ставилась осознанно и временно. Когда пометка «чей» стала обязательной,
 * код ещё нигде её не проставлял — без значения по умолчанию ломалось создание
 * вообще всего: клиента, сотрудника, задачи. Подпорка писала «первая фирма».
 *
 * Пока фирма одна это безобидно, но с появлением второй становится опасно:
 * забытая привязка молча уехала бы в первый аккаунт вместо того, чтобы честно
 * упасть. Чужой клиент оказался бы у вас — без ошибки, без следа, без способа
 * это заметить.
 *
 * Теперь фирму проставляет трейт BelongsToTenant из контекста, а если контекста
 * нет — падение (в терминале) или ошибка базы (везде). Это и есть цель:
 * строка без хозяина не должна появляться никакими путями.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tablesWithTenantId() as $table) {
            DB::statement($this->alter($table, 'DROP DEFAULT'));
        }
    }

    public function down(): void
    {
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');

        if (!$tenantId) {
            return;
        }

        foreach ($this->tablesWithTenantId() as $table) {
            DB::statement($this->alter($table, "SET DEFAULT {$tenantId}"));
        }
    }

    /**
     * Кавычки вокруг имён у MySQL и PostgreSQL разные, поэтому не пишем их
     * руками — пусть подставит грамматика соединения. Сам ALTER ... DEFAULT
     * в обеих базах пишется одинаково.
     */
    private function alter(string $table, string $action): string
    {
        $grammar = DB::getQueryGrammar();

        return 'ALTER TABLE ' . $grammar->wrapTable($table)
            . ' ALTER COLUMN ' . $grammar->wrap('tenant_id')
            . ' ' . $action;
    }

    /**
     * Список таблиц берём средствами Laravel, а не запросом к information_schema:
     * прод работает на PostgreSQL, разработка на MySQL, и DATABASE() есть только
     * во втором. Сам ALTER ... DROP DEFAULT одинаков в обеих базах.
     *
     * @return string[]
     */
    private function tablesWithTenantId(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'tenant_id'))
            ->sort()
            ->values()
            ->all();
    }
};
