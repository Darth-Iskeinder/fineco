<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Месяц и день начала/срока БП — два независимых поля,
     * заполняются при создании бизнес-процесса.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedTinyInteger('start_month')->nullable()->after('due_day');
            $table->unsignedTinyInteger('start_day')->nullable()->after('start_month');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['start_month', 'start_day']);
        });
    }
};
