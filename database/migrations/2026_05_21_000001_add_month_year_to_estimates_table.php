<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->default(0)->after('client_id');
            $table->unsignedTinyInteger('month')->default(0)->after('year');
        });

        DB::table('estimates')->update([
            'year'  => now()->year,
            'month' => now()->month,
        ]);

        Schema::table('estimates', function (Blueprint $table) {
            // Добавляем составной уникальный индекс ДО удаления старого,
            // чтобы FK на client_id нашёл новый индекс и не заблокировал удаление.
            $table->unique(['client_id', 'year', 'month']);
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->unique(['client_id']);
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'year', 'month']);
            $table->dropColumn(['year', 'month']);
        });
    }
};
