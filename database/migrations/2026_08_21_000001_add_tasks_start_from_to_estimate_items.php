<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * С какого дня идут задачи по позиции сметы.
     *
     * Месяц, в котором смету завели, — холостой: задачи начинаются с 1 числа
     * следующего. Но граница считалась по дате создания всей сметы, поэтому у
     * действующего клиента новый БП попадал в текущий месяц и сразу выдавал
     * просрочку за уже прошедшие сроки. Теперь своя граница есть у каждой
     * позиции: у добавленной сегодня — 1 число следующего месяца.
     *
     * У всех существующих позиций колонка пуста — значит границы нет и
     * поведение прежнее: то, что бухгалтеры уже ведут, не пропадёт.
     */
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->date('tasks_start_from')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn('tasks_start_from');
        });
    }
};
