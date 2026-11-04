<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расчёты с клиентами: долг или аванс на начало (04.11.2026).
 *
 * У клиентов долг был ещё до Kubik. Вписывают его один раз, одной суммой с датой.
 * Оплаты гасят его первым, раньше всех счетов. Номера счёта у него нет.
 * amount со знаком: больше нуля долг клиента, меньше нуля его аванс.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('as_of');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_opening_balances');
    }
};
