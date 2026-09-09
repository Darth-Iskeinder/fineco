<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Эталонный номер БП — опора авто-аудита.
 *
 * Правила сверки ссылаются на БП номером, а не названием: название фирма правит
 * под себя, и привязка по нему разъехалась бы на первой же правке. Номер ставит
 * вендор, фирма его не видит в форме и поменять не может.
 *
 * Номер уникален внутри фирмы, а одно и то же число во всех фирмах означает один
 * и тот же БП. Перенумеровывать и переиспользовать номера нельзя: правила
 * ссылаются на них, и подмена тихо переставит сверку на чужой документ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('reference_id')->nullable()->after('check_type');
            // NULL в уникальном индексе не конфликтуют ни в MySQL, ни в PostgreSQL:
            // непомеченных БП может быть сколько угодно.
            $table->unique(['tenant_id', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'reference_id']);
            $table->dropColumn('reference_id');
        });
    }
};
