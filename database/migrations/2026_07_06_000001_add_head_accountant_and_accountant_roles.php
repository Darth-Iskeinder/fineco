<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Добавляем роли «Главбух» и «Бухгалтер».
     * Аддитивно: никому не назначаются, у всех остаётся текущая роль «Сотрудник».
     * Идемпотентно (updateOrInsert) — безопасно при повторном прогоне.
     */
    public function up(): void
    {
        $roles = [
            [
                'name' => 'head_accountant',
                'display_name' => 'Главбух',
                'description' => 'Ведёт клиента, распределяет задачи между бухгалтерами и проверяет их работу',
            ],
            [
                'name' => 'accountant',
                'display_name' => 'Бухгалтер',
                'description' => 'Выполняет закреплённые за ним задачи по клиентам',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                [
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('name', ['head_accountant', 'accountant'])
            ->delete();
    }
};
