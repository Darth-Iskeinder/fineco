<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('task_reminders', function (Blueprint $table) {
            // Филиальное напоминание: один service_id, но разные НО — должны быть отдельными.
            $table->string('tax_office_code', 10)->nullable()->after('service_id');
            $table->string('branch_label')->nullable()->after('tax_office_code');

            // Уникальность теперь учитывает НО, иначе филиальные копии схлопываются в одну.
            $table->dropUnique('task_reminders_unique');
            $table->unique(
                ['employee_id', 'client_id', 'service_id', 'tax_office_code', 'due_date'],
                'task_reminders_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('task_reminders', function (Blueprint $table) {
            $table->dropUnique('task_reminders_unique');
            $table->unique(['employee_id', 'client_id', 'service_id', 'due_date'], 'task_reminders_unique');
            $table->dropColumn(['tax_office_code', 'branch_label']);
        });
    }
};
