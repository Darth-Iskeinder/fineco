<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал загрузок клиентов из файла.
 *
 * Нужен для двух вопросов, которые задают позже: «откуда у нас этот клиент»
 * и «верните как было». Второй требует построчного снимка прежних значений —
 * без него откат обновления невозможен.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('file_name');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            // Режим, в котором человек подтвердил загрузку: с ним понятно,
            // почему совпавшие по ИНН строки перезаписали клиентов.
            $table->boolean('update_existing')->default(false);

            $table->string('status')->default('applied');
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_import_id')->constrained('client_imports')->cascadeOnDelete();

            // Клиента могли удалить после импорта — запись в журнале остаётся.
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->string('action');

            // Прежние значения только тех полей, которые импорт менял.
            $table->json('before')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_import_rows');
        Schema::dropIfExists('client_imports');
    }
};
