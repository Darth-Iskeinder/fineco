<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Исполнитель БП на позиции сметы. По умолчанию все задачи клиента идут на главбуха
     * (clients.responsible_employee_id); главбух позже сможет переназначать отдельные БП на бухгалтеров.
     *
     * Бэкфилл = текущий ответственный клиента, чтобы в день выката поведение не изменилось.
     * Генерация задач на этом шаге ещё читает responsible_employee_id — assignee_id пока никто не использует.
     */
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->foreignId('assignee_id')
                ->nullable()
                ->after('service_id')
                ->constrained('employees')
                ->nullOnDelete();
        });

        // Бэкфилл через подзапрос — работает и на MySQL, и на SQLite (без UPDATE ... JOIN).
        DB::table('clients')
            ->whereNotNull('responsible_employee_id')
            ->orderBy('id')
            ->chunkById(200, function ($clients) {
                foreach ($clients as $client) {
                    DB::table('estimate_items')
                        ->whereIn('estimate_id', function ($q) use ($client) {
                            $q->select('id')->from('estimates')->where('client_id', $client->id);
                        })
                        ->update(['assignee_id' => $client->responsible_employee_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignee_id');
        });
    }
};
