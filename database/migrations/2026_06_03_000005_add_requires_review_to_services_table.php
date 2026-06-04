<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Тип проверки задачи по БП:
     *  - true  → обязательная проверка: задачу закрывает аудитор после «отправить на проверку»;
     *  - false → самоконтроль: задача закрывается сразу, когда исполнитель кликнул «выполнено».
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('requires_review')->default(false)->after('check_type');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('requires_review');
        });
    }
};
