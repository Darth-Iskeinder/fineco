<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расчёты с клиентами: оплаты (04.11.2026).
 *
 * Оплата вносится на клиента, а не на счёт. По счетам она раскладывается при показе:
 * сначала гасит самые старые, лишнее остаётся авансом (см. ClientLedger). Раскладку
 * не храним, поэтому правка суммы счёта или удаление ошибочной оплаты сами
 * перераскладывают деньги, и разъехаться нечему.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 12, 2);
            $table->string('method', 8);                 // bank | cash
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_payments');
    }
};
