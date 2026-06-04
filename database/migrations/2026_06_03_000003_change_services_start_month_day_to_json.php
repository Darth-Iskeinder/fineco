<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Месяц/день становятся массивами (теги):
     *  - start_month — выбранные месяцы (1–12) для ежеквартально/ежегодно.
     *  - start_day   — для месячно/квартально/ежегодно: [день месяца];
     *                  для еженедельно: дни недели [1=Пн … 7=Вс].
     * Смысл содержимого определяется periodicity.kind.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['start_month', 'start_day']);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->json('start_month')->nullable()->after('due_day');
            $table->json('start_day')->nullable()->after('start_month');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['start_month', 'start_day']);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedTinyInteger('start_month')->nullable()->after('due_day');
            $table->unsignedTinyInteger('start_day')->nullable()->after('start_month');
        });
    }
};
