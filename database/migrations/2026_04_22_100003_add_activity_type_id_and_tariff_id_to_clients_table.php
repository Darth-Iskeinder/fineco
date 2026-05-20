<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Добавляем activity_type_id после activity_type
            $table->foreignId('activity_type_id')
                ->nullable()
                ->after('activity_type')
                ->constrained('activity_types')
                ->nullOnDelete();

            // Добавляем tariff_id после price
            $table->foreignId('tariff_id')
                ->nullable()
                ->after('price')
                ->constrained('tariffs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['activity_type_id']);
            $table->dropColumn('activity_type_id');

            $table->dropForeign(['tariff_id']);
            $table->dropColumn('tariff_id');
        });
    }
};
