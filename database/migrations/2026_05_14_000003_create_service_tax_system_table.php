<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Убираем прямой FK (был добавлен ранее), заменяем на pivot
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['tax_system_id']);
            $table->dropColumn('tax_system_id');
        });

        Schema::create('service_tax_system', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_system_id')->constrained('tax_systems')->cascadeOnDelete();
            $table->primary(['service_id', 'tax_system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_tax_system');

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('tax_system_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('tax_systems')
                ->nullOnDelete();
        });
    }
};
