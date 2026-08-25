<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * След «эта задача родилась от той». Нужен для двух вещей сразу:
     *
     *  - дубли. По одной задаче-родителю дочерняя создаётся ровно один раз.
     *    Задачу сбросили и закрыли повторно — второй копии не будет;
     *  - одна ступень. Задача с проставленным следом сама триггер не запускает,
     *    поэтому цепочка не идёт дальше и кольцо из двух БП безопасно.
     *
     * Тип хранится строкой с именем класса (BuhTaskLog или BuhAdhocTask):
     * родителем бывает и плановая задача, и разовая.
     *
     * Название родителя лежит рядом СНИМКОМ, как имя и описание самой задачи.
     * Так бейдж «по событию» показывает, откуда задача взялась, не ходя за
     * родителем на каждую строку списка, и переживает его удаление.
     */
    public function up(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->nullableMorphs('trigger_source');
            $table->string('trigger_source_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->dropColumn('trigger_source_name');
            $table->dropMorphs('trigger_source');
        });
    }
};
