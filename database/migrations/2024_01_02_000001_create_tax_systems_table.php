<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Сидирование базовых систем налогообложения
        DB::table('tax_systems')->insert([
            ['name' => 'УСН 6%', 'code' => 'usn_6', 'description' => 'Упрощённая система налогообложения (доходы)', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'УСН 15%', 'code' => 'usn_15', 'description' => 'Упрощённая система налогообложения (доходы минус расходы)', 'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'ОСНО', 'code' => 'osno', 'description' => 'Общая система налогообложения', 'is_active' => true, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'ПСН', 'code' => 'psn', 'description' => 'Патентная система налогообложения', 'is_active' => true, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'ЕСХН', 'code' => 'eshn', 'description' => 'Единый сельскохозяйственный налог', 'is_active' => true, 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'НПД', 'code' => 'npd', 'description' => 'Налог на профессиональный доход (самозанятые)', 'is_active' => true, 'sort_order' => 6, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_systems');
    }
};
