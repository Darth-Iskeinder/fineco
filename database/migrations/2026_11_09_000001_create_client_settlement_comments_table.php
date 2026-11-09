<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расчёты с клиентами: комментарий про деньги (09.11.2026).
 *
 * Один на клиента: «платит наличными в конце месяца», «обещал закрыть долг до 20-го».
 * Отдельно от «Примечаний» карточки клиента: те видят все, у кого есть клиент,
 * а этот только те, кто видит расчёты. Историю не храним (решение Искендера),
 * помним только, кто и когда менял последним.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_settlement_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->text('body');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_settlement_comments');
    }
};
