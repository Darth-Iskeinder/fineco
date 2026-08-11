<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автор внеплановой задачи. Раньше он нигде не сохранялся: поручив задачу другому,
 * сотрудник терял её из виду навсегда (всплывала только у главбуха клиента, да и то
 * если клиент указан). Поле даёт вкладку «Я поручил» и адресата проверки —
 * принимает работу тот, кто её поручил.
 *
 * Старые задачи остаются с NULL: автор неизвестен, поведение для них прежнее.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('employee_id')
                ->constrained('employees')->nullOnDelete();

            $table->index(['created_by', 'status']); // выборка вкладки «Я поручил»
        });
    }

    public function down(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'status']);
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
