<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Признак статуса: по нему обслуживание не идёт, значит новых задач нет.
 *
 * Отдельный от `closes_service` флаг, потому что это разные вещи. «Завершен»
 * закрывает обслуживание совсем и меняет формулировки на экране, а «Приостановлен»
 * это пауза, из которой возвращаются. Для задач же разницы нет никакой: пока
 * клиента не обслуживают, сроки по его смете не считаются.
 *
 * Заполняем по имеющимся статусам: завершающий останавливает задачи всегда,
 * «Приостановлен» получает флаг здесь же — до сих пор он не останавливал ничего.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_statuses', function (Blueprint $table) {
            $table->boolean('stops_tasks')->default(false)->after('closes_service');
        });

        DB::table('client_statuses')->where('closes_service', true)->update(['stops_tasks' => true]);
        DB::table('client_statuses')->where('name', 'Приостановлен')->update(['stops_tasks' => true]);
    }

    public function down(): void
    {
        Schema::table('client_statuses', function (Blueprint $table) {
            $table->dropColumn('stops_tasks');
        });
    }
};
