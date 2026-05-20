<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->decimal('price', 12, 2);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Базовые тарифы
        DB::table('tariffs')->insert([
            ['name' => 'Базовый', 'code' => 'basic', 'price' => 5000.00, 'description' => 'Базовый тариф обслуживания', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Стандарт', 'code' => 'standard', 'price' => 10000.00, 'description' => 'Стандартный тариф', 'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Бизнес', 'code' => 'business', 'price' => 20000.00, 'description' => 'Бизнес тариф с расширенным обслуживанием', 'is_active' => true, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Премиум', 'code' => 'premium', 'price' => 35000.00, 'description' => 'Премиум тариф с полным обслуживанием', 'is_active' => true, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Индивидуальный', 'code' => 'custom', 'price' => 0.00, 'description' => 'Индивидуальный тариф по договорённости', 'is_active' => true, 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
