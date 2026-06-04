<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ответственное лицо клиента (одно). На него будут ассайниться задачи компании.
     * Отдельно от M2M client_employee («Закреплённые сотрудники» / доступ к задачам).
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('responsible_employee_id')
                ->nullable()
                ->after('tariff_id')
                ->constrained('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['responsible_employee_id']);
            $table->dropColumn('responsible_employee_id');
        });
    }
};
