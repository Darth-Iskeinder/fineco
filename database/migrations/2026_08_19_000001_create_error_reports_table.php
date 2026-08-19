<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал сбоев: и серверных исключений, и того, что сломалось в браузере.
 *
 * Заведён после случая, когда создание задачи из каталога отвечало пустым телом,
 * а пользователь видел «Unexpected end of JSON input». В логах не было ничего:
 * падение случилось ниже уровня приложения, а ошибку браузера мы не собирали
 * вовсе. Узнали о ней от клиента — то есть последними.
 *
 * Колонка tenant_id есть, но трейта BelongsToTenant у модели нет намеренно.
 * Причин две. Первая: писать сюда нужно всегда, в том числе когда фирма в
 * контексте не задана (крон, консоль, падение до авторизации) — глобальный
 * скоуп в строгом режиме сам бросил бы исключение прямо в обработчике ошибок.
 * Вторая: читает журнал только владелец системы, и ему нужны все фирмы разом.
 *
 * fingerprint — отпечаток «той же самой ошибки» (фирма + вид + текст + место).
 * Одинаковые сбои копятся в одну строку со счётчиком: зациклившаяся вкладка
 * иначе за ночь пишет сюда десятки тысяч строк и топит собой всё остальное.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('kind', 16);                 // server | browser
            $table->string('fingerprint', 40)->unique();

            $table->string('message', 1000);
            $table->string('source', 500)->nullable();  // класс+строка у сервера, файл скрипта у браузера
            $table->string('url', 500)->nullable();     // адрес, на котором сломалось
            $table->unsignedSmallInteger('status')->nullable(); // HTTP-код, если он известен
            $table->text('context')->nullable();        // стек или иные подробности, обрезанные по длине

            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            // Разобранные не удаляем, а прячем: повторится — снова всплывёт наверх.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['resolved_at', 'last_seen_at']); // лента журнала
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
