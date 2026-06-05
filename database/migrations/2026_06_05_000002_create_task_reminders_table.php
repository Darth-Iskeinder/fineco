<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Сгенерированные напоминания о сроках выполнения БП — выход воркера tasks:generate.
     *
     * Одно напоминание = «сотруднику X выполнить БП (service) для клиента по сроку due_date».
     * Ключуется по СТАБИЛЬНОМУ (employee, client, service, due_date), а НЕ по estimate_item_id —
     * смета пересоздаёт позиции при каждом сохранении (cascade delete), и привязка к ним
     * стирала бы напоминания. service_id/client_id стабильны, поэтому переживают пересохранение.
     *
     * name/periodicity — снапшот для отображения и истории (если БП переименуют/уберут).
     */
    public function up(): void
    {
        Schema::create('task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('periodicity')->nullable();
            $table->date('due_date');
            $table->string('status')->default('pending'); // pending | done
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'client_id', 'service_id', 'due_date'], 'task_reminders_unique');
            $table->index(['employee_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminders');
    }
};
