<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Находки автоаудита и переписка по ним.
 *
 * Находка: проблемная строка автоаудита, по которой ждём ответа бухгалтера. Привязана к
 * ключу строки (AutoAuditResult::key), а не к id: строка результата сменяется новой, когда
 * меняется итог, а разговор при этом тот же. Открывает и закрывает находки только прогон.
 *
 * Сообщение помнит, к какой строке результата относилось (result_id). По нему видно,
 * держится ли «Принято»: руководитель принял одну строку, а после замены файла итог стал
 * другим, и принятое к новой строке не относится.
 *
 * Только новые таблицы: старые не трогаем, откат просто их удаляет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_audit_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            // Длиннее 191 ключ не бывает: «check:клиент:проверка:дата..дата».
            $table->string('key', 191);
            // С какого дня строка висит: когда появилась проблемная строка результата.
            $table->timestamp('opened_at');
            // Пусто, пока находка открыта.
            $table->timestamp('closed_at')->nullable();
            // Каким стал итог, когда закрылась: matched, scan и т.п. Пусто: строка ушла.
            $table->string('closed_outcome', 16)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'key']);
        });

        Schema::create('auto_audit_finding_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('finding_id')->constrained('auto_audit_findings')->cascadeOnDelete();
            $table->foreignId('result_id')->nullable()->constrained('auto_audit_results')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // Вендор работает внутри фирмы под учёткой её сотрудника: отличаем его отдельно.
            $table->boolean('by_vendor')->default(false);

            $table->string('kind', 16);   // explained | fixed | accepted | rejected
            $table->text('body')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_audit_finding_messages');
        Schema::dropIfExists('auto_audit_findings');
    }
};
