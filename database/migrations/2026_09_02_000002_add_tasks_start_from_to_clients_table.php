<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Нижняя граница задач клиента: «сроки раньше этой даты не считаем».
 *
 * Ставится, когда клиента возвращают в работу после перерыва. Без неё возврат
 * означал бы обвал просрочки за всё время простоя: генератор задач памяти не
 * имеет, каждый прогон он пересчитывает сроки по смете за полгода назад, а
 * живой список бухзадачника считает их на лету. Снятая верхняя граница
 * (`service_end_date`) открывает им весь перерыв разом.
 *
 * Пустая у всех, кто ни разу не останавливался: у них окно задач прежнее.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->date('tasks_start_from')->nullable()->after('service_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('tasks_start_from');
        });
    }
};
