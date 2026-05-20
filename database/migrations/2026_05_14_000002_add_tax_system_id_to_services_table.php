<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('tax_system_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('tax_systems')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['tax_system_id']);
            $table->dropColumn('tax_system_id');
        });
    }
};
