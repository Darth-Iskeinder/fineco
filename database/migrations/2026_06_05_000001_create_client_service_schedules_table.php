<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Индивидуальное расписание БП у конкретного клиента.
     *
     * Паттерн «шаблон + override»: у БП (services) лежит дефолтное расписание,
     * а строка здесь ПОЛНОСТЬЮ перекрывает его для пары (клиент, БП).
     *  - нет строки  → клиент идёт по дефолтному расписанию БП;
     *  - есть строка → у клиента своё расписание (можно менять и периодичность, и даты).
     *
     * Поля повторяют семантику services.{periodicity,start_month,start_day}:
     *  - periodicity — имя из справочника Periodicity (kind выводится из него);
     *  - start_month — месяцы 1–12 (для quarterly/yearly);
     *  - start_day   — день месяца (monthly/quarterly/yearly) либо дни недели 1=Пн…7=Вс (weekly).
     */
    public function up(): void
    {
        Schema::create('client_service_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('periodicity')->nullable();
            $table->json('start_month')->nullable();
            $table->json('start_day')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_service_schedules');
    }
};
