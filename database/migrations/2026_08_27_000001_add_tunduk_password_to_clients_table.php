<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отдельный пароль для Тундук ЕСИ.
 *
 * До этого `eds_password` значил сразу две вещи: пароль ЭЦП и пароль Тундука.
 * В карточке они теперь разведены по разным блокам, значит и колонки нужны
 * разные. Существующие значения остаются там, где лежат, и считаются паролем
 * ЭЦП: понять по значению, что именно там записано, нельзя, а угадывать за
 * бухгалтера мы не будем. Новая колонка заводится пустой, заполняют её руками.
 *
 * Миграция только добавляет колонку: ничего не переписывает и не удаляет,
 * откат сводится к удалению пустого поля.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Шифруется в модели, как и остальные пароли.
            $table->text('tunduk_password')->nullable()->after('eds_expires');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('tunduk_password');
        });
    }
};
