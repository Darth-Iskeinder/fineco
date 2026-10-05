<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Задачу передали другому исполнителю, а прежний уже начинал её: его запись получает
 * статус «Передана» (BuhTaskLog::STATUS_HANDED) и помнит, кому и когда. Раньше такая
 * запись оставалась «на паузе» и пропадала с экрана: ни прежний, ни новый её не видели.
 *
 * Только новые пустые колонки, старые строки не трогаются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('handed_to_id')->nullable()->after('status');
            $table->timestamp('handed_at')->nullable()->after('handed_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('buh_task_logs', fn (Blueprint $table) => $table->dropColumn(['handed_to_id', 'handed_at']));
    }
};
