<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «По событию»: БП становится родителем, и после выполнения задачи по нему
     * сама собой рождается задача по другому БП (дочернему, с периодичностью
     * «По запросу»).
     *
     * Раньше «По событию» было записью в справочнике периодичностей и не делало
     * ничего: дат у неё нет, задачи по таким БП не создавались вовсе. Теперь это
     * не периодичность, а надстройка над ней: у БП остаётся своё расписание,
     * а тумблер только добавляет поведение триггера.
     *
     * Оба поля добавляющие: тумблер выключен у всех, дочерний не выбран. Пока
     * его не включат руками, не меняется ничего.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('triggers_on_event')->default(false)->after('deadline_days');
            // Удалили дочерний БП — связь просто гаснет, родитель остаётся жив.
            $table->foreignId('event_child_service_id')->nullable()->after('triggers_on_event')
                ->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_child_service_id');
            $table->dropColumn('triggers_on_event');
        });
    }
};
