<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обязательный документ у внеплановых задач — как у БП «С документом».
 *
 * requires_document ставит автор при создании (по каталогу или тумблером), снимает он же
 * кнопкой «Документ не нужен». Все прежние задачи остаются без требования.
 *
 * from_author отделяет файл, который автор приложил при создании (материал к задаче), от
 * результата исполнителя: в зачёт закрытия идёт только второй. Таблица документов общая
 * с плановыми задачами, у них колонка просто остаётся false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->boolean('requires_document')->default(false)->after('requires_review');
        });

        Schema::table('buh_task_documents', function (Blueprint $table) {
            $table->boolean('from_author')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->dropColumn('requires_document');
        });

        Schema::table('buh_task_documents', function (Blueprint $table) {
            $table->dropColumn('from_author');
        });
    }
};
