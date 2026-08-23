<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Тип обслуживания бизнес-процесса: бухучёт, налоговый учёт или расчёт ЗП.
     * Значения — ключи Service::SERVICE_TYPES.
     *
     * Нужен, чтобы фирме, которая ведёт клиента не целиком (скажем, только
     * налоговый учёт), в смету не подтягивались чужие БП. Само сужение появится
     * отдельным шагом; здесь только поле.
     *
     * Пусто у всех БП каталога, и пусто означает «общий БП, участвует при любом
     * обслуживании» — то есть нынешнее поведение. Фильтр начинает отсекать БП
     * ровно в тот момент, когда ему проставят тип, и ни секундой раньше.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('service_type')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });
    }
};
