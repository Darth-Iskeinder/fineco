<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расчёты с клиентами, этап 1 (03.11.2026): счёт клиенту за месяц.
 *
 * client_invoices: один счёт на клиента за месяц. Пока номера нет, это черновик: сумму
 *     вписали, но счёт не выставлен. Номер сквозной по фирме, даётся при выставлении.
 *     Начисление это и есть сам счёт: смету и тарифы не трогаем.
 * client_invoice_extras: дополнительные услуги месяца строками. В следующий месяц
 *     не переходят.
 *
 * Слово billings занято справочником режимов тарификации, поэтому «invoices».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            // Первое число месяца, за который счёт.
            $table->date('period');
            // Пусто, пока счёт не выставлен.
            $table->unsignedInteger('number')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            // Сумма за месяц. Пусто у черновика нового клиента, которому её ещё не вписали.
            $table->decimal('amount', 12, 2)->nullable();
            // Сумма за месяц плюс дополнительные услуги. Хранится, чтобы список не складывал строки.
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'period']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'period']);
        });

        Schema::create('client_invoice_extras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('invoice_id')->constrained('client_invoices')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invoice_extras');
        Schema::dropIfExists('client_invoices');
    }
};
