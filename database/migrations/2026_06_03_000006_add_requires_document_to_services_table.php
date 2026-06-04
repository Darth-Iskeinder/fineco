<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Правило закрытия задачи по БП:
     *  - true  → для закрытия нужно прикрепить документ;
     *  - false → можно закрыть без документа.
     * Проверяется при закрытии БП.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('requires_document')->default(false)->after('closing_rule');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('requires_document');
        });
    }
};
