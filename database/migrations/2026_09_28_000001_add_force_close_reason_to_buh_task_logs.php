<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Причина принудительного закрытия выбором из списка (BuhTaskLog::FORCE_REASONS), рядом
 * со свободным комментарием. Текст читает человек, а автоаудиту нужен признак, который не
 * надо угадывать: «нул», «нулев» и «ежеквартально» в одном поле не разобрать надёжно.
 *
 * Пустая у старых задач, пока их не разметит buhtasks:classify-force-closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->string('force_close_reason', 20)->nullable()->after('force_closed');
        });
    }

    public function down(): void
    {
        Schema::table('buh_task_logs', fn (Blueprint $table) => $table->dropColumn('force_close_reason'));
    }
};
