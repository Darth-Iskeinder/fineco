<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Результаты автоаудита: одна строка на клиента, проверку и период.
 *
 * Первый, самый простой вариант. Истории нет: каждый прогон стирает строки фирмы и
 * пишет заново. Пока результат смотрит глазами только владелец системы, старые строки
 * рядом с новыми лишь путали бы.
 *
 * left_value и right_value названы без привязки к ОСВ и отчёту: сейчас слева всегда
 * ведомость, справа отчёт, но у следующих проверок стороны будут другими.
 *
 * sources хранит, из каких документов взяты числа и что из каждого прочитано. По ним
 * человек открывает файлы и проверяет вывод сам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_audit_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->string('rule', 64);
            // Пусто, когда документ не прочитался и период узнать не из чего.
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            $table->string('outcome', 16);              // matched | mismatch | no_documents
            $table->decimal('left_value', 18, 2)->nullable();
            $table->decimal('right_value', 18, 2)->nullable();
            $table->decimal('difference', 18, 2)->nullable();
            $table->text('reason')->nullable();
            $table->json('sources')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_audit_results');
    }
};
